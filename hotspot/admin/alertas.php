<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'alertas_lib.php';

date_default_timezone_set('America/Sao_Paulo');
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
    } elseif ($acao === 'salvar') {
        $con = $_POST['max_conectados'] ?? '';
        $tra = $_POST['max_trafego_mb'] ?? '';
        if (!ctype_digit($con) || !ctype_digit($tra) || (int) $con > 100000 || (int) $tra > 1000000) {
            $erro = 'Informe apenas numeros inteiros (0 desliga o alerta).';
        } else {
            $up = $pdo->prepare("UPDATE alert_config SET valor = ? WHERE chave = ?");
            $up->execute([(string) (int) $con, 'max_conectados']);
            $up->execute([(string) (int) $tra, 'max_trafego_mb']);
            admin_log($pdo, 'config_alertas', '', "conectados>$con, trafego>{$tra}MB");
            $ok = 'Limites salvos.';
        }
    } elseif ($acao === 'reconhecer') {
        $pdo->prepare("UPDATE alertas SET reconhecido_em = NOW(), reconhecido_por = ? WHERE id = ? AND reconhecido_em IS NULL")
            ->execute([$_SESSION['admin_user'] ?? '?', (int) ($_POST['id'] ?? 0)]);
    } elseif ($acao === 'reconhecer_todos') {
        $pdo->prepare("UPDATE alertas SET reconhecido_em = NOW(), reconhecido_por = ? WHERE reconhecido_em IS NULL")
            ->execute([$_SESSION['admin_user'] ?? '?']);
        $ok = 'Todos os alertas foram reconhecidos.';
    }
}

$cfg     = alert_config($pdo);
$alertas = $pdo->query("SELECT * FROM alertas ORDER BY id DESC LIMIT 100")->fetchAll();
$pendentes = (int) $pdo->query("SELECT COUNT(*) FROM alertas WHERE reconhecido_em IS NULL")->fetchColumn();
$telegramOk = defined('ALERT_TELEGRAM_TOKEN') && ALERT_TELEGRAM_TOKEN !== '' && defined('ALERT_TELEGRAM_CHAT') && ALERT_TELEGRAM_CHAT !== '';

function fmt_dt2(?string $d): string
{
    return $d ? date('d/m/Y H:i', strtotime($d)) : '—';
}
$tipos = ['conectados' => 'Muitos conectados', 'trafego' => 'Alto consumo', 'roteador' => 'Roteador'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .glass-input { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2); color: white; }
        .glass-input:focus { outline: none; border-color: #ff4c30; box-shadow: 0 0 0 3px rgba(255,76,48,0.25); }
        .btn-accent { background: linear-gradient(135deg, #ff4c30, #e63825); box-shadow: 0 4px 20px rgba(255,76,48,0.4); }
        .tbl { width: 100%; border-collapse: collapse; color: rgba(255,255,255,0.85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,0.45); padding: .6rem .75rem; white-space: nowrap; }
        .tbl td { padding: .6rem .75rem; border-top: 1px solid rgba(255,255,255,0.08); }
        .pill { padding: .15rem .6rem; border-radius: 999px; font-size: .7rem; font-weight: 700; white-space: nowrap; }
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
            <a href="alertas.php" class="nav-item active"><i class="fa-solid fa-bell w-5 text-center"></i><span>Alertas</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Alertas</h1>
                    <p class="text-xs opacity-50 text-white"><?= $pendentes ?> pendente<?= $pendentes !== 1 ? 's' : '' ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-white text-sm font-semibold hidden sm:block"><?= htmlspecialchars($adminNome) ?></p>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <?php if ($erro): ?>
            <div class="mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.4); color: #ffb3a5;"><?= htmlspecialchars($erro) ?></div>
        <?php elseif ($ok): ?>
            <div class="mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.4); color: #86efac;"><?= htmlspecialchars($ok) ?></div>
        <?php endif; ?>

        <form method="POST" class="glass-card rounded-2xl p-6 mt-6 grid gap-4 md:grid-cols-2">
            <h2 class="text-white font-bold md:col-span-2">Quando avisar</h2>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="acao" value="salvar">
            <div>
                <label class="block text-sm font-semibold mb-1.5 text-white opacity-80">Clientes conectados acima de</label>
                <input type="number" name="max_conectados" min="0" max="100000" value="<?= $cfg['max_conectados'] ?>" <?= $souAdmin ? '' : 'disabled' ?> class="glass-input w-full px-4 py-3 rounded-xl text-sm">
                <p class="text-xs text-white opacity-50 mt-1">0 = desligado</p>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5 text-white opacity-80">Consumo de um cliente acima de (MB, download + upload da sessão)</label>
                <input type="number" name="max_trafego_mb" min="0" max="1000000" value="<?= $cfg['max_trafego_mb'] ?>" <?= $souAdmin ? '' : 'disabled' ?> class="glass-input w-full px-4 py-3 rounded-xl text-sm">
                <p class="text-xs text-white opacity-50 mt-1">0 = desligado. Ex.: 2048 = 2 GB</p>
            </div>
            <?php if ($souAdmin): ?>
                <button class="btn-accent text-white font-bold py-3 rounded-xl md:col-span-2">Salvar limites</button>
            <?php endif; ?>
            <p class="text-xs text-white opacity-50 md:col-span-2">
                Também avisa quando o roteador não responde. Um alerta abre quando a situação aparece e fecha sozinho quando ela termina; não repete enquanto durar.
                <br>Verificação: <strong>com o painel aberto</strong>, a cada ~30 s<?= '' ?>; fora do painel, só se houver um agendamento (cron) apontando para <code>alertas_cron.php</code>.
                <br>Aviso no Telegram: <strong><?= $telegramOk ? 'configurado' : 'não configurado' ?></strong>.
            </p>
        </form>

        <div class="glass-card rounded-2xl mt-6 overflow-x-auto">
            <div class="p-5 pb-2 flex items-center justify-between gap-3">
                <h2 class="text-white font-bold text-sm">Histórico de alertas</h2>
                <?php if ($souAdmin && $pendentes): ?>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                    <input type="hidden" name="acao" value="reconhecer_todos">
                    <button class="px-3 py-1.5 rounded-lg text-xs font-bold" style="background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: #86efac;">Reconhecer todos</button>
                </form>
                <?php endif; ?>
            </div>
            <table class="tbl">
                <thead><tr><th>Quando</th><th>Tipo</th><th>Mensagem</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($alertas as $a): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= fmt_dt2($a['created_at']) ?></td>
                        <td><?= htmlspecialchars($tipos[$a['tipo']] ?? $a['tipo']) ?></td>
                        <td><?= htmlspecialchars($a['mensagem']) ?></td>
                        <td class="whitespace-nowrap">
                            <?php if ($a['resolvido_em']): ?>
                                <span class="pill" style="background: rgba(34,197,94,0.2); color:#86efac;">resolvido <?= fmt_dt2($a['resolvido_em']) ?></span>
                            <?php else: ?>
                                <span class="pill" style="background: rgba(255,76,48,0.25); color:#ffb3a5;">em andamento</span>
                            <?php endif; ?>
                            <?php if ($a['reconhecido_por']): ?>
                                <span class="text-xs opacity-50">· visto por <?= htmlspecialchars($a['reconhecido_por']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($souAdmin && !$a['reconhecido_em']): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                <input type="hidden" name="acao" value="reconhecer">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="px-3 py-1 rounded-lg text-xs font-bold" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color:#fff;">Reconhecer</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$alertas): ?><p class="text-center text-white opacity-50 py-10">Nenhum alerta até agora.</p><?php endif; ?>
        </div>
    </main>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
