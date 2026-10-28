<?php
require_once 'session.php';
require_once 'db.php';
require_once 'alertas_lib.php';

date_default_timezone_set('America/Sao_Paulo');
admin_require_login(true);
header('Content-Type: application/json');

try {
    $pdo = getDB();
    ensure_schema($pdo);
    alert_check($pdo); // respeita o intervalo minimo; varias telas abertas nao sobrecarregam o roteador

    $pend = $pdo->query("SELECT id, tipo, mensagem, created_at, resolvido_em FROM alertas WHERE reconhecido_em IS NULL ORDER BY id DESC LIMIT 5")->fetchAll();
    $n    = (int) $pdo->query("SELECT COUNT(*) FROM alertas WHERE reconhecido_em IS NULL")->fetchColumn();
    echo json_encode(['success' => true, 'total' => $n, 'alertas' => $pend]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao consultar alertas.']);
}
