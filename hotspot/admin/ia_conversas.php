<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
require_once 'ia_lib.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$rotulos = [
    'responder'  => ['Responderia', '#22c55e'],
    'encaminhar' => ['Encaminharia', '#fbbf24'],
    'comprovante'=> ['Comprovante', '#60a5fa'],
    'falha'      => ['Falha', '#ff4c30'],
    'agrupada'   => ['Agrupada', '#94a3b8'],
    'limite'     => ['Limite', '#94a3b8'],
    'ignorado'   => ['Ignorada', '#94a3b8'],
];
$filtro = $_GET['d'] ?? '';
if (!isset($rotulos[$filtro])) {
    $filtro = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$por  = 15;

$where = $filtro !== '' ? 'l.decisao = ?' : "l.decisao <> 'agrupada'";
$params = $filtro !== '' ? [$filtro] : [];
$st = $pdo->prepare("SELECT COUNT(*) FROM ia_log l WHERE $where");
$st->execute($params);
$total = (int) $st->fetchColumn();
$paginas = max(1, (int) ceil($total / $por));
$page = min($page, $paginas);

$st = $pdo->prepare("SELECT l.*, c.nome, c.telefone, m.tipo AS msg_tipo, m.texto AS msg_texto
                     FROM ia_log l
                     LEFT JOIN conversas c ON c.id = l.conversa_id
                     LEFT JOIN mensagens m ON m.id = l.mensagem_id
                     WHERE $where ORDER BY l.id DESC LIMIT $por OFFSET " . (($page - 1) * $por));
$st->execute($params);
$linhas = $st->fetchAll();

$hoje = [];
foreach ($pdo->query("SELECT decisao, COUNT(*) n FROM ia_log WHERE created_at >= CURDATE() GROUP BY decisao")->fetchAll() as $r) {
    $hoje[$r['decisao']] = (int) $r['n'];
}

$estado = !defined('GEMINI_API_KEY') || trim((string) GEMINI_API_KEY) === ''
    ? ['Desligada — falta GEMINI_API_KEY no config.php', '#ff4c30']
    : (ia_ativa() ? ['Modo sombra — analisa e registra, não envia nada ao cliente', '#22c55e']
                  : ['Desligada — defina IA_MODO = \'sombra\' no config.php', '#fbbf24']);

function url_ia(array $over): string
{
    global $filtro, $page;
    return 'ia_conversas.php?' . http_build_query(array_filter(array_merge(['d' => $filtro, 'page' => $page], $over), fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA de atendimento — ServiceNet Admin</title>
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
        .ia-card { background: var(--w06); border: 1px solid var(--w10); border-radius: 16px; padding: 14px 18px; }
        .ia-muted { color: var(--w50); font-size: 12px; }
        .ia-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #111; }
        .ia-bolha { border-radius: 12px; padding: 8px 12px; font-size: 13px; white-space: pre-wrap; word-break: break-word; }
        .ia-cli { background: var(--w10); color: var(--text); }
        .ia-bot { background: rgba(34,197,94,0.14); border: 1px dashed rgba(34,197,94,0.45); color: var(--text); }
        .page-btn { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .page-btn.active { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
        details summary { cursor: pointer; }
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
            <a href="ia_conversas.php" class="nav-item active"><i class="fa-solid fa-robot w-5 text-center"></i><span>IA (sombra)</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">IA de atendimento</h1>
                    <p class="text-xs opacity-50 text-white">O que a IA responderia — nada é enviado ao cliente</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white" style="background: rgba(255,76,48,0.8);">
                    <?= $h(strtoupper(substr($adminNome, 0, 1))) ?>
                </div>
            </div>
        </header>

        <div class="glass-card rounded-2xl p-4 mt-6">
            <p class="text-white text-sm font-semibold"><i class="fa-solid fa-circle" style="color:<?= $estado[1] ?>;font-size:10px;"></i> <?= $h($estado[0]) ?></p>
            <p class="ia-muted mt-1">Hoje:
                <?php foreach (['responder', 'encaminhar', 'comprovante', 'falha'] as $k): ?>
                    <?= $h($rotulos[$k][0]) ?>: <b><?= $hoje[$k] ?? 0 ?></b> ·
                <?php endforeach; ?>
            </p>
        </div>

        <div class="flex flex-wrap gap-2 mt-4">
            <a href="<?= $h(url_ia(['d' => '', 'page' => 1])) ?>" class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold <?= $filtro === '' ? 'active' : '' ?>">Todas</a>
            <?php foreach (['responder', 'encaminhar', 'comprovante', 'falha'] as $k): ?>
                <a href="<?= $h(url_ia(['d' => $k, 'page' => 1])) ?>" class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold <?= $filtro === $k ? 'active' : '' ?>"><?= $h($rotulos[$k][0]) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="flex flex-col gap-3 mt-5">
            <?php if (!$linhas): ?>
                <div class="glass-card rounded-2xl p-8 text-center text-white opacity-70">Nenhuma análise registrada ainda.</div>
            <?php endif; ?>
            <?php foreach ($linhas as $l):
                [$rot, $cor] = $rotulos[$l['decisao']] ?? [$l['decisao'], '#94a3b8'];
                $entrada = $l['msg_texto'] !== null && $l['msg_texto'] !== ''
                    ? ia_mascarar_documentos($l['msg_texto'])
                    : '[' . ucfirst(ia_rotulo_tipo((string) $l['msg_tipo'])) . ']';
            ?>
            <div class="ia-card">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <p class="text-white text-sm font-semibold">
                        <?= $h($l['nome'] ?: 'Sem nome') ?>
                        <span class="ia-muted">· <?= $h(comprovantes_fone_fmt((string) $l['telefone'])) ?></span>
                    </p>
                    <p class="ia-muted">
                        <span class="ia-badge" style="background:<?= $cor ?>;"><?= $h($rot) ?></span>
                        <?= $h(date('d/m H:i', strtotime($l['created_at']))) ?>
                        <?= $l['duracao_ms'] !== null ? ' · ' . round($l['duracao_ms'] / 1000, 1) . 's' : '' ?>
                    </p>
                </div>
                <div class="ia-bolha ia-cli"><?= $h($entrada) ?></div>
                <?php if ($l['resposta']): ?>
                    <div class="ia-bolha ia-bot mt-2"><span class="ia-muted"><i class="fa-solid fa-robot"></i> Resposta simulada</span><br><?= $h($l['resposta']) ?></div>
                <?php endif; ?>
                <?php if ($l['ferramentas'] || $l['detalhes'] || $l['erro']): ?>
                    <details class="mt-2 ia-muted">
                        <summary>Detalhes</summary>
                        <?php if ($l['ferramentas']): ?><p>Consultou: <?= $h($l['ferramentas']) ?></p><?php endif; ?>
                        <?php if ($l['detalhes']): ?><p style="white-space:pre-wrap;word-break:break-word;"><?= $h($l['detalhes']) ?></p><?php endif; ?>
                        <?php if ($l['erro']): ?><p style="color:#ff4c30;">Erro: <?= $h($l['erro']) ?></p><?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($paginas > 1): ?>
        <div class="flex justify-center flex-wrap gap-2 mt-8 mb-4">
            <?php for ($i = 1; $i <= $paginas; $i++): ?>
                <a href="<?= $h(url_ia(['page' => $i])) ?>" class="page-btn px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
