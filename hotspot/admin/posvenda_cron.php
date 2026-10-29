<?php
// Sincronizacao do pos-venda com o SGP fora do painel (agendador de tarefas / cron).
//   Linha de comando:  php /caminho/hotspot/admin/posvenda_cron.php
//   Por URL (opcional): defina ALERT_CRON_TOKEN no config.php e chame
//                       posvenda_cron.php?token=SEU_TOKEN
// Sugestao: a cada 30 minutos (ex.: */30 * * * *).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/posvenda_lib.php';

if (PHP_SAPI !== 'cli') {
    $token = $_GET['token'] ?? '';
    if (!defined('ALERT_CRON_TOKEN') || ALERT_CRON_TOKEN === '' || !hash_equals(ALERT_CRON_TOKEN, (string) $token)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain');
}

date_default_timezone_set('America/Sao_Paulo');
try {
    $pdo = getDB();
    ensure_schema($pdo);
    $res = posvenda_sync($pdo);
    if (!$res['success']) {
        http_response_code(502);
        echo 'erro: ' . $res['message'] . "\n";
        exit(1);
    }
    echo 'ok: ' . $res['message'] . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "erro\n";
    exit(1);
}
