<?php
require_once 'session.php';
admin_require_login();
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rede — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .tbl { width: 100%; border-collapse: collapse; color: rgba(255,255,255,0.85); font-size: 0.875rem; }
        .tbl th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,0.45); padding: .6rem .75rem; white-space: nowrap; }
        .tbl td { padding: .6rem .75rem; border-top: 1px solid rgba(255,255,255,0.08); white-space: nowrap; }
        .chart-box { position: relative; height: 260px; }
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
            <a href="rede.php" class="nav-item active"><i class="fa-solid fa-network-wired w-5 text-center"></i><span>Rede</span></a>
            <a href="leads.php" class="nav-item"><i class="fa-solid fa-users w-5 text-center"></i><span>Leads</span></a>
            <a href="posvenda.php" class="nav-item"><i class="fa-solid fa-headset w-5 text-center"></i><span>Pós-venda</span></a>
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
                <button onclick="document.getElementById('sidebar').classList.toggle('sidebar-open')" class="lg:hidden text-white opacity-70 hover:opacity-100">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-white font-bold text-lg leading-none">Dashboard da rede</h1>
                    <p class="text-xs opacity-50 text-white" id="ultAtualiz">Carregando...</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-white text-sm font-semibold hidden sm:block"><?= htmlspecialchars($adminNome) ?></p>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
            </div>
        </header>

        <div id="aviso" class="hidden mt-6 p-4 rounded-2xl text-sm font-semibold" style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.4); color: #ffb3a5;"></div>

        <div class="stats-grid">
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(59,130,246,0.2); color: #60a5fa;"><i class="fa-solid fa-wifi text-xl"></i></div>
                <div><p class="stat-label">Conectados agora</p><p class="stat-value" id="cConectados">—</p></div>
            </div>
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(251,191,36,0.2); color: #fbbf24;"><i class="fa-solid fa-hourglass-half text-xl"></i></div>
                <div><p class="stat-label">Ociosos há 3h ou mais</p><p class="stat-value" id="cOciosos">—</p></div>
            </div>
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(255,76,48,0.2); color: #ff7a61;"><i class="fa-solid fa-ban text-xl"></i></div>
                <div><p class="stat-label">Bloqueados</p><p class="stat-value" id="cBloqueados">—</p></div>
            </div>
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(34,197,94,0.2); color: #4ade80;"><i class="fa-solid fa-arrow-down text-xl"></i></div>
                <div><p class="stat-label">Download (sessões ativas)</p><p class="stat-value" id="cDown">—</p></div>
            </div>
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(168,85,247,0.2); color: #c084fc;"><i class="fa-solid fa-arrow-up text-xl"></i></div>
                <div><p class="stat-label">Upload (sessões ativas)</p><p class="stat-value" id="cUp">—</p></div>
            </div>
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(255,255,255,0.12); color: #fff;"><i class="fa-solid fa-right-to-bracket text-xl"></i></div>
                <div><p class="stat-label">Logins hoje</p><p class="stat-value"><span id="cLoginsHoje">—</span> <span class="text-xs opacity-50 font-normal" id="cUnicosHoje"></span></p></div>
            </div>
        </div>

        <div class="grid gap-6 mt-6 lg:grid-cols-2">
            <div class="glass-card rounded-2xl p-5">
                <h2 class="text-white font-bold text-sm mb-1">Logins por dia (últimos 14 dias)</h2>
                <p class="text-xs opacity-50 text-white mb-3">Cada acesso aprovado no portal; "pessoas" conta CPFs diferentes.</p>
                <div class="chart-box"><canvas id="gDia"></canvas></div>
            </div>
            <div class="glass-card rounded-2xl p-5">
                <h2 class="text-white font-bold text-sm mb-1">Logins por hora do dia (últimos 7 dias)</h2>
                <p class="text-xs opacity-50 text-white mb-3">Mostra os horários de maior movimento.</p>
                <div class="chart-box"><canvas id="gHora"></canvas></div>
            </div>
        </div>

        <div class="glass-card rounded-2xl mt-6 overflow-x-auto">
            <div class="p-5 pb-2"><h2 class="text-white font-bold text-sm">Maiores consumidores agora</h2>
                <p class="text-xs opacity-50 text-white">Top 10 pelo tráfego total da sessão atual.</p></div>
            <table class="tbl"><thead><tr><th>CPF</th><th>IP</th><th>MAC</th><th>Conectado há</th><th>Download</th><th>Upload</th></tr></thead>
                <tbody id="tbTop"></tbody></table>
            <p id="topVazio" class="hidden text-center text-white opacity-50 py-8"></p>
        </div>
    </main>

    <script>
    const $ = id => document.getElementById(id);
    let gDia, gHora, busy = false;

    function fmtBytes(n) {
        const u = ['B','KB','MB','GB','TB']; let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return n.toFixed(i ? 1 : 0) + ' ' + u[i];
    }
    function td(text) { const e = document.createElement('td'); e.textContent = text; return e; }

    const eixo = { ticks: { color: 'rgba(255,255,255,0.6)' }, grid: { color: 'rgba(255,255,255,0.08)' } };
    function opcoes(extra) {
        return Object.assign({
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: 'rgba(255,255,255,0.8)' } } },
            scales: { x: eixo, y: Object.assign({ beginAtZero: true, ticks: { color: 'rgba(255,255,255,0.6)', precision: 0 } }, { grid: eixo.grid }) }
        }, extra || {});
    }

    function graficos(l) {
        const dias = l.por_dia.map(x => x.dia);
        if (!gDia) {
            gDia = new Chart($('gDia'), { type: 'line', options: opcoes(), data: { labels: dias, datasets: [
                { label: 'Logins', data: [], borderColor: '#ff4c30', backgroundColor: 'rgba(255,76,48,0.15)', fill: true, tension: .3 },
                { label: 'Pessoas (CPFs)', data: [], borderColor: '#60a5fa', tension: .3 }
            ] } });
            gHora = new Chart($('gHora'), { type: 'bar', options: opcoes({ plugins: { legend: { display: false } } }), data: {
                labels: Array.from({ length: 24 }, (_, h) => h + 'h'),
                datasets: [{ label: 'Logins', data: [], backgroundColor: 'rgba(255,76,48,0.7)', borderRadius: 4 }] } });
        }
        gDia.data.labels = dias;
        gDia.data.datasets[0].data = l.por_dia.map(x => x.logins);
        gDia.data.datasets[1].data = l.por_dia.map(x => x.unicos);
        gDia.update();
        gHora.data.datasets[0].data = l.por_hora;
        gHora.update();
    }

    async function carregar() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const res = await fetch('rede_api.php', { credentials: 'same-origin' });
            if (res.status === 401) { location.href = 'login.php'; return; }
            const d = await res.json();
            if (!d.success) throw new Error(d.message);

            const a = $('aviso');
            a.textContent = d.router_error ? 'Roteador: ' + d.router_error + ' (os dados de logins abaixo continuam disponíveis)' : '';
            a.classList.toggle('hidden', !d.router_error);

            if (d.router) {
                const r = d.router;
                $('cConectados').textContent = r.conectados;
                $('cOciosos').textContent = r.ociosos;
                $('cBloqueados').textContent = r.bloqueados;
                $('cDown').textContent = fmtBytes(r.down);
                $('cUp').textContent = fmtBytes(r.up);
                const tb = $('tbTop'); tb.replaceChildren();
                r.top.forEach(t => {
                    const tr = document.createElement('tr');
                    tr.append(td(t.cpf || '—'), td(t.ip), td(t.mac), td(t.uptime || '—'), td(fmtBytes(t.down)), td(fmtBytes(t.up)));
                    tb.append(tr);
                });
                $('topVazio').textContent = 'Nenhum cliente conectado.';
                $('topVazio').classList.toggle('hidden', r.top.length > 0);
            }
            $('cLoginsHoje').textContent = d.logins.hoje;
            $('cUnicosHoje').textContent = '(' + d.logins.unicos_hoje + ' pessoas)';
            graficos(d.logins);
            $('ultAtualiz').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
        } catch (e) {
            $('aviso').textContent = e.message || 'Falha ao carregar.';
            $('aviso').classList.remove('hidden');
        } finally { busy = false; }
    }

    carregar();
    setInterval(carregar, 30000);
    document.addEventListener('visibilitychange', carregar);
    </script>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
