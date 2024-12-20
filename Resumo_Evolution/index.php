<?php
include('addons.class.php');

// Verifica se o usuário está logado
session_name('mka');
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['mka_logado']) && !isset($_SESSION['MKA_Logado'])) exit('Acesso negado... <a href="/admin/login.php">Fazer Login</a>');

// Variáveis do Manifesto
$manifestTitle = isset($Manifest->name) ? htmlspecialchars($Manifest->name) : '';
$manifestVersion = isset($Manifest->version) ? htmlspecialchars($Manifest->version) : '';

//--------------------------------------------------------------------------------------//

// Caminho e permissões para o diretório de configurações
$dir_path = '/opt/mk-auth/dados/Resumo_Evolution';
$file_path = $dir_path . '/config.php';
if (!is_dir($dir_path)) mkdir($dir_path, 0755, true);

// Define a chave de criptografia
$chave_criptografia = 'iJa0hpn259eVP9rdfHxtMVHcevHghV';

// Funções de encriptação e desencriptação de dados
function encriptar($dados, $chave) {
    return openssl_encrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Verifica e cria o arquivo de configuração criptografado, se necessário
if (!file_exists($file_path)) {
    $config_content = '<?php return ' . var_export([
        'protocol' => 'http', // Protocolo padrão
        'ip' => '',
        'user' => '',
        'token' => '',
        'telefone' => '' // Adicionado campo telefone
    ], true) . ';';
    file_put_contents($file_path, $config_content);
    chmod($file_path, 0600);  // Permissões restritas
}

// Lê e desencripta as configurações do arquivo
$configuracoes = include($file_path);
$protocol = $configuracoes['protocol'] ?? 'http'; // Protocolo não criptografado
$ip = isset($configuracoes['ip']) ? desencriptar($configuracoes['ip'], $chave_criptografia) : '';
$user = isset($configuracoes['user']) ? desencriptar($configuracoes['user'], $chave_criptografia) : '';
$token = isset($configuracoes['token']) ? desencriptar($configuracoes['token'], $chave_criptografia) : '';
$telefone = isset($configuracoes['telefone']) ? desencriptar($configuracoes['telefone'], $chave_criptografia) : '';

// Salva as configurações, incluindo telefone, se o formulário foi enviado
if (isset($_POST['salvar_configuracoes'])) {
    $protocol = $_POST['protocol'] ?? 'http';
    $ip = $_POST['ip'] ?? '';
    $user = $_POST['user'] ?? '';
    $token = $_POST['token'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    
    $novas_configuracoes = [
        'protocol' => $protocol, // Salva diretamente
        'ip' => encriptar($ip, $chave_criptografia),
        'user' => encriptar($user, $chave_criptografia),
        'token' => encriptar($token, $chave_criptografia),
        'telefone' => encriptar($telefone, $chave_criptografia) // Salva o telefone criptografado
    ];

    $config_content = '<?php return ' . var_export($novas_configuracoes, true) . ';';
    if (file_put_contents($file_path, $config_content) !== false) {
        chmod($file_path, 0600);  // Define permissão 0600 para o arquivo
        echo "<script>alert('Configurações de Protocolo, Token, IP, Telefone e User salvas com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao salvar as configurações. Verifique as permissões do diretório.');</script>";
    }
}

//----------------------------------------------------------------------//

// Caminho para o arquivo temporário do cron
$cronFilePath = '/tmp/cron_Resumo_Evolution';

// Função para atualizar o cron com hora e minuto específicos
function atualizarCronHora($hora, $minuto) {
    global $cronFilePath;
    $comando = "/usr/bin/php -q /opt/mk-auth/admin/addons/Resumo_Evolution/enviar_resumo.php >/dev/null 2>&1";

    // Obter os agendamentos existentes
    $output = shell_exec("crontab -l");
    $existingCrons = $output ? explode("\n", trim($output)) : [];

    // Formatar o novo agendamento
    $novoCron = "$minuto $hora * * * $comando";

    // Remover qualquer agendamento duplicado para evitar redundância
    $existingCrons = array_filter($existingCrons, function ($line) use ($comando) {
        return strpos($line, $comando) === false;
    });

    // Adicionar o novo agendamento
    $existingCrons[] = $novoCron;

    // Escrever todos os agendamentos de volta no crontab
    $cronContent = implode("\n", $existingCrons) . PHP_EOL;
    file_put_contents($cronFilePath, $cronContent);
    exec("crontab $cronFilePath");
}

// Função para exibir apenas os agendamentos específicos
function obterAgendamentoAtual() {
    $output = shell_exec("crontab -l");
    if ($output) {
        // Filtrar as linhas que contêm o comando específico
        $lines = explode("\n", trim($output));
        $result = [];
        foreach ($lines as $line) {
            if (strpos($line, '/usr/bin/php -q /opt/mk-auth/admin/addons/Resumo_Evolution/enviar_resumo.php') !== false) {
                $result[] = htmlspecialchars($line);
            }
        }
        return $result ? implode("<br>", $result) : "<span class='no-schedule'>Nenhum agendamento configurado</span>";
    }
    return "<span class='no-schedule'>Nenhum agendamento configurado</span>";
}

// Função para excluir apenas o agendamento específico
function excluirAgendamentoEspecifico() {
    global $cronFilePath;
    $output = shell_exec("crontab -l");
    if ($output) {
        // Remover as linhas que contêm o comando específico
        $lines = explode("\n", trim($output));
        $filteredLines = array_filter($lines, function ($line) {
            return strpos($line, '/usr/bin/php -q /opt/mk-auth/admin/addons/Resumo_Evolution/enviar_resumo.php') === false;
        });

        // Atualizar o crontab apenas se houver mudanças
        if (count($filteredLines) !== count($lines)) {
            $cronContent = implode("\n", $filteredLines) . PHP_EOL;
            file_put_contents($cronFilePath, $cronContent);
            exec("crontab $cronFilePath");
            echo '<script>alert("Agendamento excluído com sucesso.");</script>';
        } else {
            echo '<script>alert("Nenhum agendamento específico encontrado para excluir.");</script>';
        }
    } else {
        echo '<script>alert("Nenhum agendamento configurado no sistema.");</script>';
    }
}

// Verifica se o formulário de hora foi enviado
if (isset($_POST['hora_agendamento'])) {
    $horaCompleta = $_POST['hora_agendamento']; // Hora no formato HH:MM
    list($hora, $minuto) = explode(':', $horaCompleta);

    // Atualiza o cron para a hora e minuto especificados
    atualizarCronHora((int)$hora, (int)$minuto);

    echo "<script>alert('Agendamento atualizado para as $horaCompleta!');</script>";
}

// Verifica se o formulário de exclusão foi enviado
if (isset($_POST['delete_schedule'])) {
    excluirAgendamentoEspecifico();
}
?>

<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="iso-8859-1">
<title>MK-AUTH :: <?php echo $manifestTitle; ?></title>

<link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

<script src="../../scripts/jquery.js"></script>
<script src="../../scripts/mk-auth.js"></script>

<style>
    .container {
        max-width: 1300px; /* Aumenta a largura máxima do contêiner */
        width: 100%; /* Define a largura para 90% da tela, adaptando-se a telas menores */
        margin: 20px auto; /* Centraliza o contêiner */
        background: #fff;
        padding: 30px; /* Ajusta o padding para um espaçamento interno confortável */
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        border-radius: 8px; /* Deixa os cantos ligeiramente arredondados */
    }
    h2 {
        text-align: center;
        color: #333;
    }
    form {
        display: flex;
        justify-content: center;
        margin-bottom: 20px;
    }
    input[type="date"], button {
        padding: 10px;
        font-size: 16px;
        margin: 5px;
    }
    button {
        background-color: #007BFF;
        color: #fff;
        border: none;
        cursor: pointer;
        border-radius: 4px;
    }
    button:hover {
        background-color: #0056b3;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    table th, table td {
        border: 1px solid #ddd;
        padding: 10px;
        text-align: center;
    }
    table th {
        background-color: #007BFF;
        color: white;
        font-weight: bold;
    }
    table tr:nth-child(even) {
        background-color: #f2f2f2;
    }
    .total {
        font-weight: bold;
        text-align: right;
        margin-top: 10px;
        color: #333;
        font-size: 1.2em;
    }
    .whatsapp-form {
        text-align: center;
        margin-top: 20px;
    }
    .whatsapp-form button {
        background-color: #25d366;
        color: #fff;
    }

    h2 {
        text-align: center;
        color: #333;
    }
    form label {
        font-weight: bold;
        margin-top: 10px;
        display: block;
    }
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 10px;
        margin: 10px 0;
        border: 1px solid #ccc;
        border-radius: 5px;
    }
    button {
        background-color: #007BFF;
        color: white;
        padding: 10px 15px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.3s;
    }
    button:hover {
        background-color: #0056b3;
    }
</style>

</head>
<body>

<?php include('../../topo.php'); ?>

<nav class="breadcrumb has-bullet-separator is-centered" aria-label="breadcrumbs">
    <ul>
        <li><a href="#"> ADDON</a></li>
        <a href="#" aria-current="page"> <?= $manifestTitle . " - V " . $manifestVersion; ?> </a>
    </ul>
</nav>

<div class="container" style="padding: 1px; max-width: 1300px; margin: 1px auto; background: #f9f9f9; border-radius: 1px; box-shadow: 0 0 1px rgba(0, 0, 0, 0.1);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1px;">
        <h2>Configurações de Token, IP e Telefone</h2>
        <div style="display: flex; gap: 10px;">
            <button id="mostrarHorario" class="toggle-button" onclick="toggleSection('agendamentoForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_agen.png" alt="Mostrar Horário" style="width: 30px; height: 30px;">
            </button>
            <button id="mostrarConfiguracoes" onclick="toggleSection('configForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_config.png" alt="Mostrar Configurações" style="width: 30px; height: 30px;">
            </button>
        </div>
    </div>

    <!-- Formulário de Configurações (oculto inicialmente) -->
    <div id="configForm" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; display: none;">
        
        <!-- Tutorial Estilizado -->
        <div style="background-color: #f0f8ff; padding: 20px; border-left: 6px solid #5a9bd4; margin-bottom: 20px; border-radius: 5px;">
            <h3 style="display: flex; align-items: center; font-weight: bold; color: #31708f;">
                <img src="icon_config.png" alt="Info" style="width: 24px; height: 24px; margin-right: 8px;"> Tutorial de Configuração
            </h3>
            <p style="margin-top: 10px; color: #555;">
                Utilize as informações abaixo como exemplo para configurar o envio de mensagens com a API.
            </p>
            <ul style="list-style: none; padding: 0; margin-top: 15px;">
			    <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">Protocolo:</strong> <span style="color: #555;">HTTP / HTTPS</span> <em style="color: #888;">(Exemplo)</em>
                </li>
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">URL do Servidor:</strong> <span style="color: #555;">192.168.3.250:8000</span> <em style="color: #888;">(Exemplo)</em>
                </li>
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">Nome da Instancia:</strong> <span style="color: #555;">mk-whatsapp</span> <em style="color: #888;">(Exemplo)</em>
                </li>
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">Token da Instancia:</strong> <span style="color: #555;">7G8iAhdoYzZnpinPzTi7</span> <em style="color: #888;">(Exemplo)</em>
                </li>
            </ul>
            <p style="margin-top: 15px; color: #555;">
                Insira os dados fornecidos pela API para configurar corretamente o envio de mensagens.
            </p>
        </div>

        <!-- Formulário -->
        <form method="post" style="display: flex; flex-direction: column; ">
            <label for="protocol" style="font-weight: bold;">Protocolo:</label>
            <select id="protocol" name="protocol" style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                <option value="http" <?php echo ($protocol === 'http' ? 'selected' : ''); ?>>HTTP</option>
                <option value="https" <?php echo ($protocol === 'https' ? 'selected' : ''); ?>>HTTPS</option>
            </select>

            <label for="ip" style="font-weight: bold;">URL do Servidor:</label>
            <input type="text" id="ip" name="ip" value="<?php echo htmlspecialchars($ip); ?>" 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">

            <label for="user" style="font-weight: bold;">Nome da Instância:</label>
            <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($user); ?>" 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">

            <label for="token" style="font-weight: bold;">Token da Instância:</label>
            <div style="position: relative;">
                <input type="password" id="token" name="token" value="<?php echo htmlspecialchars($token); ?>" 
                       style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                <label style="display: flex; align-items: center; margin-top: 5px;">
                    <input type="checkbox" onclick="togglePasswordVisibility()" style="margin-right: 5px;"> Mostrar Token
                </label>
            </div>

            <label for="telefone" style="font-weight: bold;">Número de Telefone:</label>
            <input type="text" id="telefone" name="telefone" value="<?php echo htmlspecialchars($telefone); ?>" 
                   placeholder="(XX) XXXXX-XXXX" 
                   pattern="\(\d{2}\) \d{4,5}-\d{4}" 
                   title="Formato esperado: (XX) XXXXX-XXXX" 
                   style="padding: 10px; border: 1px solid #ccc; border-radius: 5px;">

            <button type="submit" name="salvar_configuracoes" 
                    style="padding: 12px; background-color: #007BFF; color: #fff; border: none; border-radius: 5px; cursor: pointer;">
                Salvar Configurações
            </button>
        </form>
    </div>
</div>

 
 </div>
<!-- Formulário de Agendamento -->
<div id="agendamentoForm" class="config-section" style="display: none; max-width: 1000px; margin: 20px auto; padding: 25px; border-radius: 15px; background: #ffffff; box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);">
    <h3 style="text-align: center; color: #4CAF50; font-size: 1.5em; font-weight: bold; margin-bottom: 20px;">Agendamento</h3>

    <form id="scheduleForm" method="post" style="display: flex; flex-direction: column; gap: 15px;">
        <label for="hora_agendamento" style="font-size: 1.1em; color: #333; font-weight: 600;">Hora (HH:MM):</label>
        <input type="time" id="hora_agendamento" name="hora_agendamento" required style="padding: 12px; font-size: 1.1em; border: 1px solid #ddd; border-radius: 8px; background-color: #f8f8f8;">

<div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px;">
    <!-- Botão Salvar -->
    <form id="scheduleForm" method="post" style="margin: 0;">
        <button type="submit" name="salvar_agendamento" style="display: inline-flex; align-items: center; justify-content: center; width: 200px; padding: 14px 0; font-size: 1em; font-weight: bold; color: #fff; background: linear-gradient(90deg, #28a745, #20c997); border: none; border-radius: 30px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 10px 20px rgba(0, 0, 0, 0.2)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 8px 15px rgba(0, 0, 0, 0.1)';">
            <i class="fa fa-save" style="font-size: 1.2em;"></i> Salvar
        </button>
    </form>

    <!-- Botão Excluir -->
    <form method="post" style="margin: 0;">
        <input type="hidden" name="delete_schedule" value="1">
        <button type="submit" style="display: inline-flex; align-items: center; justify-content: center; width: 200px; padding: 14px 0; font-size: 1em; font-weight: bold; color: #fff; background: linear-gradient(90deg, #dc3545, #e4606d); border: none; border-radius: 30px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);" onclick="return confirm('Tem certeza que deseja excluir o agendamento específico?');" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 10px 20px rgba(0, 0, 0, 0.2)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 8px 15px rgba(0, 0, 0, 0.1)';">
            <i class="fa fa-trash" style="font-size: 1.2em;"></i> Excluir
        </button>
    </form>
</div>

    <div class="cron-display" style="margin-top: 20px; padding: 15px; text-align: center; border-radius: 8px; background-color: #f7f9fb; border: 1px solid #ddd;">
        <strong style="color: #4CAF50; font-size: 1.1em;">Agendamento Atual:</strong><br>
        <?php echo obterAgendamentoAtual(); ?>
    </div>
</div>

<script>
    function toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }

    function togglePasswordVisibility() {
        const tokenInput = document.getElementById('token');
        tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
    }
