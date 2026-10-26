<?php
require_once 'session.php';
require_once 'db.php';

admin_require_login(true);
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID invalido.']);
    exit;
}

$pdo = getDB();

switch ($action) {
    case 'update':
        $statusOk = ['novo', 'em_contato', 'convertido', 'descartado'];
        $status   = $_POST['status'] ?? 'novo';
        if (!in_array($status, $statusOk)) {
            echo json_encode(['success' => false, 'message' => 'Status invalido.']);
            exit;
        }
        $anotacoes = trim($_POST['anotacoes'] ?? '');
        $stmt = $pdo->prepare("UPDATE leads SET status = ?, anotacoes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $anotacoes ?: null, $id]);
        echo json_encode(['success' => true, 'message' => 'Lead atualizado.']);
        break;

    case 'delete':
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Lead excluido.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acao invalida.']);
}