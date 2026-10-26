<?php
// =============================================================
// Configuracao de Conexao com o Banco de Dados
// =============================================================
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    die(json_encode(['error' => 'config.php ausente. Copie config.example.php para config.php.']));
}
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Falha na conexao com o banco.']));
        }
    }
    return $pdo;
}