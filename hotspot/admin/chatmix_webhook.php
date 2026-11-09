<?php
// Recebe os webhooks de canal do ChatMix (mensagens recebidas no canal de disparos).
// Publico, sem sessao: protegido por segredo na URL (?s=...). Por enquanto so guarda o payload bruto.
// Chamadas recusadas tambem ficam registradas (com limite) para diagnosticar a integracao.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

header('Content-Type: application/json; charset=utf-8');

function wh_headers(): string
{
    $cab = ['METHOD' => $_SERVER['REQUEST_METHOD'] ?? '?'];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0 || $k === 'CONTENT_TYPE') {
            $cab[$k] = is_string($v) ? mb_substr($v, 0, 500) : $v;
        }
    }
    return json_encode($cab, JSON_UNESCAPED_UNICODE);
}

function wh_gravar(string $resultado, string $corpo): void
{
    try {
        $pdo = getDB();
        ensure_schema($pdo);
        if ($resultado !== 'ok') {
            // Recusadas: guarda pouco e no maximo 100 por hora (o endpoint e publico)
            $n = (int) $pdo->query("SELECT COUNT(*) FROM chatmix_eventos
                                    WHERE resultado <> 'ok' AND created_at > NOW() - INTERVAL 1 HOUR")->fetchColumn();
            if ($n >= 100) {
                return;
            }
            $corpo = mb_substr($corpo, 0, 2000);
        }
        $pdo->prepare("INSERT INTO chatmix_eventos (payload, cabecalhos, resultado, ip_origem) VALUES (?, ?, ?, ?)")
            ->execute([$corpo, wh_headers(), $resultado, $_SERVER['REMOTE_ADDR'] ?? null]);
        $pdo->exec("DELETE FROM chatmix_eventos WHERE created_at < NOW() - INTERVAL 30 DAY");
    } catch (Throwable $e) {
        // diagnostico e secundario
    }
}

function wh_out(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => $code < 300, 'message' => $msg]);
    exit;
}

$corpo = (string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);

$secret = defined('CHATMIX_WEBHOOK_SECRET') ? (string) CHATMIX_WEBHOOK_SECRET : '';
if ($secret === '') {
    wh_out(503, 'Webhook desativado.');
}
if (!hash_equals($secret, (string) ($_GET['s'] ?? ''))) {
    wh_gravar('recusado: segredo errado', $corpo);
    wh_out(403, 'Acesso negado.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wh_gravar('recusado: metodo ' . ($_SERVER['REQUEST_METHOD'] ?? '?'), $corpo);
    wh_out(405, 'Use POST.');
}
if (trim($corpo) === '') {
    wh_gravar('recusado: corpo vazio', '');
    wh_out(400, 'Corpo vazio.');
}

wh_gravar('ok', $corpo);
wh_out(200, 'Recebido.');
