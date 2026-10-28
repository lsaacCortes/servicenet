<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
$pdo = getDB();
ensure_schema($pdo);
$podeAgir  = admin_perfil($pdo) === 'admin';
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectados — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .tab-btn { background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.7); transition: all .2s; }
        .tab-btn:hover, .tab-btn.active { background: rgba(255,76,48,0.25); border-color: rgba(255,76,48,0.5); color: #fff; }
        .search-input { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #fff; }
        .search-input::placeholder { color: rgba(255,255,255,0.35); }
        .search-input:focus { outline: none; border-color: #ff4c30; }
        .tbl { width: 100%; border-collapse: collapse; color: rgba(255,255,255,0.85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,0.45); padding: .6rem .75rem; white-space: nowrap; }
        .tbl td { padding: .65rem .75rem; border-top: 1px solid rgba(255,255,255,0.08); white-space: nowrap; }
        .tbl tr:hover td { background: rgba(255,255,255,0.04); }
        .act-btn { padding: .35rem .7rem; border-radius: .6rem; font-size: .75rem; font-weight: 700; transition: all .2s; }
        .act-warn { background: rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.35); color: #fde68a; }
        .act-warn:hover { background: rgba(251,191,36,0.3); }
        .act-danger { background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.35); color: #ff7a61; }
        .act-danger:hover { background: rgba(255,76,48,0.3); }
        .act-ok { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
        .act-ok:hover { background: rgba(34,197,94,0.3); }
        .idle-alto { color: #fde68a; font-weight: 700; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; display: inline-block; }
        .dot.off { background: #ff7a61; }
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
            <a href="conectados.php" class="nav-item active"><i class="fa-solid fa-wifi w-5 text-center"></i><span>Conectados</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Clientes conectados</h1>
                    <p class="text-xs opacity-50 text-white" id="subtitulo">Carregando...</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-white text-sm font-semibold"><?= htmlspecialchars($adminNome) ?></p>
                    <p class="text-xs opacity-50 text-white"><?= $podeAgir ? 'Administrador' : 'Somente visualizacao' ?></p>
                </div>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <div id="aviso" class="hidden mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.4); color: #ffb3a5;"></div>

        <div class="glass-card rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-center">
            <button class="tab-btn active px-4 py-2 rounded-xl text-sm font-semibold" data-tab="ativos">Conectados <span id="cntAtivos"></span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-tab="bloqueados">Bloqueados <span id="cntBloq"></span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-tab="auditoria">Historico de acoes</button>
            <div class="flex-1 min-w-40"></div>
            <input id="busca" type="text" placeholder="Buscar IP, MAC ou CPF..." class="search-input px-3 py-2 rounded-xl text-sm w-60">
            <span class="text-xs text-white opacity-60 flex items-center gap-2"><span class="dot" id="dotLive"></span><span id="ultAtualiz">—</span></span>
            <?php if ($podeAgir): ?>
            <button id="btnTodos" class="act-btn act-danger"><i class="fa-solid fa-power-off"></i> Desconectar todos</button>
            <?php endif; ?>
        </div>

        <div class="glass-card rounded-2xl mt-4 overflow-x-auto">
            <table class="tbl" id="tabela"></table>
            <p id="vazio" class="hidden text-center text-white opacity-50 py-12"></p>
        </div>
    </main>

    <script>
    const PODE_AGIR = <?= $podeAgir ? 'true' : 'false' ?>;
    const IDLE_ALERTA_S = 3 * 3600;
    let aba = 'ativos', ativos = [], bloqueados = [], auditoria = [], busy = false;

    const $ = id => document.getElementById(id);
    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }
    function fmtBytes(n) {
        const u = ['B','KB','MB','GB','TB']; let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return n.toFixed(i ? 1 : 0) + ' ' + u[i];
    }
    // "1h2m3s" / "2d4h" (formato RouterOS) -> segundos
    function durS(s) {
        const m = { w: 604800, d: 86400, h: 3600, m: 60, s: 1 };
        let t = 0; (s || '').replace(/(\d+)([wdhms])/g, (_, n, k) => { t += n * m[k]; });
        return t;
    }

    async function api(action, data) {
        const opt = { credentials: 'same-origin' };
        let url = 'conectados_api.php?action=' + encodeURIComponent(action);
        if (data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            fd.append('action', action);
            Object.assign(opt, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
            url = 'conectados_api.php';
        }
        const res = await fetch(url, opt);
        if (res.status === 401) { location.href = 'login.php'; throw new Error('sessao'); }
        return res.json();
    }

    function aviso(msg) {
        const a = $('aviso');
        a.textContent = msg || '';
        a.classList.toggle('hidden', !msg);
    }

    async function carregar() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const [a, b] = await Promise.all([api('list'), api('blocked')]);
            if (!a.success) { throw new Error(a.message); }
            ativos = a.active;
            bloqueados = b.success ? b.blocked : [];
            if (aba === 'auditoria') {
                const r = await api('audit');
                auditoria = r.success ? r.audit : [];
            }
            aviso('');
            $('dotLive').classList.remove('off');
            $('ultAtualiz').textContent = 'atualizado ' + new Date().toLocaleTimeString('pt-BR');
        } catch (e) {
            if (e.message !== 'sessao') {
                aviso(e.message || 'Falha ao consultar o roteador.');
                $('dotLive').classList.add('off');
            }
        } finally {
            busy = false;
            desenhar();
        }
    }

    function filtro(rows) {
        const q = $('busca').value.trim().toLowerCase();
        if (!q) return rows;
        return rows.filter(r => [r.ip, r.mac, r.cpf, r.user, r.comment].join(' ').toLowerCase().includes(q));
    }

    function cabecalho(cols) {
        const t = $('tabela'); t.replaceChildren();
        const tr = el('tr'); cols.forEach(c => tr.append(el('th', '', c)));
        const th = el('thead'); th.append(tr); t.append(th);
        const tb = el('tbody'); t.append(tb);
        return tb;
    }

    function desenhar() {
        $('cntAtivos').textContent = '(' + ativos.length + ')';
        $('cntBloq').textContent = '(' + bloqueados.length + ')';
        $('subtitulo').textContent = ativos.length + ' cliente' + (ativos.length !== 1 ? 's' : '') + ' conectado' + (ativos.length !== 1 ? 's' : '');
        let n = 0, tb;

        if (aba === 'ativos') {
            tb = cabecalho(['CPF', 'IP', 'MAC', 'Conectado ha', 'Ocioso', 'Upload', 'Download', PODE_AGIR ? 'Acoes' : '']);
            filtro(ativos).forEach(r => {
                n++;
                const tr = el('tr');
                tr.append(el('td', '', r.cpf || '—'), el('td', '', r.ip), el('td', '', r.mac), el('td', '', r.uptime || '—'));
                tr.append(el('td', durS(r.idle) >= IDLE_ALERTA_S ? 'idle-alto' : '', r.idle || '—'));
                tr.append(el('td', '', fmtBytes(r.up)), el('td', '', fmtBytes(r.down)));
                const td = el('td');
                if (PODE_AGIR) {
                    const d = el('button', 'act-btn act-warn mr-2', 'Desconectar');
                    d.onclick = () => acao('disconnect', r, 'Desconectar ' + r.ip + '? O cliente precisara fazer login novamente.');
                    const b = el('button', 'act-btn act-danger', 'Bloquear');
                    b.onclick = () => acao('block', r, 'Bloquear ' + r.mac + '? O dispositivo sera desconectado e nao conseguira reconectar ate ser desbloqueado.');
                    td.append(d, b);
                }
                tr.append(td); tb.append(tr);
            });
        } else if (aba === 'bloqueados') {
            tb = cabecalho(['CPF', 'MAC', 'Observacao', PODE_AGIR ? 'Acoes' : '']);
            filtro(bloqueados).forEach(r => {
                n++;
                const tr = el('tr');
                tr.append(el('td', '', r.cpf || '—'), el('td', '', r.mac), el('td', '', r.comment || '—'));
                const td = el('td');
                if (PODE_AGIR) {
                    const u = el('button', 'act-btn act-ok', 'Desbloquear');
                    u.onclick = () => acao('unblock', r, 'Desbloquear ' + r.mac + '?');
                    td.append(u);
                }
                tr.append(td); tb.append(tr);
            });
        } else {
            tb = cabecalho(['Data', 'Administrador', 'Acao', 'Alvo', 'Detalhes']);
            auditoria.forEach(r => {
                n++;
                const tr = el('tr');
                tr.append(el('td', '', new Date(r.created_at.replace(' ', 'T')).toLocaleString('pt-BR')),
                          el('td', '', r.admin_user), el('td', '', r.acao.replace(/_/g, ' ')),
                          el('td', '', r.alvo || '—'), el('td', '', r.detalhes || '—'));
                tb.append(tr);
            });
        }
        const v = $('vazio');
        v.classList.toggle('hidden', n > 0);
        v.textContent = aba === 'ativos' ? 'Nenhum cliente conectado.' : aba === 'bloqueados' ? 'Nenhum dispositivo bloqueado.' : 'Nenhuma acao registrada.';
    }

    async function acao(nome, r, pergunta) {
        if (!confirm(pergunta)) return;
        try {
            const res = await api(nome, { id: r.id });
            aviso(res.success ? '' : res.message);
            if (res.success) { toast(res.message); }
        } catch (e) { aviso('Erro de comunicacao.'); }
        carregar();
    }

    function toast(msg) {
        const t = el('div', '', msg);
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:60;padding:12px 18px;border-radius:12px;font-weight:700;font-size:14px;color:#fff;background:rgba(22,163,74,.9);box-shadow:0 8px 30px rgba(0,0,0,.4)';
        document.body.append(t); setTimeout(() => t.remove(), 3000);
    }

    document.querySelectorAll('.tab-btn').forEach(b => b.onclick = () => {
        document.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
        b.classList.add('active'); aba = b.dataset.tab;
        carregar(); desenhar();
    });
    $('busca').oninput = desenhar;
    if ($('btnTodos')) $('btnTodos').onclick = async () => {
        if (!ativos.length) return;
        if (!confirm('Desconectar TODOS os ' + ativos.length + ' clientes agora? Todos precisarao fazer login novamente.')) return;
        const res = await api('disconnect_all', {});
        aviso(res.success ? '' : res.message);
        if (res.success) toast(res.message);
        carregar();
    };

    carregar();
    setInterval(carregar, 5000);
    document.addEventListener('visibilitychange', carregar);
    </script>
</body>
</html>
