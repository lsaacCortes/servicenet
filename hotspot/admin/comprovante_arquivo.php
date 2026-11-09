<?php
// Entrega a imagem/PDF de um comprovante, so para admin logado.
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
require_once 'comprovantes_lib.php';

$pdo = getDB();
$stmt = $pdo->prepare("SELECT arquivo, mime FROM comprovantes WHERE id = ?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$r = $stmt->fetch();

$mimes = array_keys(COMPROVANTE_EXT);
$path  = $r && $r['arquivo'] ? COMPROVANTE_DIR . '/' . basename($r['arquivo']) : '';
if (!$r || !in_array($r['mime'], $mimes, true) || !is_file($path)) {
    http_response_code(404);
    exit('Arquivo não disponível.');
}

header('Content-Type: ' . $r['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="comprovante-' . (int) $_GET['id'] . '.' . COMPROVANTE_EXT[$r['mime']] . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
