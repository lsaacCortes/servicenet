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

$filtro = $_GET['f'] ?? 'pendente';
if (!in_array($filtro, ['pendente', 'baixado', 'descartado'], true)) {
    $filtro = 'pendente';
}
$q = trim($_GET['q'] ?? '');

// ---- Acoes (formulario POST + redirecionamento) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volta = 'comprovantes.php?f=' . urlencode($filtro) . ($q !== '' ? '&q=' . urlencode($q) : '');
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
    $st = $pdo->prepare("SELECT nome, telefone, status FROM comprovantes WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        $_SESSION['flash'] = ['error', 'Comprovante não encontrado.'];
    } elseif ($acao === 'baixar' || $acao === 'descartar') {
        $novo = $acao === 'baixar' ? 'baixado' : 'descartado';
        $nota = mb_substr(trim($_POST['anotacao'] ?? ''), 0, 255) ?: null;
        $pdo->prepare("UPDATE comprovantes SET status = ?, baixado_por = ?, baixado_em = NOW(), anotacao = ? WHERE id = ?")
            ->execute([$novo, $adminNome, $nota, $id]);
        admin_log($pdo, 'comprovante_' . $novo, $c['nome'] ?: $c['telefone'], 'comprovante #' . $id);
        $_SESSION['flash'] = ['success', $acao === 'baixar' ? 'Baixa registrada.' : 'Comprovante descartado.'];
    } elseif ($acao === 'identificar') {
        [$oki, $msgi] = comprovantes_identificar($pdo, $id);
        $_SESSION['flash'] = [$oki ? 'success' : 'error', $msgi];
    } elseif ($acao === 'rebaixar') {
        [$okb, $msgb] = comprovantes_rebaixar($pdo, $id);
        $_SESSION['flash'] = [$okb ? 'success' : 'error', $msgb];
    } elseif ($acao === 'reabrir') {
        $pdo->prepare("UPDATE comprovantes SET status = 'pendente', baixado_por = NULL, baixado_em = NULL WHERE id = ?")->execute([$id]);
        admin_log($pdo, 'comprovante_reabrir', $c['nome'] ?: $c['telefone'], 'comprovante #' . $id);
        $_SESSION['flash'] = ['success', 'Comprovante voltou para pendentes.'];
    }
    header('Location: ' . $volta); exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- Listagem ----
$where = 'status = ?';
$params = [$filtro];
if ($q !== '') {
    $dig = preg_replace('/\D/', '', $q);
    $where .= ' AND (nome LIKE ?' . ($dig !== '' ? ' OR telefone LIKE ?' : '') . ')';
    $params[] = '%' . $q . '%';
    if ($dig !== '') $params[] = '%' . $dig . '%';
}
$por  = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$st = $pdo->prepare("SELECT COUNT(*) FROM comprovantes WHERE $where");
$st->execute($params);
$total   = (int) $st->fetchColumn();
$paginas = max(1, (int) ceil($total / $por));
$page    = min($page, $paginas);
$ordem   = $filtro === 'pendente' ? 'recebido_em ASC' : 'baixado_em DESC';
$st = $pdo->prepare("SELECT * FROM comprovantes WHERE $where ORDER BY $ordem LIMIT $por OFFSET " . (($page - 1) * $por));
$st->execute($params);
$linhas = $st->fetchAll();

