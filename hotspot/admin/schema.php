<?php
// =============================================================
// Tabelas e helpers do modulo de gestao do hotspot
// (perfil de acesso, auditoria de acoes, historico de logins)
// =============================================================

function ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE 'perfil'")->fetch()) {
        // Usuarios existentes continuam administradores (acesso total)
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN perfil ENUM('admin','visualizador') NOT NULL DEFAULT 'admin'");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_audit` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `admin_id`   INT UNSIGNED DEFAULT NULL,
            `admin_user` VARCHAR(100) NOT NULL,
            `acao`       VARCHAR(40)  NOT NULL,
            `alvo`       VARCHAR(100) DEFAULT NULL,
            `detalhes`   VARCHAR(255) DEFAULT NULL,
            `ip_origem`  VARCHAR(45)  DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hotspot_logins` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `cpf`        VARCHAR(11) NOT NULL,
            `mac`        VARCHAR(17) NOT NULL,
            `ip`         VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_mac` (`mac`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function admin_perfil(PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT perfil FROM admin_users WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id'] ?? 0]);
    return $stmt->fetchColumn() ?: 'visualizador';
}

function admin_log(PDO $pdo, string $acao, string $alvo = '', string $detalhes = ''): void
{
    $pdo->prepare("INSERT INTO admin_audit (admin_id, admin_user, acao, alvo, detalhes, ip_origem) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([
            $_SESSION['admin_id'] ?? null,
            $_SESSION['admin_user'] ?? '?',
            $acao,
            $alvo ?: null,
            mb_substr($detalhes, 0, 255) ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
}

// Chamado pelo portal do cliente: nunca pode derrubar o login do hotspot se o banco falhar.
function hotspot_log_login(string $cpf, string $mac, string $ip): void
{
    try {
        $cfg = __DIR__ . '/config.php';
        if (!is_file($cfg) || $mac === '') {
            return;
        }
        require_once $cfg;
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        ensure_schema($pdo);
        $pdo->prepare("INSERT INTO hotspot_logins (cpf, mac, ip) VALUES (?, ?, ?)")
            ->execute([preg_replace('/\D/', '', $cpf), strtoupper($mac), $ip ?: null]);
    } catch (Throwable $e) {
        // Registro e secundario: ignora a falha
    }
}

function mask_cpf(string $cpf): string
{
    return strlen($cpf) === 11 ? '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**' : '';
}
