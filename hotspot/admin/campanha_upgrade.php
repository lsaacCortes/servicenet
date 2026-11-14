<?php
// =============================================================
// Campanha de aniversario - upgrade 50 -> 300 Mega
// Controla quem respondeu aos botoes do ChatMix, quem foi confirmado, e as datas da promocao
// (3 meses pelo preco do 50; no 4o mes volta ao valor normal). A troca no SGP e manual.
// =============================================================
require_once 'session.php';
require_once 'db.php';
require_once 'schema.php';
require_once 'upgrade_lib.php';

$ehApi = isset($_GET['action']);
admin_require_login($ehApi);
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$pdo = getDB();
ensure_schema($pdo);
date_default_timezone_set('America/Sao_Paulo');
$souAdmin = admin_perfil($pdo) === 'admin';

function up_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function up_linha(array $r): array
{
    $volta = $r['promo_inicio'] ? upgrade_data_volta($r['promo_inicio']) : null;
    $dias  = $volta ? (int) floor((strtotime($volta) - strtotime(date('Y-m-d'))) / 86400) : null;
    return [
        'id' => (int) $r['id'], 'contrato' => $r['contrato'] ? (int) $r['contrato'] : null, 'nome' => $r['nome'],
        'telefones' => $r['telefones'], 'plano' => $r['plano_atual'], 'origem' => $r['origem'], 'status' => $r['status'],
        'respondeu_em' => $r['respondeu_em'], 'promo_inicio' => $r['promo_inicio'], 'volta' => $volta, 'dias' => $dias,
        'aviso_em' => $r['aviso_em'], 'obs' => $r['obs'],
    ];
}

