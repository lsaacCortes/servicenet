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
            `encerrado_em`     DATETIME DEFAULT NULL,
            `encerrado_motivo` VARCHAR(30) DEFAULT NULL,
            KEY `idx_mac` (`mac`, `id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `alert_config` (
            `chave` VARCHAR(40)  NOT NULL PRIMARY KEY,
            `valor` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 0 = desligado
    $pdo->exec("INSERT IGNORE INTO alert_config (chave, valor) VALUES
        ('max_conectados', '0'), ('max_trafego_mb', '0'), ('ultima_execucao', '0')");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `alertas` (
            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `tipo`           VARCHAR(20)  NOT NULL,
            `chave`          VARCHAR(40)  NOT NULL,
            `mensagem`       VARCHAR(255) NOT NULL,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `resolvido_em`   DATETIME DEFAULT NULL,
            `reconhecido_em` DATETIME DEFAULT NULL,
            `reconhecido_por` VARCHAR(100) DEFAULT NULL,
            KEY `idx_aberto` (`resolvido_em`, `chave`),
            KEY `idx_pend` (`reconhecido_em`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Pos-venda: contratos do SGP acompanhados 7 dias apos a ativacao
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `posvenda` (
            `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_contrato`       INT UNSIGNED NOT NULL,
            `id_cliente`        INT UNSIGNED DEFAULT NULL,
            `nome`              VARCHAR(150) NOT NULL,
            `cpfcnpj`           VARCHAR(14)  DEFAULT NULL,
            `telefone`          VARCHAR(30)  DEFAULT NULL,
            `email`             VARCHAR(150) DEFAULT NULL,
            `bairro`            VARCHAR(100) DEFAULT NULL,
            `cidade`            VARCHAR(100) DEFAULT NULL,
            `status_contrato`   VARCHAR(30)  DEFAULT NULL,
            `data_cadastro`     DATETIME DEFAULT NULL,
            `ativado_em`        DATETIME NOT NULL,
            `status`            ENUM('pendente','em_contato','sem_resposta','concluido') NOT NULL DEFAULT 'pendente',
            `resultado`         ENUM('satisfeito','problema_resolvido','precisa_visita','outro') DEFAULT NULL,
            `anotacoes`         TEXT DEFAULT NULL,
            `ultimo_contato_em` DATETIME DEFAULT NULL,
            `ultimo_contato_por` VARCHAR(100) DEFAULT NULL,
            `concluido_em`      DATETIME DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_contrato` (`id_contrato`),
            KEY `idx_status` (`status`, `ativado_em`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Webhook de canal do ChatMix: guarda o payload bruto de cada evento recebido
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `chatmix_eventos` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `payload`    MEDIUMTEXT NOT NULL,
            `ip_origem`  VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!$pdo->query("SHOW COLUMNS FROM chatmix_eventos LIKE 'cabecalhos'")->fetch()) {
        $pdo->exec("ALTER TABLE chatmix_eventos ADD COLUMN `cabecalhos` TEXT DEFAULT NULL AFTER `payload`");
    }

    if (!$pdo->query("SHOW COLUMNS FROM chatmix_eventos LIKE 'resultado'")->fetch()) {
        $pdo->exec("ALTER TABLE chatmix_eventos ADD COLUMN `resultado` VARCHAR(60) NOT NULL DEFAULT 'ok' AFTER `cabecalhos`");
    }

    // Comprovantes recebidos pelo canal do ChatMix, pendentes de baixa no sistema de clientes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `comprovantes` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `wamid`        VARCHAR(150) NOT NULL,
            `telefone`     VARCHAR(20)  NOT NULL,
            `nome`         VARCHAR(150) DEFAULT NULL,
            `tipo`         VARCHAR(20)  NOT NULL,
            `mime`         VARCHAR(80)  DEFAULT NULL,
            `nome_arquivo` VARCHAR(150) DEFAULT NULL,
            `legenda`      VARCHAR(500) DEFAULT NULL,
            `media_id`     VARCHAR(60)  DEFAULT NULL,
            `media_url`    TEXT DEFAULT NULL,
            `arquivo`      VARCHAR(80)  DEFAULT NULL,
            `recebido_em`  DATETIME NOT NULL,
            `status`       ENUM('pendente','baixado','descartado') NOT NULL DEFAULT 'pendente',
            `baixado_por`  VARCHAR(100) DEFAULT NULL,
            `baixado_em`   DATETIME DEFAULT NULL,
            `anotacao`     VARCHAR(255) DEFAULT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_wamid` (`wamid`),
            KEY `idx_status` (`status`, `recebido_em`),
            KEY `idx_fone` (`telefone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!$pdo->query("SHOW COLUMNS FROM comprovantes LIKE 'erro_midia'")->fetch()) {
        $pdo->exec("ALTER TABLE comprovantes ADD COLUMN `erro_midia` VARCHAR(250) DEFAULT NULL AFTER `arquivo`");
    }

    if (!$pdo->query("SHOW COLUMNS FROM comprovantes LIKE 'cliente_status'")->fetch()) {
        // Resultado da busca do cliente no SGP pelo telefone: unico | varios | nenhum | erro
        $pdo->exec("ALTER TABLE comprovantes
            ADD COLUMN `cliente_status` VARCHAR(10) DEFAULT NULL,
            ADD COLUMN `cliente_json` TEXT DEFAULT NULL,
            ADD COLUMN `cliente_erro` VARCHAR(250) DEFAULT NULL,
            ADD COLUMN `identificado_em` DATETIME DEFAULT NULL");
    }

    // A mensagem de erro do SGP lista os campos recebidos e pode ser longa
    if (!$pdo->query("SHOW COLUMNS FROM comprovantes WHERE Field = 'cliente_erro' AND Type LIKE 'text%'")->fetch()
        && $pdo->query("SHOW COLUMNS FROM comprovantes LIKE 'cliente_erro'")->fetch()) {
        $pdo->exec("ALTER TABLE comprovantes MODIFY `cliente_erro` TEXT DEFAULT NULL");
    }

    // Disparos de pos-venda feitos por nos: so quem esta aqui tem a resposta roteada para o pos-venda
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `posvenda_envios` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_posvenda` INT UNSIGNED NOT NULL,
            `fone_chave`  VARCHAR(12) NOT NULL,
            `enviado_por` VARCHAR(100) DEFAULT NULL,
            `enviado_em`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_chave` (`fone_chave`, `enviado_em`),
            KEY `idx_posvenda` (`id_posvenda`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Mensagens (texto, audio, botoes...) recebidas no canal de disparos, ja roteadas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `chatmix_mensagens` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `wamid`        VARCHAR(150) NOT NULL,
            `telefone`     VARCHAR(20)  NOT NULL,
            `fone_chave`   VARCHAR(12)  NOT NULL,
            `nome_perfil`  VARCHAR(150) DEFAULT NULL,
            `tipo`         VARCHAR(20)  NOT NULL,
            `texto`        VARCHAR(1000) DEFAULT NULL,
            `destino`      ENUM('posvenda','outras') NOT NULL,
            `id_posvenda`  INT UNSIGNED DEFAULT NULL,
            `etiqueta`     VARCHAR(20)  DEFAULT NULL,
            `cliente_nome` VARCHAR(150) DEFAULT NULL,
            `status`       ENUM('novo','tratado') NOT NULL DEFAULT 'novo',
            `tratado_por`  VARCHAR(100) DEFAULT NULL,
            `tratado_em`   DATETIME DEFAULT NULL,
            `recebido_em`  DATETIME NOT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_wamid` (`wamid`),
            KEY `idx_destino` (`destino`, `status`, `recebido_em`),
            KEY `idx_posvenda` (`id_posvenda`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Campanha de aniversario (upgrade 50 -> 300 Mega, 3 meses pelo preco do 50)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `upgrade_clientes` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `contrato`      INT UNSIGNED DEFAULT NULL,
            `nome`          VARCHAR(150) NOT NULL,
            `telefones`     VARCHAR(255) DEFAULT NULL,
            `fones_chave`   VARCHAR(255) NOT NULL DEFAULT ',',
            `plano_atual`   VARCHAR(100) DEFAULT NULL,
            `origem`        ENUM('base','novo','resposta') NOT NULL DEFAULT 'base',
            `status`        ENUM('alvo','quero','nao_quero','confirmado','em_promocao','efetivado','desistiu') NOT NULL DEFAULT 'alvo',
            `respondeu_em`  DATETIME DEFAULT NULL,
            `promo_inicio`  DATE DEFAULT NULL,
            `aviso_em`      DATETIME DEFAULT NULL,
            `obs`           VARCHAR(500) DEFAULT NULL,
            `atualizado_por` VARCHAR(100) DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_contrato` (`contrato`),
            KEY `idx_status` (`status`, `respondeu_em`),
            KEY `idx_promo` (`status`, `promo_inicio`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `upgrade_historico` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_cliente` INT UNSIGNED NOT NULL,
            `evento`     VARCHAR(255) NOT NULL,
            `por`        VARCHAR(100) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_cliente` (`id_cliente`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Tabelas criadas antes do historico de acessos
    if (!$pdo->query("SHOW COLUMNS FROM hotspot_logins LIKE 'encerrado_em'")->fetch()) {
        $pdo->exec("ALTER TABLE hotspot_logins
            ADD COLUMN `encerrado_em` DATETIME DEFAULT NULL,
            ADD COLUMN `encerrado_motivo` VARCHAR(30) DEFAULT NULL,
            ADD KEY `idx_created` (`created_at`)");
    }
}

// Registra o fim do acesso do ultimo login ainda aberto daquele aparelho
function hotspot_mark_ended(PDO $pdo, string $mac, string $motivo): void
{
    $pdo->prepare("UPDATE hotspot_logins SET encerrado_em = NOW(), encerrado_motivo = ?
                   WHERE mac = ? AND encerrado_em IS NULL ORDER BY id DESC LIMIT 1")
        ->execute([$motivo, strtoupper($mac)]);
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
