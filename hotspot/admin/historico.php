<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
require_once 'routeros.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);

$souAdmin  = admin_perfil($pdo) === 'admin';
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';

// ---- Filtros ----
$q      = trim($_GET['q'] ?? '');
$de     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : '';
$ate    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : '';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where  = 'WHERE 1=1';
$params = [];
if ($q !== '') {
    $qd = preg_replace('/\D/', '', $q);
    $or = ['mac LIKE ?', 'ip LIKE ?'];
    $params[] = '%' . strtoupper($q) . '%';
    $params[] = '%' . $q . '%';
    if ($qd !== '') {
        $or[]     = 'cpf LIKE ?';
        $params[] = '%' . $qd . '%';
    }
    $where .= ' AND (' . implode(' OR ', $or) . ')';
}
if ($de !== '') {
    $where   .= ' AND created_at >= ?';
    $params[] = $de . ' 00:00:00';
}
if ($ate !== '') {
    $where   .= ' AND created_at <= ?';
    $params[] = $ate . ' 23:59:59';
}

// ---- Exportacao CSV (somente administradores; fica registrada) ----
if (isset($_GET['export'])) {
    if (!$souAdmin) {
        http_response_code(403);
        exit('Somente administradores podem exportar.');
    }
    $stmt = $pdo->prepare("SELECT created_at, cpf, mac, ip, encerrado_em, encerrado_motivo FROM hotspot_logins $where ORDER BY id DESC LIMIT 50000");
    $stmt->execute($params);
    admin_log($pdo, 'exportar_historico', '', 'filtro: ' . ($q ?: '-') . " | $de a $ate");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historico-acessos-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir com acentos
    fputcsv($out, ['Login em', 'CPF', 'MAC', 'IP', 'Encerrado em', 'Motivo'], ';');
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['created_at'], $r['cpf'], $r['mac'], $r['ip'], $r['encerrado_em'], $r['encerrado_motivo']], ';');
    }
    fclose($out);
    exit;
}

