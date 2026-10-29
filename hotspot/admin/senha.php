<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$erro = '';
$ok   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $atual = $_POST['atual'] ?? '';
    $nova  = $_POST['nova'] ?? '';
    $conf  = $_POST['confirma'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $erro = 'Sessao expirada. Recarregue a pagina e tente novamente.';
    } elseif ($atual === '' || $nova === '' || $conf === '') {
        $erro = 'Preencha todos os campos.';
    } elseif (strlen($nova) < 8) {
        $erro = 'A nova senha deve ter pelo menos 8 caracteres.';
    } elseif ($nova !== $conf) {
        $erro = 'A confirmacao nao confere com a nova senha.';
    } elseif ($nova === $atual) {
        $erro = 'A nova senha deve ser diferente da atual.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT password FROM admin_users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($atual, $hash)) {
            $erro = 'Senha atual incorreta.';
        } else {
            $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?")
                ->execute([password_hash($nova, PASSWORD_BCRYPT), $_SESSION['admin_id']]);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            $ok = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alterar Senha — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .glass-input {
            background: var(--w08);
            border: 1px solid var(--w20);
            color: var(--text);
        }
        .glass-input::placeholder { color: var(--w35); }
        .glass-input:focus { outline: none; border-color: #ff4c30; box-shadow: 0 0 0 3px rgba(255,76,48,0.25); }
        .btn-accent { background: linear-gradient(135deg, #ff4c30, #e63825); box-shadow: 0 4px 20px rgba(255,76,48,0.4); }
    </style>
</head>
<body class="admin-body">

    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo"><img src="../assets/img/logo.PNG" class="logo-bordered" alt="ServiceNet"
                 onerror="this.outerHTML='<span class=\'text-white font-extrabold text-lg\'>ServiceNet</span>'">
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span>Dashboard</span>
            </a>
            <a href="leads.php" class="nav-item">
                <i class="fa-solid fa-users w-5 text-center"></i>
                <span>Leads</span>
            </a>
            <a href="posvenda.php" class="nav-item"><i class="fa-solid fa-headset w-5 text-center"></i><span>Pós-venda</span></a>
            <a href="rede.php" class="nav-item">
                <i class="fa-solid fa-network-wired w-5 text-center"></i>
                <span>Rede</span>
            </a>
            <a href="conectados.php" class="nav-item">
                <i class="fa-solid fa-wifi w-5 text-center"></i>
                <span>Conectados</span>
            </a>
            <a href="historico.php" class="nav-item">
                <i class="fa-solid fa-clock-rotate-left w-5 text-center"></i>
                <span>Histórico</span>
            </a>
            <a href="alertas.php" class="nav-item">
                <i class="fa-solid fa-bell w-5 text-center"></i>
                <span>Alertas</span>
            </a>
            <a href="auditoria.php" class="nav-item">
                <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                <span>Auditoria</span>
            </a>
            <a href="usuarios.php" class="nav-item">
                <i class="fa-solid fa-user-shield w-5 text-center"></i>
                <span>Administradores</span>
            </a>
            <a href="senha.php" class="nav-item active">
                <i class="fa-solid fa-key w-5 text-center"></i>
                <span>Alterar senha</span>
            </a>
            <a href="auth.php?action=logout" class="nav-item mt-auto">
                <i class="fa-solid fa-right-from-bracket w-5 text-center"></i>
                <span>Sair</span>
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar glass-card">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('sidebar').classList.toggle('sidebar-open')"
                        class="lg:hidden text-white opacity-70 hover:opacity-100">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-white font-bold text-lg leading-none">Alterar Senha</h1>
                    <p class="text-xs opacity-50 text-white">Conta: <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white" style="background: rgba(255,76,48,0.8);">
                <?= strtoupper(substr($adminNome, 0, 1)) ?>
            </div>
            </div>
        </header>

        <div class="glass-card rounded-2xl p-6 mt-6 max-w-lg">
            <?php if ($ok): ?>
            <div class="mb-5 p-4 rounded-xl text-sm font-semibold text-white" style="background: rgba(34,197,94,0.2); border: 1px solid rgba(34,197,94,0.4);">
                <i class="fa-solid fa-circle-check mr-2"></i>Senha alterada com sucesso.
            </div>
            <?php elseif ($erro): ?>
            <div class="mb-5 p-4 rounded-xl text-sm font-semibold text-white" style="background: rgba(255,76,48,0.2); border: 1px solid rgba(255,76,48,0.4);">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($erro) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color: var(--w75);" for="atual">
                        <i class="fa-solid fa-lock mr-1.5 opacity-70"></i>Senha atual
                    </label>
                    <input class="glass-input w-full py-3 px-4 rounded-xl text-sm" type="password" id="atual" name="atual"
                           required autocomplete="current-password">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color: var(--w75);" for="nova">
                        <i class="fa-solid fa-key mr-1.5 opacity-70"></i>Nova senha
                    </label>
                    <input class="glass-input w-full py-3 px-4 rounded-xl text-sm" type="password" id="nova" name="nova"
                           required minlength="8" placeholder="Minimo de 8 caracteres" autocomplete="new-password">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color: var(--w75);" for="confirma">
                        <i class="fa-solid fa-check-double mr-1.5 opacity-70"></i>Confirmar nova senha
                    </label>
                    <input class="glass-input w-full py-3 px-4 rounded-xl text-sm" type="password" id="confirma" name="confirma"
                           required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn-accent w-full py-3 px-4 rounded-xl text-white font-bold text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>Salvar nova senha
                </button>
            </form>
        </div>
    </main>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
