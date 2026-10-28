<?php
// Verificacao de alertas fora do painel (agendador de tarefas / cron).
//   Linha de comando:  php /caminho/hotspot/admin/alertas_cron.php
//   Por URL (opcional): defina ALERT_CRON_TOKEN no config.php e chame
//                       alertas_cron.php?token=SEU_TOKEN
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/alertas_lib.php';

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
    alert_check($pdo, true);
    echo "ok\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "erro\n";
}
