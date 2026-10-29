<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';

$pdo = getDB();
ensure_schema($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$souAdmin  = admin_perfil($pdo) === 'admin';
$erro = '';
$ok   = '';

if ($souAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $erro = 'Sessao expirada. Recarregue a pagina e tente novamente.';
    } elseif ($acao === 'criar') {
        $user   = trim($_POST['username'] ?? '');
        $nome   = trim($_POST['nome'] ?? '');
        $senha  = $_POST['senha'] ?? '';
        $perfil = ($_POST['perfil'] ?? '') === 'visualizador' ? 'visualizador' : 'admin';

        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $user)) {
            $erro = 'Usuario: 3 a 50 caracteres (letras, numeros, ponto, hifen ou sublinhado).';
        } elseif ($nome === '' || mb_strlen($nome) > 150) {
            $erro = 'Informe o nome.';
        } elseif (strlen($senha) < 8) {
            $erro = 'A senha deve ter pelo menos 8 caracteres.';
        } else {
            $existe = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
            $existe->execute([$user]);
            if ($existe->fetchColumn() > 0) {
                $erro = 'Ja existe um usuario com esse login.';
            } else {
                $pdo->prepare("INSERT INTO admin_users (username, password, nome, perfil) VALUES (?, ?, ?, ?)")
                    ->execute([$user, password_hash($senha, PASSWORD_BCRYPT), $nome, $perfil]);
                admin_log($pdo, 'criar_usuario', $user, "perfil $perfil");
                $ok = "Usuario '$user' criado.";
            }
        }
    } elseif ($acao === 'remover') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $_SESSION['admin_id']) {
            $erro = 'Voce nao pode remover o proprio usuario.';
        } else {
            $stmt = $pdo->prepare("SELECT username, perfil FROM admin_users WHERE id = ?");
            $stmt->execute([$id]);
            $alvo = $stmt->fetch();
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE perfil = 'admin'")->fetchColumn();
            if (!$alvo) {
                $erro = 'Usuario nao encontrado.';
            } elseif ($alvo['perfil'] === 'admin' && $admins <= 1) {
                $erro = 'Precisa existir pelo menos um administrador.';
            } else {
                $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);
                admin_log($pdo, 'remover_usuario', $alvo['username']);
                $ok = "Usuario '{$alvo['username']}' removido.";
            }
        }
    }
}

$usuarios = $pdo->query("SELECT id, username, nome, perfil, created_at FROM admin_users ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administradores — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .glass-input { background: var(--w08); border: 1px solid var(--w20); color: var(--text); }
        .glass-input::placeholder { color: var(--w35); }
        .glass-input:focus { outline: none; border-color: #ff4c30; box-shadow: 0 0 0 3px rgba(255,76,48,0.25); }
        select.glass-input option { background: var(--option-bg); color: var(--text); }
        .btn-accent { background: linear-gradient(135deg, #ff4c30, #e63825); box-shadow: 0 4px 20px rgba(255,76,48,0.4); }
        .tbl { width: 100%; border-collapse: collapse; color: var(--w85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--w45); padding: .6rem .75rem; }
        .tbl td { padding: .65rem .75rem; border-top: 1px solid var(--w08); }
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
            <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-line w-5 text-center"></i><span>Dashboard</span></a>
            <a href="rede.php" class="nav-item"><i class="fa-solid fa-network-wired w-5 text-center"></i><span>Rede</span></a>
            <a href="leads.php" class="nav-item"><i class="fa-solid fa-users w-5 text-center"></i><span>Leads</span></a>
            <a href="posvenda.php" class="nav-item"><i class="fa-solid fa-headset w-5 text-center"></i><span>Pós-venda</span></a>
            <a href="conectados.php" class="nav-item"><i class="fa-solid fa-wifi w-5 text-center"></i><span>Conectados</span></a>
            <a href="historico.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left w-5 text-center"></i><span>Histórico</span></a>
            <a href="alertas.php" class="nav-item"><i class="fa-solid fa-bell w-5 text-center"></i><span>Alertas</span></a>
            <a href="auditoria.php" class="nav-item"><i class="fa-solid fa-clipboard-list w-5 text-center"></i><span>Auditoria</span></a>
            <a href="usuarios.php" class="nav-item active"><i class="fa-solid fa-user-shield w-5 text-center"></i><span>Administradores</span></a>
            <a href="senha.php" class="nav-item"><i class="fa-solid fa-key w-5 text-center"></i><span>Alterar senha</span></a>
            <a href="auth.php?action=logout" class="nav-item mt-auto"><i class="fa-solid fa-right-from-bracket w-5 text-center"></i><span>Sair</span></a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar glass-card">
            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('sidebar').classList.toggle('sidebar-open')" class="lg:hidden text-white opacity-70 hover:opacity-100">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-white font-bold text-lg leading-none">Administradores</h1>
                    <p class="text-xs opacity-50 text-white">Quem acessa o painel e o que pode fazer</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-white text-sm font-semibold hidden sm:block"><?= htmlspecialchars($adminNome) ?></p>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <?php if (!$souAdmin): ?>
            <div class="glass-card rounded-2xl p-6 mt-6 text-white">
                <i class="fa-solid fa-lock"></i> Somente administradores podem gerenciar usuarios.
            </div>
        <?php else: ?>
            <?php if ($erro): ?>
                <div class="mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.4); color: var(--coral-soft);"><?= htmlspecialchars($erro) ?></div>
            <?php elseif ($ok): ?>
                <div class="mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.4); color: var(--green-t);"><?= htmlspecialchars($ok) ?></div>
            <?php endif; ?>

            <div class="glass-card rounded-2xl mt-6 overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Usuario</th><th>Nome</th><th>Perfil</th><th>Criado em</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['nome']) ?></td>
                            <td><?= $u['perfil'] === 'admin' ? 'Administrador' : 'Somente visualizacao' ?></td>
                            <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-right">
                                <?php if ((int) $u['id'] !== (int) $_SESSION['admin_id']): ?>
                                <form method="POST" data-confirm="Remover o usuário <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? Ele perderá o acesso ao painel." data-confirm-title="Remover usuário" data-confirm-text="Remover">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="acao" value="remover">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="px-3 py-1 rounded-lg text-xs font-bold" style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.35); color: var(--coral);">Remover</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="glass-card rounded-2xl p-6 mt-6 grid gap-4 md:grid-cols-2">
                <h2 class="text-white font-bold md:col-span-2">Novo usuario</h2>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="acao" value="criar">
                <input name="username" required placeholder="Usuario (login)" autocomplete="off" class="glass-input px-4 py-3 rounded-xl text-sm">
                <input name="nome" required placeholder="Nome completo" autocomplete="off" class="glass-input px-4 py-3 rounded-xl text-sm">
                <input name="senha" type="password" required minlength="8" placeholder="Senha (minimo 8 caracteres)" autocomplete="new-password" class="glass-input px-4 py-3 rounded-xl text-sm">
                <select name="perfil" class="glass-input px-4 py-3 rounded-xl text-sm">
                    <option value="admin">Administrador (acesso total)</option>
                    <option value="visualizador">Somente visualizacao</option>
                </select>
                <button class="btn-accent text-white font-bold py-3 rounded-xl md:col-span-2">Criar usuario</button>
            </form>
        <?php endif; ?>
    </main>
    <script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/assets/ui.js') ?>" defer></script>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