if ($ehApi) {
    header('Cache-Control: no-store');
    $action = $_GET['action'];

    if ($action === 'lista') {
        $rows = $pdo->query("SELECT * FROM upgrade_clientes ORDER BY COALESCE(respondeu_em, created_at) DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
        up_out(['success' => true, 'clientes' => array_map('up_linha', $rows), 'pode_editar' => $souAdmin]);
    }

    if ($action === 'historico') {
        $st = $pdo->prepare("SELECT evento, por, created_at FROM upgrade_historico WHERE id_cliente = ? ORDER BY id DESC LIMIT 50");
        $st->execute([(int) ($_GET['id'] ?? 0)]);
        up_out(['success' => true, 'historico' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Daqui para baixo so escrita
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        up_out(['success' => false, 'message' => 'Use POST.'], 405);
    }
    $e = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($e) || !hash_equals($_SESSION['csrf'], (string) ($e['csrf'] ?? ''))) {
        up_out(['success' => false, 'message' => 'Sessão inválida. Recarregue a página.'], 403);
    }
    if (!$souAdmin) {
        up_out(['success' => false, 'message' => 'Seu perfil é somente leitura.'], 403);
    }
    $por = $_SESSION['admin_nome'] ?? ($_SESSION['admin_user'] ?? 'admin');

    // Coloca na lista os clientes de 50 Mega achados na tela "Clientes por plano"
    if ($action === 'importar') {
        $novos = 0; $atual = 0;
        $ins = $pdo->prepare("INSERT INTO upgrade_clientes (contrato, nome, telefones, fones_chave, plano_atual, origem, status)
                              VALUES (?, ?, ?, ?, ?, 'base', 'alvo')");
        $upd = $pdo->prepare("UPDATE upgrade_clientes SET nome = ?, telefones = ?, fones_chave = ?, plano_atual = COALESCE(plano_atual, ?) WHERE id = ?");
        $sel = $pdo->prepare("SELECT id FROM upgrade_clientes WHERE contrato = ?");
        foreach (array_slice((array) ($e['clientes'] ?? []), 0, 500) as $c) {
            $contrato = (int) ($c['contrato'] ?? 0);
            $nome = mb_substr(trim((string) ($c['nome'] ?? '')), 0, 150);
            if ($contrato <= 0 || $nome === '') {
                continue;
            }
            $fones = mb_substr((string) ($c['telefones'] ?? ''), 0, 255);
            $plano = mb_substr((string) ($c['plano'] ?? ''), 0, 100);
            $sel->execute([$contrato]);
            $id = $sel->fetchColumn();
            if ($id) {
                $upd->execute([$nome, $fones, upgrade_chaves($fones), $plano, $id]);
                $atual++;
            } else {
                $ins->execute([$contrato, $nome, $fones, upgrade_chaves($fones), $plano]);
                upgrade_hist($pdo, (int) $pdo->lastInsertId(), 'Adicionado à campanha', $por);
                $novos++;
            }
        }
        admin_log($pdo, 'upgrade_importar', 'campanha upgrade', "$novos novos, $atual atualizados");
        up_out(['success' => true, 'novos' => $novos, 'atualizados' => $atual]);
    }

    // Cliente novo que ja entrou no 300 pelo preco promocional
    if ($action === 'novo') {
        $nome = mb_substr(trim((string) ($e['nome'] ?? '')), 0, 150);
        $fones = mb_substr(trim((string) ($e['telefones'] ?? '')), 0, 255);
        $inicio = (string) ($e['inicio'] ?? '');
        $contrato = (int) ($e['contrato'] ?? 0);
        if ($nome === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
            up_out(['success' => false, 'message' => 'Informe nome e a data de início.'], 400);
        }
        try {
            $pdo->prepare("INSERT INTO upgrade_clientes (contrato, nome, telefones, fones_chave, plano_atual, origem, status, promo_inicio)
                           VALUES (?, ?, ?, ?, '300 Mega', 'novo', 'em_promocao', ?)")
                ->execute([$contrato ?: null, $nome, $fones, upgrade_chaves($fones), $inicio]);
        } catch (PDOException $ex) {
            up_out(['success' => false, 'message' => 'Esse contrato já está na campanha.'], 409);
        }
        $id = (int) $pdo->lastInsertId();
        upgrade_hist($pdo, $id, 'Cliente novo no 300 (promoção desde ' . $inicio . ')', $por);
        admin_log($pdo, 'upgrade_novo', $nome, 'início ' . $inicio);
        up_out(['success' => true]);
    }

    // Muda o status / datas / observacao
    if ($action === 'status') {
        $id = (int) ($e['id'] ?? 0);
        $novo = (string) ($e['status'] ?? '');
        if (!isset(UPGRADE_STATUS[$novo])) {
            up_out(['success' => false, 'message' => 'Status inválido.'], 400);
        }
        $st = $pdo->prepare("SELECT nome FROM upgrade_clientes WHERE id = ?");
        $st->execute([$id]);
        $nome = $st->fetchColumn();
        if ($nome === false) {
            up_out(['success' => false, 'message' => 'Cliente não encontrado.'], 404);
        }
        $inicio = null;
        if ($novo === 'em_promocao') {
            $inicio = (string) ($e['inicio'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
                up_out(['success' => false, 'message' => 'Informe a data em que o plano foi trocado.'], 400);
            }
        }
        $pdo->prepare("UPDATE upgrade_clientes SET status = ?, promo_inicio = COALESCE(?, promo_inicio), aviso_em = IF(? IS NULL, aviso_em, NULL), atualizado_por = ? WHERE id = ?")
            ->execute([$novo, $inicio, $inicio, mb_substr($por, 0, 100), $id]);
        upgrade_hist($pdo, $id, 'Status: ' . UPGRADE_STATUS[$novo] . ($inicio ? ' (início ' . $inicio . ')' : ''), $por);
        admin_log($pdo, 'upgrade_status', $nome, $novo);
        up_out(['success' => true]);
    }

    if ($action === 'aviso') {
        $id = (int) ($e['id'] ?? 0);
        $pdo->prepare("UPDATE upgrade_clientes SET aviso_em = NOW() WHERE id = ?")->execute([$id]);
        upgrade_hist($pdo, $id, 'Cliente avisado de que o preço volta ao normal', $por);
        up_out(['success' => true]);
    }

    if ($action === 'obs') {
        $id = (int) ($e['id'] ?? 0);
        $obs = mb_substr(trim((string) ($e['obs'] ?? '')), 0, 500);
        $pdo->prepare("UPDATE upgrade_clientes SET obs = ? WHERE id = ?")->execute([$obs !== '' ? $obs : null, $id]);
        up_out(['success' => true]);
    }

    up_out(['success' => false, 'message' => 'Ação inválida.'], 400);
}

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campanha upgrade — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .pl-input { background: var(--w08); border: 1px solid var(--w15); color: var(--text); border-radius: 10px; padding: 7px 10px; font-size: 13px; }
        .pl-input:focus { outline: none; border-color: #ff4c30; }
        .pl-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;
            background: rgba(255,76,48,0.25); border: 1px solid rgba(255,76,48,0.5); color: var(--text); }
        .pl-btn:hover { background: rgba(255,76,48,0.4); }
        .pl-btn.sec { background: var(--w08); border-color: var(--w15); }
        .pl-muted { color: var(--w50); font-size: 12px; }
        .pl-tab { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pl-tab th { text-align: left; padding: 8px 10px; color: var(--w70); border-bottom: 1px solid var(--w15); position: sticky; top: 0; background: var(--w10); }
        .pl-tab td { padding: 8px 10px; border-bottom: 1px solid var(--w08); color: var(--text); vertical-align: top; }
        .pl-scroll { max-height: 65vh; overflow: auto; border-radius: 12px; }
        .up-card { cursor: pointer; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--w15); background: var(--w08); min-width: 130px; }
        .up-card.on { border-color: #ff4c30; }
        .up-card b { display: block; font-size: 22px; color: var(--text); }
        .up-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--w10); }
        .up-tag.quero, .up-tag.confirmado { background: rgba(34,197,94,.25); }
        .up-tag.nao_quero, .up-tag.desistiu { background: rgba(239,68,68,.25); }
        .up-tag.em_promocao { background: rgba(255,76,48,.25); }
        .up-tag.efetivado { background: rgba(59,130,246,.25); }
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
            <a href="campanha_upgrade.php" class="nav-item active"><i class="fa-solid fa-cake-candles w-5 text-center"></i><span>Campanha upgrade</span></a>
            <a href="planos_sgp.php" class="nav-item"><i class="fa-solid fa-table-list w-5 text-center"></i><span>Clientes por plano</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Campanha de aniversário — upgrade 300 Mega</h1>
                    <p class="text-xs opacity-50 text-white">3 meses pelo preço do 50; no 4º mês volta ao valor normal</p>
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

        <section class="glass-card rounded-2xl p-5 mt-6">
            <div class="flex flex-wrap gap-3" id="cards"></div>
            <p class="pl-muted mt-3">Os cliques nos botões do ChatMix entram sozinhos. Para montar a lista dos clientes de 50 Mega, use “Clientes por plano” → “Adicionar à campanha”.</p>
        </section>

        <section class="glass-card rounded-2xl p-5 mt-6" id="lembretes" style="display:none">
            <p class="text-white text-sm font-semibold mb-2"><i class="fa-solid fa-bell"></i> Avisar que o preço vai voltar ao normal</p>
            <div id="lembretesLista" class="text-sm text-white"></div>
        </section>

        <section class="glass-card rounded-2xl p-5 mt-6">
            <div class="flex flex-wrap items-center gap-3 mb-3">
                <input id="busca" class="pl-input" placeholder="Buscar nome, telefone ou contrato" style="min-width:240px">
                <button id="btnCsv" class="pl-btn sec"><i class="fa-solid fa-file-csv"></i> Baixar CSV</button>
                <?php if ($souAdmin): ?>
                <button id="btnNovo" class="pl-btn"><i class="fa-solid fa-user-plus"></i> Cliente novo no 300</button>
                <?php endif; ?>
                <span id="resumo" class="pl-muted"></span>
            </div>
            <div class="pl-scroll">
                <table class="pl-tab">
                    <thead><tr><th>Cliente</th><th>Telefones</th><th>Status</th><th>Respondeu</th><th>Promoção</th><th>Ações</th></tr></thead>
                    <tbody id="corpo"></tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
    const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
    const STATUS = <?= json_encode(UPGRADE_STATUS, JSON_UNESCAPED_UNICODE) ?>;
    const AVISO_DIAS = <?= UPGRADE_DIAS_AVISO ?>;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const br = d => d ? d.slice(0, 10).split('-').reverse().join('/') : '';
    const brHora = d => d ? br(d) + ' ' + d.slice(11, 16) : '';
    let dados = [], podeEditar = false, filtro = '', podeAcao = false;

    async function api(action, corpo) {
        const r = await fetch('campanha_upgrade.php?action=' + action, corpo
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: CSRF, ...corpo }) } : {});
        const d = await r.json().catch(() => ({ success: false, message: 'Resposta inválida (HTTP ' + r.status + ').' }));
        if (!d.success) throw new Error(d.message || 'Erro');
        return d;
    }
    async function carregar() {
        const d = await api('lista');
        dados = d.clientes; podeEditar = d.pode_editar;
        desenhar();
    }
    async function mudar(id, status, extra) {
        try { await api('status', { id, status, ...extra }); await carregar(); } catch (e) { alert(e.message); }
    }
    window.mudar = mudar;
    window.avisar = async id => { try { await api('aviso', { id }); await carregar(); } catch (e) { alert(e.message); } };
    window.iniciar = id => {
        const v = $('ini' + id).value;
        if (!v) { alert('Escolha a data em que o plano foi trocado no SGP.'); return; }
        mudar(id, 'em_promocao', { inicio: v });
    };
    window.editarObs = async id => {
        const c = dados.find(x => x.id === id);
        const v = prompt('Observação:', c.obs || '');
        if (v === null) return;
        try { await api('obs', { id, obs: v }); await carregar(); } catch (e) { alert(e.message); }
    };
    window.verHist = async id => {
        const d = await api('historico&id=' + id);
        alert(d.historico.map(h => brHora(h.created_at) + ' — ' + h.evento + (h.por ? ' (' + h.por + ')' : '')).join('\n') || 'Sem histórico.');
    };

    function acoes(c) {
        if (!podeEditar) return '';
        const b = (t, js, cls) => `<button class="pl-btn ${cls || ''}" onclick="${js}">${t}</button>`;
        const hoje = new Date().toISOString().slice(0, 10);
        let a = '';
        if (['alvo', 'quero', 'nao_quero'].includes(c.status)) a = b('Confirmar', `mudar(${c.id},'confirmado')`) + (c.status !== 'nao_quero' ? b('Não quer', `mudar(${c.id},'nao_quero')`, 'sec') : '');
        else if (c.status === 'confirmado') a = `<input type="date" id="ini${c.id}" class="pl-input" value="${hoje}"> ` + b('Plano trocado', `iniciar(${c.id})`) + b('Desistiu', `mudar(${c.id},'desistiu')`, 'sec');
        else if (c.status === 'em_promocao') a = (c.aviso_em ? '' : b('Avisado', `avisar(${c.id})`)) + b('Permaneceu', `mudar(${c.id},'efetivado')`) + b('Desistiu', `mudar(${c.id},'desistiu')`, 'sec');
        else a = b('Reabrir', `mudar(${c.id},'quero')`, 'sec');
        return a + b('<i class="fa-solid fa-note-sticky"></i>', `editarObs(${c.id})`, 'sec') + b('<i class="fa-solid fa-clock-rotate-left"></i>', `verHist(${c.id})`, 'sec');
    }

    function desenhar() {
        const cont = {};
        Object.keys(STATUS).forEach(k => cont[k] = dados.filter(c => c.status === k).length);
        $('cards').innerHTML = `<div class="up-card ${filtro === '' ? 'on' : ''}" onclick="setFiltro('')"><b>${dados.length}</b><span class="pl-muted">Todos</span></div>` +
            Object.entries(STATUS).map(([k, v]) => `<div class="up-card ${filtro === k ? 'on' : ''}" onclick="setFiltro('${k}')"><b>${cont[k]}</b><span class="pl-muted">${v}</span></div>`).join('');

        const lemb = dados.filter(c => c.status === 'em_promocao' && c.volta && c.dias <= AVISO_DIAS && !c.aviso_em);
        $('lembretes').style.display = lemb.length ? 'block' : 'none';
        $('lembretesLista').innerHTML = lemb.sort((a, b) => a.dias - b.dias).map(c =>
            `<div class="py-1">${esc(c.nome)} <span class="pl-muted">${esc(c.telefones)}</span> — preço volta em <b>${br(c.volta)}</b> (${c.dias < 0 ? 'atrasado ' + (-c.dias) + ' dia(s)' : c.dias === 0 ? 'hoje' : 'em ' + c.dias + ' dia(s)'})
            ${podeEditar ? `<button class="pl-btn" onclick="avisar(${c.id})">Marcar como avisado</button>` : ''}</div>`).join('');

        const q = $('busca').value.trim().toLowerCase(), qd = q.replace(/\D/g, '');
        const vis = dados.filter(c => (!filtro || c.status === filtro) &&
            (!q || (c.nome || '').toLowerCase().includes(q) || String(c.contrato || '') === q || (qd.length >= 4 && (c.telefones || '').replace(/\D/g, '').includes(qd))));
        $('resumo').textContent = vis.length + ' de ' + dados.length;
        $('corpo').innerHTML = vis.map(c => {
            const origem = c.origem === 'novo' ? ' <span class="up-tag">novo</span>' : c.origem === 'resposta' ? ' <span class="up-tag" title="Número fora da lista">fora da lista</span>' : '';
            const promo = c.promo_inicio ? `${br(c.promo_inicio)} → volta ${br(c.volta)}${c.status === 'em_promocao' ? ` <span class="pl-muted">(${c.dias < 0 ? 'passou há ' + (-c.dias) + 'd' : c.dias + 'd'})</span>` : ''}${c.aviso_em ? '<br><span class="pl-muted">avisado ' + br(c.aviso_em) + '</span>' : ''}` : '';
            return `<tr><td>${esc(c.nome)}${origem}<br><span class="pl-muted">${c.contrato ? 'Contrato ' + c.contrato : ''} ${esc(c.plano || '')}</span>${c.obs ? '<br><span class="pl-muted">' + esc(c.obs) + '</span>' : ''}</td>
                <td>${esc(c.telefones)}</td><td><span class="up-tag ${c.status}">${STATUS[c.status]}</span></td><td>${brHora(c.respondeu_em)}</td><td>${promo}</td><td><div class="flex flex-wrap gap-1">${acoes(c)}</div></td></tr>`;
        }).join('');
    }
    window.setFiltro = f => { filtro = f; desenhar(); };
    $('busca').oninput = desenhar;

    $('btnCsv').onclick = () => {
        const cel = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
        const cab = ['Contrato', 'Nome', 'Telefones', 'Plano', 'Origem', 'Status', 'Respondeu em', 'Início da promoção', 'Preço volta em', 'Observação'];
        const csv = [cab, ...dados.filter(c => !filtro || c.status === filtro).map(c => [c.contrato, c.nome, c.telefones, c.plano, c.origem, STATUS[c.status], brHora(c.respondeu_em), br(c.promo_inicio), br(c.volta), c.obs])]
            .map(r => r.map(cel).join(';')).join('\r\n');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
        a.download = 'campanha-upgrade-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click(); URL.revokeObjectURL(a.href);
    };

    const btnNovo = $('btnNovo');
    if (btnNovo) btnNovo.onclick = async () => {
        const nome = prompt('Nome do cliente novo:'); if (!nome) return;
        const telefones = prompt('Telefone(s) com DDD (separe por /):') || '';
        const contrato = prompt('Número do contrato no SGP (opcional):') || '';
        const inicio = prompt('Data da instalação/início da promoção (AAAA-MM-DD):', new Date().toISOString().slice(0, 10)); if (!inicio) return;
        try { await api('novo', { nome, telefones, contrato, inicio }); await carregar(); } catch (e) { alert(e.message); }
    };

    carregar().catch(e => { $('resumo').textContent = 'Erro: ' + e.message; });
    </script>
</body>
</html>
