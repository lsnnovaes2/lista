<?php
/**
 * Cisco Equipment Scanner - Configuração
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'cisco_scanner');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Cisco Support API - Registre em https://apiconsole.cisco.com
define('CISCO_API_CLIENT_ID', '');       // Preencha com seu Client ID
define('CISCO_API_CLIENT_SECRET', '');   // Preencha com seu Client Secret

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// CORS (ajuste em produção)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Erros sempre em JSON (HTML quebra o resp.json() do frontend)
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    error_log($e);
    $msg = $e instanceof PDOException
        ? 'Erro no banco de dados: ' . $e->getMessage()
        : 'Erro interno: ' . $e->getMessage();
    jsonResponse(['error' => $msg], 500);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Conexão PDO singleton
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Resposta JSON padronizada
 */
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
