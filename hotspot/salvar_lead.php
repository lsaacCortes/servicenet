<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Requisicao invalida.']);
    exit;
}

$nome     = filter_input(INPUT_POST, 'nome',     FILTER_SANITIZE_SPECIAL_CHARS);
$telefone = filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_SPECIAL_CHARS);
$bairro   = filter_input(INPUT_POST, 'bairro',   FILTER_SANITIZE_SPECIAL_CHARS);

if (empty($nome) || empty($telefone)) {
    echo json_encode(['success' => false, 'message' => 'Por favor, preencha o seu nome e telefone.']);
    exit;
}

$bairro = $bairro ?: 'Nao informado';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$now    = date('Y-m-d H:i:s');

// ---- 1. Salvar no CSV (mantém compatibilidade) ----
$csvSaved = false;
try {
    $file      = __DIR__ . '/leads.csv';
    $isNewFile = !file_exists($file);
    $fp = fopen($file, 'a');
    if ($fp) {
        if ($isNewFile) {
            fputcsv($fp, ['Data/Hora', 'Nome', 'Telefone/WhatsApp', 'Bairro', 'IP Client']);
        }
        fputcsv($fp, [$now, $nome, $telefone, $bairro, $ip]);
        fclose($fp);
        $csvSaved = true;
    }
} catch (Exception $e) {
    // CSV falhou, nao e critico
}

// ---- 2. Salvar no MySQL ----
$dbSaved = false;
try {
    // Inclui db.php somente se existir (evita erro se admin ainda nao foi instalado)
    $dbFile = __DIR__ . '/admin/db.php';
    if (file_exists($dbFile)) {
        require_once $dbFile;
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO leads (nome, telefone, bairro, ip_cliente, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nome, $telefone, $bairro, $ip, $now]);
        $dbSaved = true;
    }
} catch (Exception $e) {
    // DB falhou mas CSV pode ter funcionado
}

if ($csvSaved || $dbSaved) {
    echo json_encode(['success' => true, 'message' => 'Obrigado! A nossa equipa entrara em contacto em breve.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao guardar dados. Tente novamente.']);
}