<?php
// =============================================================
// Sessao segura do painel admin
// =============================================================
const ADMIN_IDLE_TIMEOUT = 1800;   // 30 min sem atividade
const ADMIN_MAX_LIFETIME = 28800;  // 8 h desde o login, mesmo com atividade

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) ADMIN_MAX_LIFETIME);

    // Nome e caminho proprios: nao se mistura com a sessao do portal do hotspot
    session_name('sn_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if (isset($_SESSION['admin_id'])) {
        $agora = time();
        $idle  = $agora - ($_SESSION['last_activity'] ?? 0);
        $vida  = $agora - ($_SESSION['login_time'] ?? 0);
        if ($idle > ADMIN_IDLE_TIMEOUT || $vida > ADMIN_MAX_LIFETIME) {
            admin_session_destroy();
            session_name('sn_admin');
            session_start();
            $_SESSION['sessao_expirada'] = true;
            return;
        }
        $_SESSION['last_activity'] = $agora;
    }
}

function admin_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function admin_no_cache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Exige login. $json = true devolve 401 em JSON em vez de redirecionar.
function admin_require_login(bool $json = false): void
{
    admin_session_start();
    admin_no_cache();
    if (!isset($_SESSION['admin_id'])) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sessao expirada. Faca login novamente.']);
        } else {
            header('Location: login.php');
        }
        exit;
    }
}
