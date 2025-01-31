<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
session_start(); // Inicia a sessão

// Define o fuso horário para garantir horários corretos nos logs
date_default_timezone_set('America/Sao_Paulo');

// Função para escrever no log
function writeLog($message) {
    $logFile = __DIR__ . '/requisicoes.log';
    $currentDateTime = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'];
    $logEntry = "[$currentDateTime] IP: $ip - $message\n";

    // Escreve a entrada de log no arquivo
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Função para verificar e limitar consultas
function checkRateLimit($limit = 10, $duration = 60, $banDuration = 600, $banThreshold = 50) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $currentTime = time();

    // Verifica se o IP está banido e se o banimento ainda é válido
    if (isset($_SESSION['rate_limit'][$ip]['banned_until']) && $currentTime < $_SESSION['rate_limit'][$ip]['banned_until']) {
        writeLog("IP banido por excesso de consultas.");
        return false;
    }

    // Inicia ou atualiza os contadores de consulta
    if (!isset($_SESSION['rate_limit'][$ip])) {
        $_SESSION['rate_limit'][$ip] = [
            'count' => 1,
            'start_time' => $currentTime
        ];
    } else {
        $rateData = $_SESSION['rate_limit'][$ip];
        $elapsedTime = $currentTime - $rateData['start_time'];

        if ($elapsedTime > $duration) {
            // Período de tempo expirado, resetar contador
            $_SESSION['rate_limit'][$ip]['count'] = 1;
            $_SESSION['rate_limit'][$ip]['start_time'] = $currentTime;
        } else {
            // Incrementa a contagem de consultas
            $_SESSION['rate_limit'][$ip]['count']++;

            // Verifica se o IP excedeu o limite de consultas
            if ($_SESSION['rate_limit'][$ip]['count'] > $banThreshold) {
                // Banir o IP por 10 minutos
                $_SESSION['rate_limit'][$ip]['banned_until'] = $currentTime + $banDuration;
                writeLog("Limite de consultas excedido. (>50) Banimento por 10 minutos.");
                return false;
            } elseif ($_SESSION['rate_limit'][$ip]['count'] > $limit) {
                // Limite normal de consultas atingido
                writeLog("Limite de consultas excedido.");
                return false;
            }
        }
    }
    return true;
}

// Verifica se o limite de consultas foi atingido
if (!checkRateLimit()) {
    echo json_encode(["status" => "Erro", "message" => "Limite de consultas excedido. Tente novamente mais tarde."]);
    exit();
}

// Inclui o arquivo de conexão ao banco de dados SQL Server
require_once __DIR__ . '/../config/db.php';

// Recebe o CPF da URL
$cpf = isset($_GET['cpf']) ? preg_replace('/\D/', '', $_GET['cpf']) : '';

// Recebe o CRF da URL
$crf = isset($_GET['crf']) ? preg_replace('/\D/', '', $_GET['crf']) : '';
// $crf = isset($_GET['crf']) ? $_GET['crf'] : '';

$query = NULL;
$dataInput = NULL;

// Valida se CPF atende os requisitos
if (!empty($cpf)) {
    if (strlen($cpf) !== 11) {
        writeLog("CPF Inválido: $cpf");
        $response = ["status" => "CPF Inválido."];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Prepara a consulta SQL para verificar se o CPF existe e obter os dados
    $query = "SELECT CDCRF FROM pfvigentes WHERE NRCPF = ?"; // Consulta por CPF
    $dataInput = $cpf;

// * Na ausência do CPF *
// Valida se CRF atende os requisitos
} else if (!empty($crf)) {
    if (strlen($crf) !== 7) {
        writeLog("CRF Inválido: $crf");
        $response = ["status" => "CRF Inválido."];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Caso esteja CRF esteja "ok" gera a consulta
    $query = "SELECT CDCRF FROM appf WHERE SUBSTRING(CDCRF, 1, 7) = ?"; 
    // $query = "SELECT CDCRF FROM pfvigentes WHERE CDCRF = ?";
    $dataInput = $crf;

} else {
    $response = ["status" => "Erro", "message" => "CPF não informado."];
    writeLog("CPF não informado.");
}

if (!empty($query)){
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        writeLog("Erro ao preparar a consulta SQL.");
        die(json_encode(["status" => "Erro", "message" => "Erro ao preparar a consulta."], JSON_UNESCAPED_UNICODE));
    }
    
    // Executa a consulta
    $stmt->execute([$dataInput]);
    
    // Obtém o resultado como um array associativo
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result !== false) {
        unset($result['Ts']);
        $response = ["status" => "Ativo"];
        writeLog("Consulta realizada com sucesso [Ativo].");
    } else {
        $response = ["status" => "Não localizado"];
        writeLog("Consulta realizada com sucesso [Não Localizado].");
    }
}

// Fechando a conexão
$conn = null;

// Retornando a resposta em formato JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>