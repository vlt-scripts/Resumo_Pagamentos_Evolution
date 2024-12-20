<?php
// Caminho do arquivo de log
$logFile = '/opt/mk-auth/dados/Resumo_Evolution/log_pagamentos.txt';

// Função para escrever no arquivo de log
function escreverLog($mensagem) {
    global $logFile;
    $dataHora = date('d/m/Y H:i:s');
    $logMensagem = "[$dataHora] $mensagem" . PHP_EOL;
    file_put_contents($logFile, $logMensagem, FILE_APPEND);
}

// Função para formatar valores monetários
function formatarValor($valor) {
    return "R$ " . number_format($valor, 2, ',', '.');
}

// Função para formatar o número de celular
function formatarNumero($numero) {
    $numero = preg_replace('/\D/', '', $numero); // Remove tudo que não for dígito
    if (strlen($numero) == 10) {
        $numero = '55' . substr($numero, 0, 2) . '9' . substr($numero, 2); // Adiciona "9" para números antigos
    } elseif (strlen($numero) == 11) {
        $numero = '55' . $numero; // Adiciona o código do Brasil (55)
    }
    return $numero;
}

// Função para consultar os pagamentos no banco de dados
function consultarPagamentos($data) {
    $host = "localhost";
    $usuario = "root";
    $senha = "vertrigo";
    $db = "mkradius";

    $mysqli = new mysqli($host, $usuario, $senha, $db);

    if ($mysqli->connect_errno) {
        escreverLog("Erro na conexão com o banco: ({$mysqli->connect_errno}) {$mysqli->connect_error}");
        return [];
    }

    $query = "SELECT datavenc, valorpag, coletor, formapag, login FROM sis_lanc WHERE datapag = ?";
    $stmt = $mysqli->prepare($query);

    if (!$stmt) {
        escreverLog("Erro ao preparar a consulta SQL: {$mysqli->error}");
        return [];
    }

    $stmt->bind_param('s', $data);
    $stmt->execute();
    $result = $stmt->get_result();

    $pagamentos = [];
    while ($row = $result->fetch_assoc()) {
        $pagamentos[] = $row;
    }

    $stmt->close();
    $mysqli->close();

    return $pagamentos;
}

// Função para enviar o resumo pelo WhatsApp
function enviarResumo() {
    $dataAtual = date('Y-m-d'); // Data no formato 'YYYY-MM-DD'
    $resumo = "Resumo dos pagamentos do dia " . date('d/m/Y');
    $detalhes = "";

    $pagamentos = consultarPagamentos($dataAtual);

    if (empty($pagamentos)) {
        escreverLog("Nenhum pagamento encontrado para a data $dataAtual.");
        return;
    }

    $total = 0;

    // Defina os campos desejados
    $camposSelecionados = [
       'login',         // Login do cliente
       'valorpag',      // Valor do pagamento
    // 'formapag',      // Forma de pagamento
    // 'datavenc',      // Data de vencimento
    // 'coletor'        // Coletor
    ];

    // Itera sobre os pagamentos
    foreach ($pagamentos as $pagamento) { 
       $detalhesLinha = ""; // Armazena a linha com os detalhes do pagamento

        foreach ($camposSelecionados as $campo) {
            if ($campo === 'datavenc') {
                $detalhesLinha .= date('d/m/Y', strtotime($pagamento[$campo])) . " - "; // Formata a data
            } elseif ($campo === 'valorpag') {
                $detalhesLinha .= formatarValor($pagamento[$campo]) . " - "; // Formata o valor
            } else {
                $detalhesLinha .= $pagamento[$campo] . " - "; // Adiciona outros campos
            }
        }

        // Remove o último " - " da linha
        $detalhes .= rtrim($detalhesLinha, " - ") . "\n";

        // Soma o valor para o total
        $total += $pagamento['valorpag'];
    }

    $mensagem = "*$resumo*\n\n$detalhes\n\n*TOTAL: " . formatarValor($total) . "*";

    // Configurações de envio
    $chave_criptografia = 'iJa0hpn259eVP9rdfHxtMVHcevHghV';
    $configFile = '/opt/mk-auth/dados/Resumo_Evolution/config.php';

    if (file_exists($configFile)) {
        $config = include($configFile);
        $ip = openssl_decrypt($config['ip'], 'aes-256-cbc', $chave_criptografia, 0, str_repeat('0', 16));
        $user = openssl_decrypt($config['user'], 'aes-256-cbc', $chave_criptografia, 0, str_repeat('0', 16));
        $token = openssl_decrypt($config['token'], 'aes-256-cbc', $chave_criptografia, 0, str_repeat('0', 16));
        $telefone = openssl_decrypt($config['telefone'], 'aes-256-cbc', $chave_criptografia, 0, str_repeat('0', 16));

        if (!$ip || !$user || !$token || !$telefone) {
            escreverLog("Erro: Configuração de IP, usuário, token ou telefone inválida.");
            return;
        }

        $telefone = formatarNumero($telefone); // Formata o telefone
        $apiUrl = "$ip/message/sendText/$user";

        // Tentativa com API v1
        $dataV1 = [
            'number' => $telefone,
            'textMessage' => [
                'text' => $mensagem
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataV1));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . $token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 && $httpCode !== 201) {
            // Tentativa com API v2
            $dataV2 = [
                'number' => $telefone,
                'text' => $mensagem
            ];

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataV2));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        if (curl_errno($ch)) {
            escreverLog("Erro ao chamar a API: " . curl_error($ch));
        } elseif ($httpCode === 200 || $httpCode === 201) {
            escreverLog("Resumo enviado com sucesso!");
        } else {
            escreverLog("Erro ao enviar resumo. Código HTTP: $httpCode, Resposta: $response");
        }

        curl_close($ch);
    } else {
        escreverLog("Erro: Arquivo de configuração não encontrado.");
    }
}

// Executa o envio
enviarResumo();