<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';

$rotulos = [
    'desconectar'        => 'Desconectou cliente',
    'desconectar_todos'  => 'Desconectou todos',
    'bloquear'           => 'Bloqueou dispositivo',
    'desbloquear'        => 'Desbloqueou dispositivo',
    'limitar'            => 'Limitou velocidade',
    'remover_limite'     => 'Removeu limite',
    'criar_usuario'      => 'Criou administrador',
    'remover_usuario'    => 'Removeu administrador',
    'exportar_historico' => 'Exportou histórico',
    'config_alertas'     => 'Alterou alertas',
];

// ---- Filtros ----
$quem = trim($_GET['quem'] ?? '');
$acao = trim($_GET['acao'] ?? '');
$de   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : '';
$ate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = 'WHERE 1=1';
$params = [];
if ($quem !== '') { $where .= ' AND admin_user = ?'; $params[] = $quem; }
if ($acao !== '') { $where .= ' AND acao = ?';       $params[] = $acao; }
if ($de !== '')   { $where .= ' AND created_at >= ?'; $params[] = $de . ' 00:00:00'; }
if ($ate !== '')  { $where .= ' AND created_at <= ?'; $params[] = $ate . ' 23:59:59'; }

$total = $pdo->prepare("SELECT COUNT(*) FROM admin_audit $where");
$total->execute($params);
$total = (int) $total->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT admin_user, acao, alvo, detalhes, ip_origem, created_at FROM admin_audit $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$usuarios = $pdo->query("SELECT DISTINCT admin_user FROM admin_audit ORDER BY admin_user")->fetchAll(PDO::FETCH_COLUMN);
$acoes    = $pdo->query("SELECT DISTINCT acao FROM admin_audit ORDER BY acao")->fetchAll(PDO::FETCH_COLUMN);
$qs = http_build_query(['quem' => $quem, 'acao' => $acao, 'de' => $de, 'ate' => $ate]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .glass-input { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #fff; }
        .glass-input:focus { outline: none; border-color: #ff4c30; }
        select.glass-input option { background: #1a2744; color: white; }
        input[type=date] { color-scheme: dark; }
        .tbl { width: 100%; border-collapse: collapse; color: rgba(255,255,255,0.85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,0.45); padding: .6rem .75rem; white-space: nowrap; }
        .tbl td { padding: .6rem .75rem; border-top: 1px solid rgba(255,255,255,0.08); white-space: nowrap; }
        .tbl tr:hover td { background: rgba(255,255,255,0.04); }
        .page-btn { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: white; transition: all .2s; }
        .page-btn:hover { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
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
            <a href="conectados.php" class="nav-item"><i class="fa-solid fa-wifi w-5 text-center"></i><span>Conectados</span></a>
            <a href="historico.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left w-5 text-center"></i><span>Histórico</span></a>
            <a href="alertas.php" class="nav-item"><i class="fa-solid fa-bell w-5 text-center"></i><span>Alertas</span></a>
            <a href="auditoria.php" class="nav-item active"><i class="fa-solid fa-clipboard-list w-5 text-center"></i><span>Auditoria</span></a>
            <a href="usuarios.php" class="nav-item"><i class="fa-solid fa-user-shield w-5 text-center"></i><span>Administradores</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Registro de ações dos administradores</h1>
                    <p class="text-xs opacity-50 text-white"><?= number_format($total, 0, ',', '.') ?> registro<?= $total !== 1 ? 's' : '' ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-white text-sm font-semibold hidden sm:block"><?= htmlspecialchars($adminNome) ?></p>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <form method="GET" class="glass-card rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">Administrador</label>
                <select name="quem" class="glass-input px-3 py-2.5 rounded-xl text-sm">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $u === $quem ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">Ação</label>
                <select name="acao" class="glass-input px-3 py-2.5 rounded-xl text-sm">
                    <option value="">Todas</option>
                    <?php foreach ($acoes as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $a === $acao ? 'selected' : '' ?>><?= htmlspecialchars($rotulos[$a] ?? $a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">De</label>
                <input type="date" name="de" value="<?= htmlspecialchars($de) ?>" class="glass-input px-3 py-2 rounded-xl text-sm">
            </div>
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">Até</label>
                <input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>" class="glass-input px-3 py-2 rounded-xl text-sm">
            </div>
            <button class="px-5 py-2.5 rounded-xl text-sm font-bold text-white" style="background: linear-gradient(135deg, #ff4c30, #e63825);"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
            <?php if ($quem !== '' || $acao !== '' || $de !== '' || $ate !== ''): ?>
                <a href="auditoria.php" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">Limpar</a>
            <?php endif; ?>
        </form>

        <div class="glass-card rounded-2xl mt-4 overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Quando</th><th>Administrador</th><th>Ação</th><th>Alvo</th><th>Detalhes</th><th>IP de origem</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
                        <td><?= htmlspecialchars($r['admin_user']) ?></td>
                        <td><?= htmlspecialchars($rotulos[$r['acao']] ?? $r['acao']) ?></td>
                        <td><?= htmlspecialchars($r['alvo'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['detalhes'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['ip_origem'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$rows): ?><p class="text-center text-white opacity-50 py-12">Nenhuma ação registrada.</p><?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex justify-center items-center gap-3 mt-6 mb-4">
            <a href="?<?= htmlspecialchars($qs) ?>&page=<?= max(1, $page - 1) ?>" class="page-btn px-4 py-2 rounded-xl text-sm font-bold <?= $page <= 1 ? 'pointer-events-none opacity-30' : '' ?>"><i class="fa-solid fa-arrow-left"></i> Anterior</a>
            <span class="text-white text-sm opacity-70">Página <?= $page ?> de <?= $totalPages ?></span>
            <a href="?<?= htmlspecialchars($qs) ?>&page=<?= min($totalPages, $page + 1) ?>" class="page-btn px-4 py-2 rounded-xl text-sm font-bold <?= $page >= $totalPages ? 'pointer-events-none opacity-30' : '' ?>">Próximo <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php endif; ?>
    </main>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