$total = $pdo->prepare("SELECT COUNT(*) FROM hotspot_logins $where");
$total->execute($params);
$total      = (int) $total->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT id, created_at, cpf, mac, ip, encerrado_em, encerrado_motivo FROM hotspot_logins $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// "Online agora": o login mais recente do aparelho, se o MAC esta na lista do roteador
$onlineMacs = null;
$avisoRoteador = '';
try {
    $onlineMacs = array_flip(array_column(hotspot_router()->activeList(), 'mac'));
} catch (RouterOsException $e) {
    $avisoRoteador = 'Nao foi possivel consultar o roteador: o status "online agora" nao esta disponivel.';
}
$ultimo = [];
$macs = array_values(array_unique(array_column($rows, 'mac')));
if ($macs) {
    $in = implode(',', array_fill(0, count($macs), '?'));
    $s  = $pdo->prepare("SELECT mac, MAX(id) FROM hotspot_logins WHERE mac IN ($in) GROUP BY mac");
    $s->execute($macs);
    $ultimo = $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

function fmt_dt(?string $d): string
{
    return $d ? date('d/m/Y H:i:s', strtotime($d)) : '—';
}
$qs = http_build_query(['q' => $q, 'de' => $de, 'ate' => $ate]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de acessos — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .glass-input { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .glass-input::placeholder { color: var(--w35); }
        .glass-input:focus { outline: none; border-color: #ff4c30; }
        .tbl { width: 100%; border-collapse: collapse; color: var(--w85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--w45); padding: .6rem .75rem; white-space: nowrap; }
        .tbl td { padding: .6rem .75rem; border-top: 1px solid var(--w08); white-space: nowrap; }
        .tbl tr:hover td { background: var(--w04); }
        .page-btn { background: var(--w08); border: 1px solid var(--w15); color: var(--text); transition: all .2s; }
        .page-btn:hover { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
        .tag-on { color: var(--green-t); font-weight: 700; }
        input[type=date] { color-scheme: dark; }
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
            <a href="historico.php" class="nav-item active"><i class="fa-solid fa-clock-rotate-left w-5 text-center"></i><span>Histórico</span></a>
            <a href="alertas.php" class="nav-item"><i class="fa-solid fa-bell w-5 text-center"></i><span>Alertas</span></a>
            <a href="auditoria.php" class="nav-item"><i class="fa-solid fa-clipboard-list w-5 text-center"></i><span>Auditoria</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Histórico de acessos</h1>
                    <p class="text-xs opacity-50 text-white"><?= number_format($total, 0, ',', '.') ?> registro<?= $total !== 1 ? 's' : '' ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-white text-sm font-semibold hidden sm:block"><?= htmlspecialchars($adminNome) ?></p>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <?php if ($avisoRoteador): ?>
            <div class="mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(251,191,36,0.12); border: 1px solid rgba(251,191,36,0.35); color: var(--yellow-t);"><?= htmlspecialchars($avisoRoteador) ?></div>
        <?php endif; ?>

        <form method="GET" class="glass-card rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-48">
                <label class="block text-xs text-white opacity-60 mb-1">CPF, MAC ou IP</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Ex.: 12345678901, AA:BB:CC... ou 10.50.1.11" class="glass-input w-full px-3 py-2.5 rounded-xl text-sm">
            </div>
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">De</label>
                <input type="date" name="de" value="<?= htmlspecialchars($de) ?>" class="glass-input px-3 py-2 rounded-xl text-sm">
            </div>
            <div>
                <label class="block text-xs text-white opacity-60 mb-1">Até</label>
                <input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>" class="glass-input px-3 py-2 rounded-xl text-sm">
            </div>
            <button class="px-5 py-2.5 rounded-xl text-sm font-bold text-white" style="background: linear-gradient(135deg, #ff4c30, #e63825);"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
            <?php if ($q !== '' || $de !== '' || $ate !== ''): ?>
                <a href="historico.php" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style="background: var(--w10); border: 1px solid var(--w20);">Limpar</a>
            <?php endif; ?>
            <?php if ($souAdmin): ?>
                <a href="?<?= htmlspecialchars($qs) ?>&export=1" class="px-4 py-2.5 rounded-xl text-sm font-bold" style="background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: var(--green-t);"
                   title="Baixa o CSV com o CPF completo (a exportação fica registrada)"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>
            <?php endif; ?>
        </form>

        <div class="glass-card rounded-2xl mt-4 overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Login em</th><th>CPF</th><th>MAC</th><th>IP</th><th>Situação</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $online = $onlineMacs !== null && isset($onlineMacs[$r['mac']]) && (int) ($ultimo[$r['mac']] ?? 0) === (int) $r['id'];
                ?>
                    <tr>
                        <td><?= fmt_dt($r['created_at']) ?></td>
                        <td><?= htmlspecialchars(mask_cpf($r['cpf']) ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['mac']) ?></td>
                        <td><?= htmlspecialchars($r['ip'] ?? '—') ?></td>
                        <td>
                            <?php if ($online): ?>
                                <span class="tag-on"><i class="fa-solid fa-circle text-[8px]"></i> online agora</span>
                            <?php elseif ($r['encerrado_em']): ?>
                                encerrado em <?= fmt_dt($r['encerrado_em']) ?> (<?= htmlspecialchars($r['encerrado_motivo'] ?? '') ?>)
                            <?php else: ?>
                                <span class="opacity-50">saída não registrada</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$rows): ?>
                <p class="text-center text-white opacity-50 py-12">Nenhum acesso encontrado.</p>
            <?php endif; ?>
        </div>

        <p class="text-xs text-white opacity-40 mt-3">
            A saída só é registrada quando um administrador desconecta ou bloqueia o cliente pelo painel.
            Quedas, ociosidade e desligamentos feitos pelo próprio roteador aparecem como "saída não registrada".
        </p>

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
