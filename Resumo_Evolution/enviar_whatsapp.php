<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Dados da mensagem
    $resumo = $_POST["resumo"];
    $detalhes = $_POST["detalhes"];

    // Caminho do arquivo de log
    $logFile = '/opt/mk-auth/dados/Resumo_Evolution/log_pagamentos.txt';

    // Função para escrever no arquivo de log
    function escreverLog($mensagem) {
        global $logFile;
        $dataHora = date('d/m/Y H:i:s');
        $logMensagem = "[$dataHora] $mensagem" . PHP_EOL;
        file_put_contents($logFile, $logMensagem, FILE_APPEND);
    }

    // Função para formatar o número de celular
    function formatarNumero($numero) {
        $numero = preg_replace('/\D/', '', $numero);
        if (strlen($numero) == 10) {
            $numero = '55' . substr($numero, 0, 2) . '9' . substr($numero, 2);
        } elseif (strlen($numero) == 11) {
            $numero = '55' . $numero;
        }
        return $numero;
    }

    // Simplificar os detalhes para conter apenas Login e Valor
    $linhas = explode("\n", $detalhes);
    $detalhesSimplificados = [];
    $total = 0;

    foreach ($linhas as $linha) {
        $info = explode(" - ", $linha);
        if (count($info) > 1) {
            $login = $info[0];
            $valor = str_replace("R$ ", "", $info[1]);
            $valorFormatado = number_format(floatval(str_replace(',', '.', $valor)), 2, ',', '.');
            $detalhesSimplificados[] = "$login - R$ $valorFormatado";
            $total += floatval(str_replace(',', '.', $valor));
        }
    }

    // Gerar a mensagem simplificada
    $mensagem = "*$resumo*\n\n" . implode("\n", $detalhesSimplificados) . "\n\n*TOTAL R$ " . number_format($total, 2, ',', '.') . "*";

    // Define a chave de criptografia (deve ser a mesma usada no arquivo de configuração)
    $chave_criptografia = 'iJa0hpn259eVP9rdfHxtMVHcevHghV';

    // Função para desencriptar os dados
    function desencriptar($dados, $chave) {
        return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
    }

    // Carrega e desencripta configurações de token, IP e telefone
    $configFile = '/opt/mk-auth/dados/Resumo_Evolution/config.php';
    if (file_exists($configFile)) {
        $config = include($configFile);
        $protocol = isset($config['protocol']) ? $config['protocol'] : 'http';
        $ip = desencriptar($config['ip'], $chave_criptografia);
        $user = desencriptar($config['user'], $chave_criptografia);
        $token = desencriptar($config['token'], $chave_criptografia);
        $telefone = desencriptar($config['telefone'], $chave_criptografia);

        if (!$token || !$ip || !$telefone || !$user) {
            escreverLog("Erro: Falha ao desencriptar os dados de configuração.");
            die("Erro: Falha ao desencriptar os dados de configuração.");
        }

        // Formata o número do telefone
        $telefone = formatarNumero($telefone);

        // URL base da API Evolution
        $apiBaseURL = "$protocol://$ip/message/sendText/$user";
    } else {
        escreverLog("Erro: Arquivo de configuração não encontrado.");
        die("Erro: Arquivo de configuração não encontrado.");
    }

    // Função para enviar mensagem via Evolution API
    function enviarMensagemEvolutionAPI($celular, $mensagem) {
        global $apiBaseURL, $token;

        // Tenta enviar usando o formato da API v1
        $postDataV1 = json_encode([
            'number' => $celular,
            'textMessage' => ['text' => $mensagem]
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiBaseURL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataV1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . $token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 201 || $httpCode === 200) {
            curl_close($ch);
            return true;
        }

        // Se v1 falhou, tenta o formato da API v2
        $postDataV2 = json_encode([
            'number' => $celular,
            'text' => $mensagem
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataV2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201 || $httpCode === 200) {
            return true;
        }

        // Registra erro se ambas as tentativas falharem
        escreverLog("Erro ao enviar mensagem para $celular com ambas as versões da API. Código HTTP final: $httpCode");
        return false;
    }

    // Envia a mensagem
    if (enviarMensagemEvolutionAPI($telefone, $mensagem)) {
        escreverLog("Mensagem enviada com sucesso: *$resumo*");
        echo 'Mensagem enviada com sucesso!';
    } else {
        escreverLog("Erro ao enviar mensagem.");
        echo "Erro ao enviar mensagem.";
    }

    // Redireciona de volta para a página index.php
    header("Location: index.php");
    exit();
}
?>