</script>
<script>
    // Função para aplicar a máscara ao campo de telefone
    document.getElementById('telefone').addEventListener('input', function (e) {
        var value = e.target.value.replace(/\D/g, ''); // Remove todos os caracteres não numéricos
        var formattedValue = '';

        if (value.length > 0) {
            formattedValue += '(' + value.substring(0, 2); // Adiciona o código de área
        }
        if (value.length >= 3) {
            formattedValue += ') ' + value.substring(2, 7); // Adiciona o prefixo
        }
        if (value.length >= 8) {
            formattedValue += '-' + value.substring(7, 11); // Adiciona o sufixo
        }
        e.target.value = formattedValue; // Atualiza o valor do campo com a máscara
    });
</script>
<script>
    function toggleSection(id) {
        var section = document.getElementById(id);
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }

    function togglePasswordVisibility() {
        var tokenField = document.getElementById('token');
        tokenField.type = tokenField.type === 'password' ? 'text' : 'password';
    }
</script>

<div class="container">
    <h2>Consultar Pagamentos</h2>

    <!-- Formulário para entrada da data -->
    <form name="pagamentos" method="post">
        <input type="date" name="datapag" id="datapag" value="<?php echo isset($_POST['datapag']) ? $_POST['datapag'] : date('Y-m-d'); ?>" required>
        <button type="submit">Consultar</button>
    </form>

    <?php
    // Define a data para consulta (data enviada ou data atual)
    $datapag = isset($_POST["datapag"]) ? $_POST["datapag"] : date('Y-m-d');

    // Conexão com o banco de dados do Mk-Auth
    $host = "localhost";
    $usuario = "root";
    $senha = "vertrigo";
    $db = "mkradius";
    $mysqli = new mysqli($host, $usuario, $senha, $db);

    if ($mysqli->connect_errno) {
        echo "<p class='error'>Falha na conexão: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "</p>";
    } else {
        $con = mysqli_connect($host, $usuario, $senha);
        mysqli_select_db($con, $db);

        // Consulta SQL para obter os pagamentos na data especificada
        $query = "SELECT datavenc, valorpag, coletor, formapag, login FROM sis_lanc WHERE datapag = '$datapag'";
        $result = mysqli_query($con, $query);

        if (mysqli_num_rows($result) > 0) {
            $detalhes = "";
            echo "<h2>Resumo dos pagamentos do dia " . date('d/m/Y', strtotime($datapag)) . "</h2>";
            $total = 0;

// Exibe a tabela de resultados
echo "<div style='overflow-x: auto; margin-top: 20px;'>";
echo "<table style='width: 100%; border-collapse: collapse; font-family: Arial, sans-serif;'>";
echo "<thead style='background-color: #007bff; color: white;'>";
echo "<tr>
        <th style='padding: 12px; border: 1px solid #ddd; text-align: left;'>Login</th>
        <th style='padding: 12px; border: 1px solid #ddd; text-align: left;'>Valor</th> <!-- Mudado para 'left' -->
        <th style='padding: 12px; border: 1px solid #ddd; text-align: center;'>Forma de Pagamento</th>
        <th style='padding: 12px; border: 1px solid #ddd; text-align: center;'>Vencimento</th>
        <th style='padding: 12px; border: 1px solid #ddd; text-align: left;'>Coletor</th>
      </tr>";
echo "</thead>";
echo "<tbody style='background-color: #f9f9f9;'>";

while ($row = mysqli_fetch_assoc($result)) {
echo "<tr style='border-bottom: 1px solid #ddd; background-color: #f9f9f9;'>"; // Fundo alternado para linhas
echo "<td style='padding: 4px; border: 1px solid #ddd; text-align: left; color: blue; font-weight: bold;'>{$row['login']}</td>";
echo "<td style='padding: 4px; border: 1px solid #ddd; text-align: left; color: green; font-weight: bold;'>R$ " . number_format($row['valorpag'], 2, ',', '.') . "</td>"; // Valor em verde com bold
echo "<td style='padding: 4px; border: 1px solid #ddd; text-align: center; color: darkorange; font-weight: bold;'>{$row['formapag']}</td>"; // Forma de pagamento em laranja com bold
echo "<td style='padding: 4px; border: 1px solid #ddd; text-align: center; color: red; font-weight: bold;'>" . date('d/m/Y', strtotime($row['datavenc'])) . "</td>"; // Data em vermelho com bold
echo "<td style='padding: 4px; border: 1px solid #ddd; text-align: left; color: purple; font-weight: bold;'>{$row['coletor']}</td>"; // Coletor em roxo com bold
echo "</tr>";


    $detalhes .= "{$row['login']} - R$ " . number_format($row['valorpag'], 2, ',', '.') .
                 " - {$row['formapag']} - " . date('d/m/Y', strtotime($row['datavenc'])) .
                 " - {$row['coletor']}\n";
    $total += $row['valorpag'];
}

echo "</tbody>";
echo "<tfoot style='background-color: #e9ecef;'>";
echo "<tr>";
echo "<td colspan='4' style='padding: 12px; text-align: right; font-weight: bold;'>Total</td>";
echo "<td style='padding: 12px; text-align: left; font-weight: bold; color: green;'>R$ " . number_format($total, 2, ',', '.') . "</td>"; // Total também alinhado à esquerda, em negrito e com cor verde
echo "</tr>";
echo "</tfoot>";
echo "</table>";
echo "</div>";

        } else {
        echo "<p style='text-align: center; font-weight: bold; color: #555;'>Nenhum pagamento encontrado para a data " . date('d/m/Y', strtotime($datapag)) . ".</p>";
        }
    }
    ?>
