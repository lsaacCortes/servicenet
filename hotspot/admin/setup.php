<?php
// =============================================================
// Script de Setup do Banco de Dados
// Execute UMA VEZ: http://localhost/servicenet/hotspot/admin/setup.php
// =============================================================

$flag = __DIR__ . '/.setup_enabled';
if (!file_exists($flag)) {
    http_response_code(403);
    die('Setup desabilitado. Crie o arquivo vazio admin/.setup_enabled no servidor para executar e apague-o depois.');
}
if (!file_exists(__DIR__ . '/config.php')) {
    die('config.php ausente. Copie config.example.php para config.php e preencha.');
}
require_once __DIR__ . '/config.php';

$log = [];
$success = false;

try {
    // Conecta sem selecionar banco para poder criar
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 1. Criar banco de dados
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    $log[] = ["ok", "Banco de dados '" . DB_NAME . "' criado/verificado."];

    // 2. Criar tabela de usuarios admin
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_users` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username`   VARCHAR(100) NOT NULL UNIQUE,
            `password`   VARCHAR(255) NOT NULL,
            `nome`       VARCHAR(150) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = ["ok", "Tabela 'admin_users' criada/verificada."];

    // 3. Criar tabela de leads
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `leads` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `nome`        VARCHAR(150) NOT NULL,
            `telefone`    VARCHAR(30)  NOT NULL,
            `bairro`      VARCHAR(150) DEFAULT 'Nao informado',
            `ip_cliente`  VARCHAR(45)  DEFAULT NULL,
            `status`      ENUM('novo','em_contato','convertido','descartado') NOT NULL DEFAULT 'novo',
            `anotacoes`   TEXT         DEFAULT NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = ["ok", "Tabela 'leads' criada/verificada."];

    // 4. Inserir usuario admin (senha de config ou aleatoria, exibida uma unica vez)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $senha = (defined('ADMIN_INITIAL_PASSWORD') && ADMIN_INITIAL_PASSWORD !== '')
            ? ADMIN_INITIAL_PASSWORD
            : bin2hex(random_bytes(6));
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO admin_users (username, password, nome) VALUES (?, ?, ?)")
            ->execute(['admin', $hash, 'Administrador']);
        $log[] = ["ok", "Usuario criado: <strong>admin</strong> / senha: <strong>" . htmlspecialchars($senha) . "</strong> (anote agora, nao sera exibida de novo)"];
    } else {
        $log[] = ["info", "Usuario 'admin' ja existe. Nenhuma alteracao."];
    }

    // 5. Importar leads do CSV, se existir
    $csvFile = dirname(__DIR__) . '/leads.csv';
    if (file_exists($csvFile)) {
        $handle = fopen($csvFile, 'r');
        $header = fgetcsv($handle); // pula cabecalho
        $imported = 0;
        $skipped  = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) { $skipped++; continue; }
            [$dataHora, $nome, $telefone, $bairro, $ip] = array_pad($row, 5, null);
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO leads (nome, telefone, bairro, ip_cliente, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nome, $telefone, $bairro ?? 'Nao informado', $ip, $dataHora]);
            if ($stmt->rowCount() > 0) $imported++;
            else $skipped++;
        }
        fclose($handle);
        $log[] = ["ok", "CSV importado: <strong>$imported</strong> novos leads, $skipped ignorados."];
    } else {
        $log[] = ["info", "Nenhum arquivo leads.csv encontrado para importar."];
    }

    $success = true;
    @unlink($flag); // setup so roda uma vez
} catch (PDOException $e) {
    $log[] = ["error", "ERRO: " . $e->getMessage()];
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup - ServiceNet Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>body { font-family: 'Raleway', sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-[#172a4c] to-[#0a1222] min-h-screen flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="h-2 bg-[#ff4c30]"></div>
    <div class="p-8">
        <h1 class="text-2xl font-bold text-[#172a4c] mb-2"><i class="fa-solid fa-screwdriver-wrench mr-2"></i>Setup do Banco de Dados</h1>
        <p class="text-gray-500 text-sm mb-6">ServiceNet Hotspot — Painel de Gestao de Leads</p>
        <div class="space-y-3">
        <?php foreach ($log as [$type, $msg]): ?>
            <?php $colors = ['ok'=>'bg-green-50 border-green-400 text-green-800','info'=>'bg-blue-50 border-blue-400 text-blue-800','error'=>'bg-red-50 border-red-400 text-red-800']; ?>
            <div class="border-l-4 p-3 rounded-r-md <?= $colors[$type] ?>">
                <i class="fa-solid <?= $type==='ok'?'fa-circle-check':($type==='info'?'fa-circle-info':'fa-circle-xmark') ?> mr-2"></i>
                <?= $msg ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($success): ?>
        <div class="mt-8 text-center">
            <a href="login.php" class="inline-block bg-[#172a4c] hover:bg-[#0f1d35] text-white font-bold py-3 px-8 rounded-xl transition duration-300 shadow-lg">
                Ir para o Login <i class="fa-solid fa-arrow-right ml-1"></i>
            </a>
            <p class="text-xs text-red-500 mt-4 font-semibold"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Setup concluido e desabilitado. Troque a senha do admin e apague este arquivo do servidor.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>