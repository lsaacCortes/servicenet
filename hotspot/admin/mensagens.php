<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
require_once 'comprovantes_lib.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$souAdmin  = admin_perfil($pdo) === 'admin';
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

const ETIQUETAS = [
    'suporte'   => ['Suporte',    'fa-screwdriver-wrench', 'rgba(255,76,48,0.25)',  '#fecaca'],
    'ja_pagou'  => ['Já pagou',   'fa-circle-check',       'rgba(34,197,94,0.2)',   '#bbf7d0'],
    'vai_pagar' => ['Vai pagar',  'fa-clock',              'rgba(251,191,36,0.2)',  '#fde68a'],
    'cordial'   => ['Cordial',    'fa-face-smile',         'rgba(148,163,184,0.2)', '#cbd5e1'],
    'outros'    => ['Outros',     'fa-comment',            'rgba(96,165,250,0.2)',  '#bfdbfe'],
];

$filtro = $_GET['f'] ?? 'novo';
if (!in_array($filtro, ['novo', 'tratado'], true)) {
    $filtro = 'novo';
}
$etq = $_GET['e'] ?? '';
if ($etq !== '' && !isset(ETIQUETAS[$etq])) {
    $etq = '';
}
$q = trim($_GET['q'] ?? '');

// ---- Acoes (formulario POST + redirecionamento) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volta = 'mensagens.php?' . http_build_query(array_filter(['f' => $filtro, 'e' => $etq, 'q' => $q], fn($v) => $v !== ''));
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['flash'] = ['error', 'Sessão inválida. Tente novamente.'];
        header('Location: ' . $volta); exit;
    }
    if (!$souAdmin) {
        $_SESSION['flash'] = ['error', 'Seu perfil é somente de visualização.'];
        header('Location: ' . $volta); exit;
    }
    $id   = (int) ($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    $st = $pdo->prepare("SELECT telefone FROM chatmix_mensagens WHERE id = ? AND destino = 'outras'");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) {
        $_SESSION['flash'] = ['error', 'Mensagem não encontrada.'];
    } elseif ($acao === 'tratar') {
        $pdo->prepare("UPDATE chatmix_mensagens SET status = 'tratado', tratado_por = ?, tratado_em = NOW() WHERE id = ?")
            ->execute([$adminNome, $id]);
        admin_log($pdo, 'mensagem_tratar', comprovantes_fone_fmt($m['telefone']), 'mensagem #' . $id);
        $_SESSION['flash'] = ['success', 'Marcada como tratada.'];
    } elseif ($acao === 'tratar_todas') {
        // Todas as novas do mesmo numero (cliente que mandou varias mensagens seguidas)
        $pdo->prepare("UPDATE chatmix_mensagens SET status = 'tratado', tratado_por = ?, tratado_em = NOW()
                       WHERE destino = 'outras' AND status = 'novo' AND telefone = ?")
            ->execute([$adminNome, $m['telefone']]);
        admin_log($pdo, 'mensagem_tratar', comprovantes_fone_fmt($m['telefone']), 'todas do número');
        $_SESSION['flash'] = ['success', 'Mensagens do número marcadas como tratadas.'];
    } elseif ($acao === 'reabrir') {
        $pdo->prepare("UPDATE chatmix_mensagens SET status = 'novo', tratado_por = NULL, tratado_em = NULL WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['success', 'Mensagem voltou para novas.'];
    }
    header('Location: ' . $volta); exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- Listagem ----
$where  = "destino = 'outras' AND status = ?";
$params = [$filtro];
if ($etq !== '') {
    $where .= ' AND etiqueta = ?';
    $params[] = $etq;
}
if ($q !== '') {
    $dig = preg_replace('/\D/', '', $q);
    $where .= ' AND (texto LIKE ? OR nome_perfil LIKE ? OR cliente_nome LIKE ?' . ($dig !== '' ? ' OR telefone LIKE ?' : '') . ')';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    if ($dig !== '') $params[] = '%' . $dig . '%';
}
$por  = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$st = $pdo->prepare("SELECT COUNT(*) FROM chatmix_mensagens WHERE $where");
$st->execute($params);
$total   = (int) $st->fetchColumn();
$paginas = max(1, (int) ceil($total / $por));
$page    = min($page, $paginas);
$ordem   = $filtro === 'novo' ? 'recebido_em ASC' : 'tratado_em DESC';
$st = $pdo->prepare("SELECT * FROM chatmix_mensagens WHERE $where ORDER BY $ordem LIMIT $por OFFSET " . (($page - 1) * $por));
$st->execute($params);
$linhas = $st->fetchAll();

$cont = ['novo' => 0, 'tratado' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM chatmix_mensagens WHERE destino = 'outras' GROUP BY status")->fetchAll() as $r) {
    $cont[$r['status']] = (int) $r['n'];
}
$porEtq = [];
foreach ($pdo->query("SELECT etiqueta, COUNT(*) n FROM chatmix_mensagens WHERE destino = 'outras' AND status = 'novo' GROUP BY etiqueta")->fetchAll() as $r) {
    $porEtq[$r['etiqueta']] = (int) $r['n'];
}

function tempo_atras(string $dt): string
{
    $s = max(0, time() - strtotime($dt));
    if ($s < 3600)  return 'há ' . max(1, (int) ($s / 60)) . ' min';
    if ($s < 86400) return 'há ' . (int) ($s / 3600) . ' h';
    return 'há ' . (int) ($s / 86400) . ' dia(s)';
}
function url_pagina(array $over): string
{
    global $filtro, $etq, $q, $page;
    $a = array_merge(['f' => $filtro, 'e' => $etq, 'q' => $q, 'page' => $page], $over);
    return 'mensagens.php?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensagens — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .tab-btn { background: var(--w07); border: 1px solid var(--w15); color: var(--w70); transition: all .2s; }
        .tab-btn:hover, .tab-btn.active { background: rgba(255,76,48,0.25); border-color: rgba(255,76,48,0.5); color: var(--text); }
        .tab-btn .cnt { margin-left: 6px; padding: 1px 7px; border-radius: 999px; font-size: 11px; background: var(--w12); }
        .search-input { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .search-input::placeholder { color: var(--w35); }
        .search-input:focus { outline: none; border-color: #ff4c30; }
        .ms-row { display: grid; grid-template-columns: minmax(0,1.3fr) minmax(0,2.4fr) minmax(0,1fr) auto; align-items: center; gap: 16px;
            padding: 14px 18px; background: var(--w06); border: 1px solid var(--w10); border-radius: 16px; }
        .ms-row.suporte { border-left: 4px solid #ff4c30; }
        .ms-muted { color: var(--w50); font-size: 12px; }
        .ms-txt { color: var(--text); font-size: 14px; white-space: pre-wrap; word-break: break-word; }
        .ms-tag { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .ms-act { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 12px;
            font-size: 12px; font-weight: 700; white-space: nowrap; cursor: pointer; background: var(--w10); border: 1px solid var(--w18); color: var(--text); }
        .ms-act:hover { background: var(--w18); }
        .ms-ok { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: var(--green); }
        .ms-ok:hover { background: rgba(34,197,94,0.28); }
        .chip { background: var(--w07); border: 1px solid var(--w15); color: var(--w70); font-size: 12px; padding: 4px 10px; border-radius: 999px; }
        .chip.active { background: rgba(255,76,48,0.25); border-color: rgba(255,76,48,0.5); color: var(--text); }
        .page-btn { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .page-btn.active { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
        @media (max-width: 900px) {
            .ms-row { grid-template-columns: 1fr; }
            .ms-row .c-acoes { display: flex; gap: 8px; flex-wrap: wrap; }
        }
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
            <a href="comprovantes.php" class="nav-item"><i class="fa-solid fa-file-invoice-dollar w-5 text-center"></i><span>Comprovantes</span></a>
            <a href="mensagens.php" class="nav-item active"><i class="fa-solid fa-inbox w-5 text-center"></i><span>Mensagens</span></a>
            <a href="conectados.php" class="nav-item"><i class="fa-solid fa-wifi w-5 text-center"></i><span>Conectados</span></a>
            <a href="historico.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left w-5 text-center"></i><span>Histórico</span></a>
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
                <button onclick="document.getElementById('sidebar').classList.toggle('sidebar-open')" class="lg:hidden text-white opacity-70 hover:opacity-100" aria-label="Menu">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-white font-bold text-lg leading-none">Mensagens</h1>
                    <p class="text-xs opacity-50 text-white"><?= $cont['novo'] ?> nova(s) de números sem pós-venda recente (comprovantes e pós-venda ficam nas suas telas)</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-white text-sm font-semibold"><?= $h($adminNome) ?></p>
                    <p class="text-xs opacity-50 text-white"><?= date('d/m/Y') ?></p>
                </div>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white" style="background: rgba(255,76,48,0.8);">
                    <?= $h(strtoupper(substr($adminNome, 0, 1))) ?>
                </div>
            </div>
        </header>

        <form method="get" class="glass-card rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-center">
            <input type="hidden" name="f" value="<?= $h($filtro) ?>">
            <input type="hidden" name="e" value="<?= $h($etq) ?>">
            <div class="flex-1 min-w-48 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 opacity-40 text-white text-sm"></i>
                <input name="q" value="<?= $h($q) ?>" type="search" placeholder="Buscar por mensagem, nome ou telefone..." autocomplete="off"
                       class="search-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm">
            </div>
            <button class="ms-act">Buscar</button>
        </form>

        <div class="flex flex-wrap gap-2 mt-4">
            <?php foreach (['novo' => 'Novas', 'tratado' => 'Tratadas'] as $k => $rot): ?>
                <a href="<?= $h(url_pagina(['f' => $k, 'page' => 1])) ?>" class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold <?= $filtro === $k ? 'active' : '' ?>">
                    <?= $rot ?><span class="cnt"><?= $cont[$k] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($filtro === 'novo'): ?>
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="<?= $h(url_pagina(['e' => '', 'page' => 1])) ?>" class="chip <?= $etq === '' ? 'active' : '' ?>">Todas</a>
            <?php foreach (ETIQUETAS as $k => $e): ?>
                <a href="<?= $h(url_pagina(['e' => $k, 'page' => 1])) ?>" class="chip <?= $etq === $k ? 'active' : '' ?>">
                    <?= $h($e[0]) ?> <?= $porEtq[$k] ?? 0 ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="flex flex-col gap-3 mt-5">
            <?php if (!$linhas): ?>
                <div class="glass-card rounded-2xl p-8 text-center text-white opacity-70">
                    <?= $filtro === 'novo' ? 'Nenhuma mensagem nova. 🎉' : 'Nada por aqui.' ?>
                </div>
            <?php endif; ?>
            <?php foreach ($linhas as $m):
                $e = ETIQUETAS[$m['etiqueta']] ?? ETIQUETAS['outros'];
                $nome = $m['cliente_nome'] ?: ($m['nome_perfil'] ?: 'Sem nome');
            ?>
            <div class="ms-row <?= $m['etiqueta'] === 'suporte' && $m['status'] === 'novo' ? 'suporte' : '' ?>">
                <div class="min-w-0">
                    <p class="text-white font-semibold truncate"><?= $h($nome) ?></p>
                    <?php if ($m['cliente_nome']): ?><p class="ms-muted truncate">WhatsApp: <?= $h($m['nome_perfil'] ?: '—') ?></p>
                    <?php else: ?><p class="ms-muted"><i class="fa-solid fa-user-slash"></i> Não encontrado no SGP</p><?php endif; ?>
                    <p class="ms-muted">
                        <a href="https://wa.me/<?= $h($m['telefone']) ?>" target="_blank" rel="noopener" style="color:var(--green);">
                            <i class="fa-brands fa-whatsapp"></i> <?= $h(comprovantes_fone_fmt($m['telefone'])) ?>
                        </a>
                    </p>
                </div>
                <div class="min-w-0">
                    <span class="ms-tag" style="background:<?= $e[2] ?>;color:<?= $e[3] ?>;"><i class="fa-solid <?= $e[1] ?>"></i> <?= $h($e[0]) ?></span>
                    <p class="ms-txt mt-1"><?= $m['texto'] !== null && $m['texto'] !== '' ? $h($m['texto']) : '—' ?></p>
                </div>
                <div>
                    <p class="text-white text-sm"><?= $h(date('d/m/Y H:i', strtotime($m['recebido_em']))) ?></p>
                    <p class="ms-muted"><?= $h(tempo_atras($m['recebido_em'])) ?></p>
                    <?php if ($m['status'] === 'tratado'): ?>
                        <p class="ms-muted mt-1">Tratada por <?= $h($m['tratado_por']) ?> em <?= $h(date('d/m H:i', strtotime((string) $m['tratado_em']))) ?></p>
                    <?php endif; ?>
                </div>
                <div class="c-acoes flex flex-col gap-2">
                    <?php if ($souAdmin): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <?php if ($m['status'] === 'novo'): ?>
                                <input type="hidden" name="acao" value="tratar">
                                <button class="ms-act ms-ok w-full"><i class="fa-solid fa-check"></i> Tratada</button>
                            <?php else: ?>
                                <input type="hidden" name="acao" value="reabrir">
                                <button class="ms-act w-full"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>
                            <?php endif; ?>
                        </form>
                        <?php if ($m['status'] === 'novo'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <input type="hidden" name="acao" value="tratar_todas">
                            <button class="ms-act w-full" title="Marca todas as mensagens novas deste número"><i class="fa-solid fa-check-double"></i> Todas do número</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($paginas > 1): ?>
        <div class="flex justify-center flex-wrap gap-2 mt-8 mb-4">
            <?php for ($i = 1; $i <= $paginas; $i++): ?>
                <a href="<?= $h(url_pagina(['page' => $i])) ?>" class="page-btn px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </main>

    <script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/assets/ui.js') ?>" defer></script>
    <?php if ($flash): ?>
    <script>
        window.addEventListener('load', () => SN.toast(<?= json_encode($flash[1]) ?>, <?= json_encode($flash[0]) ?>));
    </script>
    <?php endif; ?>
</body>
</html>
