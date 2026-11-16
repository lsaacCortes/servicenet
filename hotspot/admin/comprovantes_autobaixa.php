<?php
// Uso (Agendador de Tarefas / cron, ex.: a cada 10 min):  php comprovantes_autobaixa.php
// Da baixa nos comprovantes pendentes cujo boleto ja foi baixado no SGP depois do envio.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/comprovantes_lib.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);
echo date('Y-m-d H:i:s') . ' - baixados: ' . comprovantes_autobaixa($pdo, 100) . PHP_EOL;
