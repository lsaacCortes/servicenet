<?php
// Recebe os webhooks de canal do ChatMix (mensagens recebidas no canal de disparos).
// Publico, sem sessao: protegido por segredo na URL (?s=...). Por enquanto so guarda o payload bruto.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

header('Content-Type: application/json; charset=utf-8');

function wh_out(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'message' => $msg]);
    exit;
}

$secret = defined('CHATMIX_WEBHOOK_SECRET') ? (string) CHATMIX_WEBHOOK_SECRET : '';
if ($secret === '') {
    wh_out(503, 'Webhook desativado.');
}
if (!hash_equals($secret, (string) ($_GET['s'] ?? ''))) {
    wh_out(403, 'Acesso negado.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wh_out(405, 'Use POST.');
}

$corpo = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
if ($corpo === false || trim($corpo) === '') {
    wh_out(400, 'Corpo vazio.');
}

try {
    $pdo = getDB();
    ensure_schema($pdo);
    // Headers recebidos (para descobrir como o ChatMix envia o segredo/assinatura)
    $cab = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0 || $k === 'CONTENT_TYPE') {
            $cab[$k] = is_string($v) ? mb_substr($v, 0, 500) : $v;
        }
    }
    $pdo->prepare("INSERT INTO chatmix_eventos (payload, cabecalhos, ip_origem) VALUES (?, ?, ?)")
        ->execute([$corpo, json_encode($cab, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
    // Mantem so os ultimos 30 dias de payload bruto
    $pdo->exec("DELETE FROM chatmix_eventos WHERE created_at < NOW() - INTERVAL 30 DAY");
} catch (Throwable $e) {
    wh_out(500, 'Erro ao gravar.');
}

wh_out(200, 'Recebido.');