$cont = ['pendente' => 0, 'baixado' => 0, 'descartado' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM comprovantes GROUP BY status")->fetchAll() as $r) {
    $cont[$r['status']] = (int) $r['n'];
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
    global $filtro, $q, $page;
    $a = array_merge(['f' => $filtro, 'q' => $q, 'page' => $page], $over);
    return 'comprovantes.php?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovantes — ServiceNet Admin</title>
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
        .cp-row { display: grid; grid-template-columns: 84px minmax(0,2fr) minmax(0,1.2fr) auto; align-items: center; gap: 16px;
            padding: 14px 18px; background: var(--w06); border: 1px solid var(--w10); border-radius: 16px; }
        .cp-row.antigo { border-left: 4px solid #ff4c30; }
        .cp-thumb { width: 84px; height: 84px; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: var(--w10); border: 1px solid var(--w15); color: var(--w50); font-size: 12px; text-align: center; }
        .cp-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .cp-muted { color: var(--w50); font-size: 12px; }
        .cp-act { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 12px;
            font-size: 12px; font-weight: 700; white-space: nowrap; cursor: pointer; background: var(--w10); border: 1px solid var(--w18); color: var(--text); }
        .cp-act:hover { background: var(--w18); }
        .cp-ok { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: var(--green); }
        .cp-ok:hover { background: rgba(34,197,94,0.28); }
        .page-btn { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .page-btn.active { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
        @media (max-width: 900px) {
            .cp-row { grid-template-columns: 84px 1fr; }
            .cp-row .c-acoes { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
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
            <a href="comprovantes.php" class="nav-item active"><i class="fa-solid fa-file-invoice-dollar w-5 text-center"></i><span>Comprovantes</span></a>
            <a href="mensagens.php" class="nav-item"><i class="fa-solid fa-inbox w-5 text-center"></i><span>Mensagens</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Comprovantes</h1>
                    <p class="text-xs opacity-50 text-white"><?= $cont['pendente'] ?> pendente(s) de baixa no sistema de clientes</p>
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
            <div class="flex-1 min-w-48 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 opacity-40 text-white text-sm"></i>
                <input name="q" value="<?= $h($q) ?>" type="search" placeholder="Buscar por nome ou telefone..." autocomplete="off"
                       class="search-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm">
            </div>
            <button class="cp-act">Buscar</button>
        </form>

        <div class="flex flex-wrap gap-2 mt-4">
            <?php foreach (['pendente' => 'Pendentes', 'baixado' => 'Baixados', 'descartado' => 'Descartados'] as $k => $rot): ?>
                <a href="<?= $h(url_pagina(['f' => $k, 'page' => 1])) ?>" class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold <?= $filtro === $k ? 'active' : '' ?>">
                    <?= $rot ?><span class="cnt"><?= $cont[$k] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="flex flex-col gap-3 mt-5">
            <?php if (!$linhas): ?>
                <div class="glass-card rounded-2xl p-8 text-center text-white opacity-70">
                    <?= $filtro === 'pendente' ? 'Nenhum comprovante pendente. 🎉' : 'Nada por aqui.' ?>
                </div>
            <?php endif; ?>
            <?php foreach ($linhas as $c):
                $antigo = $c['status'] === 'pendente' && time() - strtotime($c['recebido_em']) > 86400;
                $temArq = !empty($c['arquivo']);
                $ehImg  = strpos((string) $c['mime'], 'image/') === 0;
                $nome   = $c['nome'] ?: 'Sem nome';
            ?>
            <div class="cp-row <?= $antigo ? 'antigo' : '' ?>">
                <div>
                    <?php if ($temArq): ?>
                        <a href="comprovante_arquivo.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener" class="cp-thumb js-ver" data-id="<?= (int) $c['id'] ?>" data-img="<?= $ehImg ? '1' : '0' ?>"
                           data-titulo="<?= $h($nome . ' · ' . date('d/m/Y H:i', strtotime($c['recebido_em']))) ?>" title="Ver comprovante">
                            <?php if ($ehImg): ?><img src="comprovante_arquivo.php?id=<?= (int) $c['id'] ?>" alt="Comprovante" loading="lazy">
                            <?php else: ?><span><i class="fa-solid fa-file-pdf text-2xl"></i><br>PDF</span><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <div class="cp-thumb" title="<?= $h($c['erro_midia'] ?: 'Arquivo não baixado') ?>"><span><i class="fa-solid fa-image-slash"></i><br>sem arquivo</span></div>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <p class="text-white font-semibold truncate"><?= $h($nome) ?></p>
                    <p class="cp-muted">
                        <a href="https://wa.me/<?= $h($c['telefone']) ?>" target="_blank" rel="noopener" style="color:var(--green);">
                            <i class="fa-brands fa-whatsapp"></i> <?= $h(comprovantes_fone_fmt($c['telefone'])) ?>
                        </a>
                    </p>
                    <?php
                        $clientes = $c['cliente_json'] ? (json_decode($c['cliente_json'], true) ?: []) : [];
                        $cs = $c['cliente_status'];
                    ?>
                    <?php if ($cs === 'unico' || $cs === 'varios'): ?>
                        <div class="mt-2 text-xs" style="color:var(--w70);">
                        <?php foreach (array_slice($clientes, 0, 3) as $cl):
                            $docm = comprovantes_doc_mascarado((string) ($cl['cpf'] ?? ''));
                            $ctr = array_map(fn($x) => '#' . ($x['id'] ?? '?') . ' ' . ($x['status'] ?? ''), array_slice($cl['contratos'] ?? [], 0, 3));
                            $primeiro = explode(' ', comprovantes_norm((string) $c['nome']))[0];
                            $difere = $cs === 'unico' && $primeiro !== '' && strpos(comprovantes_norm((string) $cl['nome']), $primeiro) === false;
                        ?>
                            <p><i class="fa-solid fa-user-check" style="color:var(--green);"></i>
                                <b class="text-white"><?= $h($cl['nome']) ?></b><?= $docm ? ' · ' . $h($docm) : '' ?><br>
                                <span class="cp-muted">Contrato <?= $h(implode(' · ', $ctr)) ?></span></p>
                            <?php if ($difere): ?>
                                <p style="color:#fbbf24;"><i class="fa-solid fa-triangle-exclamation"></i> Nome do WhatsApp difere do titular — pode ser outra pessoa pagando.</p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($cs === 'varios'): ?><p style="color:#fbbf24;">Mais de um cliente com esse telefone — confira o comprovante.</p><?php endif; ?>
                        </div>
                    <?php elseif ($cs === 'nenhum'): ?>
                        <p class="cp-muted mt-2"><i class="fa-solid fa-user-slash"></i> Telefone não encontrado no SGP.</p>
                    <?php elseif ($cs === 'erro'): ?>
                        <p class="cp-muted mt-2" style="color:#ff4c30;"><i class="fa-solid fa-triangle-exclamation"></i> SGP: <?= $h($c['cliente_erro']) ?></p>
                    <?php endif; ?>
                    <?php if (!$temArq): ?>
                        <p class="cp-muted mt-1" style="color:#ff4c30;"><i class="fa-solid fa-triangle-exclamation"></i>
                            <?= $h($c['erro_midia'] ?: 'Arquivo não baixado') ?></p>
                    <?php endif; ?>
                    <?php if ($c['legenda']): ?><p class="cp-muted mt-1">“<?= $h($c['legenda']) ?>”</p><?php endif; ?>
                </div>
                <div>
                    <p class="text-white text-sm"><?= $h(date('d/m/Y H:i', strtotime($c['recebido_em']))) ?></p>
                    <p class="cp-muted" <?= $antigo ? 'style="color:#ff4c30;font-weight:700;"' : '' ?>><?= $h(tempo_atras($c['recebido_em'])) ?></p>
                    <?php if ($c['status'] !== 'pendente'): ?>
                        <p class="cp-muted mt-1"><?= $c['status'] === 'baixado' ? 'Baixa' : 'Descartado' ?> por <?= $h($c['baixado_por']) ?>
                            em <?= $h(date('d/m H:i', strtotime((string) $c['baixado_em']))) ?><?= $c['anotacao'] ? ' — ' . $h($c['anotacao']) : '' ?></p>
                    <?php endif; ?>
                </div>
                <div class="c-acoes flex flex-col gap-2">
                    <?php if ($souAdmin): ?>
                        <?php if ($cs !== 'unico'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="acao" value="identificar">
                                <button class="cp-act w-full"><i class="fa-solid fa-user-magnifying-glass"></i> Identificar cliente</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$temArq): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="acao" value="rebaixar">
                                <button class="cp-act w-full"><i class="fa-solid fa-cloud-arrow-down"></i> Baixar arquivo</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($c['status'] === 'pendente'): ?>
                            <form method="post" data-confirm="Confirma que a baixa de <?= $h($nome) ?> foi feita no sistema de clientes?"
                                  data-confirm-title="Dar baixa" data-confirm-text="Dar baixa" data-confirm-tone="primary">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="acao" value="baixar">
                                <button class="cp-act cp-ok w-full"><i class="fa-solid fa-check"></i> Dar baixa</button>
                            </form>
                            <form method="post" data-confirm="Descartar este envio (não é um comprovante)?"
                                  data-confirm-title="Descartar" data-confirm-text="Descartar">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="acao" value="descartar">
                                <button class="cp-act w-full"><i class="fa-solid fa-ban"></i> Descartar</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="acao" value="reabrir">
                                <button class="cp-act w-full"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>
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

    <!-- Visualizador do comprovante -->
    <div id="viewer" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop" role="dialog" aria-modal="true" aria-label="Comprovante">
        <div class="modal-box rounded-2xl w-full relative flex flex-col" style="max-width: 960px; max-height: 92vh;">
            <div class="flex items-center justify-between gap-3 px-5 py-3" style="border-bottom: 1px solid var(--w10);">
                <p id="vTitulo" class="text-white text-sm font-semibold truncate"></p>
                <div class="flex items-center gap-3 shrink-0">
                    <a id="vAbrir" href="#" target="_blank" rel="noopener" class="text-xs opacity-60 hover:opacity-100 text-white" title="Abrir em nova aba">
                        <i class="fa-solid fa-up-right-from-square"></i></a>
                    <button id="vFechar" type="button" class="text-white opacity-60 hover:opacity-100 text-2xl leading-none" aria-label="Fechar">&times;</button>
                </div>
            </div>
            <div id="vCorpo" class="flex-1 overflow-auto flex items-center justify-center p-3" style="min-height: 200px;"></div>
        </div>
    </div>
    <script>
    (function () {
        const modal = document.getElementById('viewer');
        const corpo = document.getElementById('vCorpo');
        function fechar() {
            modal.classList.add('hidden'); modal.classList.remove('flex');
            corpo.innerHTML = '';
            document.body.style.overflow = '';
        }
        function abrir(a) {
            const url = 'comprovante_arquivo.php?id=' + encodeURIComponent(a.dataset.id);
            document.getElementById('vTitulo').textContent = a.dataset.titulo || '';
            document.getElementById('vAbrir').href = url;
            corpo.innerHTML = '';
            let el;
            if (a.dataset.img === '1') {
                el = document.createElement('img');
                el.alt = 'Comprovante';
                el.style.cssText = 'max-width:100%; max-height:80vh; border-radius:8px; object-fit:contain;';
            } else {
                el = document.createElement('iframe');
                el.title = 'Comprovante';
                el.style.cssText = 'width:100%; height:80vh; border:0; border-radius:8px; background:#fff;';
            }
            el.src = url;
            corpo.append(el);
            modal.classList.remove('hidden'); modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.getElementById('vFechar').focus();
        }
        document.addEventListener('click', e => {
            const a = e.target.closest('.js-ver');
            if (a) { e.preventDefault(); abrir(a); }
        });
        document.getElementById('vFechar').addEventListener('click', fechar);
        modal.addEventListener('mousedown', e => { if (e.target === modal) fechar(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) fechar(); });
    })();
    </script>
    <script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/assets/ui.js') ?>" defer></script>
    <?php if ($flash): ?>
    <script>
        window.addEventListener('load', () => SN.toast(<?= json_encode($flash[1]) ?>, <?= json_encode($flash[0]) ?>));
    </script>
    <?php endif; ?>
</body>
</html>