</div>
<div class="container">
    <form id="envioForm" method="post" style="display: flex; justify-content: center; gap: 15px; margin-top: 20px;">
        <!-- Campos ocultos para envio -->
        <input type="hidden" name="resumo" value="Resumo dos pagamentos do dia <?php echo date('d/m/Y', strtotime($datapag)); ?>">
        <input type="hidden" name="detalhes" value="<?php echo htmlspecialchars($detalhes); ?>">

        <!-- Botão Enviar via WhatsApp -->
        <button type="submit" formaction="enviar_whatsapp.php" class="btn-enviar-zap" style="padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Enviar via WhatsApp
        </button>
        
        <!-- Botão Limpar Log -->
        <button type="submit" formaction="limpar_log.php" onclick="return confirm('Tem certeza que deseja limpar o log?');" class="btn-limpar-log" style="padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Limpar Log
        </button>
    </form>
</div>

<div class="container" style="background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px; max-height: 300px; overflow-y: scroll; color: blue;">
    <h2>Logs de Pagamentos</h2>
    <pre style="white-space: pre-wrap;">
<?php
    $logFile = '/opt/mk-auth/dados/Resumo_Evolution/log_pagamentos.txt';

    if (file_exists($logFile)) {
        // Lê o conteúdo do arquivo e o divide em linhas
        $logContent = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Inverte a ordem das linhas para que as mais recentes fiquem no topo
        $logContent = array_reverse($logContent);

        // Formata cada linha do log
        foreach ($logContent as &$line) {
            if (strpos($line, 'Mensagem enviada com sucesso') !== false) {
                // Mensagens enviadas com sucesso - cor verde e bold
                $line = "<span style='color: green; font-weight: bold;'>" . htmlspecialchars($line) . "</span>";

                // Formatação para data, cliente e telefone
                $line = preg_replace(
                    "/\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})\] Mensagem enviada com sucesso para (.+?) \((\d{2})(\d{2})(\d{5})(\d{4})\)/",
                    "[<span style='color: darkcyan; font-weight: bold;'>$1</span>] Mensagem enviada com sucesso para <span style='color: blue; font-weight: bold;'>$2</span> (+$3 $4 $5-$6)",
                    $line
                );
            } elseif (strpos($line, 'Erro ao enviar mensagem') !== false) {
                // Mensagens de erro - cor vermelha e bold
                $line = "<span style='color: red; font-weight: bold;'>" . htmlspecialchars($line) . "</span>";
            } elseif (strpos($line, 'Registros com mais') !== false) {
                // Formatação para mensagens "Registros com mais"
                $line = preg_replace_callback(
                    "/\[(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\]/",
                    function ($matches) {
                        return "[<span style='color: darkcyan; font-weight: bold;'>" .
                            $matches[3] . "/" . $matches[2] . "/" . $matches[1] . " " .
                            $matches[4] . ":" . $matches[5] . ":" . $matches[6] . "</span>]";
                    },
                    htmlspecialchars($line)
                );
                $line = "<strong>" . $line . "</strong>";
            } else {
                // Outras linhas - estilo bold
                $line = "<strong>" . htmlspecialchars($line) . "</strong>";
            }
        }

        // Exibe o conteúdo formatado
        echo implode("\n", $logContent);
    } else {
        echo "<strong>Arquivo de log não encontrado.</strong>";
    }
?>
    </pre>
</div>


<?php include('../../baixo.php'); ?>

<script src="../../menu.js.hhvm"></script>

</body>
</html>
