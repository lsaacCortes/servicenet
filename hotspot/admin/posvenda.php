<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';
require_once 'posvenda_lib.php';
$pdo = getDB();
ensure_schema($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$souAdmin  = admin_perfil($pdo) === 'admin';
$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$atendente = trim(explode(' ', trim($adminNome))[0]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pós-venda — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
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
        .tab-btn.active .cnt { background: var(--w25); }
        .tab-btn.tab-prio .cnt { background: rgba(255,76,48,0.35); color: var(--text); }
        .search-input { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .search-input::placeholder { color: var(--w35); }
        .search-input:focus { outline: none; border-color: #ff4c30; }

        .pv-row {
            display: grid; grid-template-columns: minmax(0,2.2fr) minmax(0,1.3fr) minmax(0,1.5fr) auto auto;
            align-items: center; gap: 16px; padding: 14px 18px; position: relative;
            background: var(--w06); border: 1px solid var(--w10);
            border-radius: 16px; transition: border-color .2s, background .2s;
        }
        .pv-row:hover { background: var(--w09); border-color: var(--w20); }
        .pv-row.prio { border-left: 4px solid #ff4c30; background: linear-gradient(90deg, rgba(255,76,48,0.10), var(--w05) 40%); }
        .pv-avatar { width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; background: linear-gradient(135deg, #172a4c, #ff4c30); }
        .pv-muted { color: var(--w50); font-size: 12px; }
        .pv-bar { height: 4px; border-radius: 4px; background: var(--w12); overflow: hidden; margin-top: 6px; max-width: 140px; }
        .pv-bar > span { display: block; height: 100%; background: linear-gradient(90deg, #60a5fa, #ff4c30); }

        .badge-aguardando   { background: rgba(96,165,250,0.18); color: #bfdbfe; border: 1px solid rgba(96,165,250,0.4); }
        .badge-pendente     { background: rgba(251,191,36,0.2);  color: var(--yellow-t); border: 1px solid rgba(251,191,36,0.4); }
        .badge-sem_resposta { background: rgba(148,163,184,0.2); color: #cbd5e1; border: 1px solid rgba(148,163,184,0.4); }
        .badge-concluido    { background: rgba(34,197,94,0.2);   color: var(--green-t); border: 1px solid rgba(34,197,94,0.4); }
        .tag-prio { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
            background: #ff4c30; color: #fff; letter-spacing: .03em; }

        .pv-act { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 12px;
            font-size: 12px; font-weight: 700; transition: all .2s; white-space: nowrap; cursor: pointer; }
        .pv-wa { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: var(--green); }
        .pv-wa:hover { background: rgba(34,197,94,0.28); }
        .pv-wa.off { opacity: .35; pointer-events: none; }
        .pv-reg { background: var(--w10); border: 1px solid var(--w18); color: var(--text); }
        .pv-reg:hover { background: var(--w18); }

        .page-btn { background: var(--w08); border: 1px solid var(--w15); color: var(--text); transition: all .2s; }
        .page-btn:hover, .page-btn.active { background: rgba(255,76,48,0.35); border-color: #ff4c30; }
        .page-btn:disabled { opacity: .3; cursor: not-allowed; }

        .modal-backdrop { background: var(--backdrop); backdrop-filter: blur(8px); }
        .modal-box { background: var(--surface); border: 1px solid var(--w15); box-shadow: 0 25px 60px rgba(0,0,0,0.6); }
        .modal-input { background: var(--w08); border: 1px solid var(--w20); color: var(--text); }
        .modal-input:focus { outline: none; border-color: #ff4c30; }
        .modal-input::placeholder { color: var(--w30); }
        select.modal-input option { background: var(--option-bg); color: var(--text); }
        .lbl { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--w70); }

        .skeleton { height: 70px; border-radius: 16px; background: linear-gradient(90deg, var(--w05), var(--w10), var(--w05)); background-size: 200% 100%; animation: sk 1.2s infinite; }
        @keyframes sk { to { background-position: -200% 0; } }
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 1024px) {
            .pv-row { grid-template-columns: 1fr auto; }
            .pv-row .c-contato, .pv-row .c-ativ { grid-column: 1 / -1; }
            .pv-row .c-status { justify-self: end; }
            .pv-row .c-acoes { grid-column: 1 / -1; display: flex; gap: 8px; }
            .pv-row .c-acoes .pv-act { flex: 1; }
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
            <a href="posvenda.php" class="nav-item active"><i class="fa-solid fa-headset w-5 text-center"></i><span>Pós-venda</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Pós-venda</h1>
                    <p id="subtitulo" class="text-xs opacity-50 text-white">Carregando...</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-white text-sm font-semibold"><?= htmlspecialchars($adminNome) ?></p>
                    <p class="text-xs opacity-50 text-white"><?= date('d/m/Y') ?></p>
                </div>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white" style="background: rgba(255,76,48,0.8);">
                    <?= htmlspecialchars(strtoupper(substr($adminNome, 0, 1))) ?>
                </div>
            </div>
        </header>

        <!-- Busca e sincronizacao -->
        <div class="glass-card rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-48 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 opacity-40 text-white text-sm"></i>
                <input id="busca" type="search" placeholder="Buscar por nome, telefone, bairro ou CPF..." autocomplete="off"
                       class="search-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm">
            </div>
            <span id="syncInfo" class="pv-muted hidden md:inline"></span>
            <button id="btnSync" class="pv-act pv-reg"><i class="fa-solid fa-arrows-rotate"></i> Sincronizar</button>
            <button id="btnConfig" class="pv-act pv-reg" title="Configurar data de início" aria-label="Configurar"><i class="fa-solid fa-gear"></i></button>
        </div>

        <!-- Filtros -->
        <div id="tabs" class="flex flex-wrap gap-2 mt-4" role="tablist">
            <button class="tab-btn active px-4 py-2 rounded-xl text-sm font-semibold" data-f="abertos">Em aberto<span class="cnt" data-c="abertos">0</span></button>
            <button class="tab-btn tab-prio px-4 py-2 rounded-xl text-sm font-semibold" data-f="prioridade"><i class="fa-solid fa-flag text-xs"></i> Prioridade<span class="cnt" data-c="prioridade">0</span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-f="aguardando">Aguardando<span class="cnt" data-c="aguardando">0</span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-f="em_contato">Em contato<span class="cnt" data-c="em_contato">0</span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-f="sem_resposta">Sem resposta<span class="cnt" data-c="sem_resposta">0</span></button>
            <button class="tab-btn px-4 py-2 rounded-xl text-sm font-semibold" data-f="concluidos">Concluídos<span class="cnt" data-c="concluidos">0</span></button>
        </div>

        <div id="lista" class="flex flex-col gap-3 mt-5"></div>
        <div id="paginacao" class="flex justify-center flex-wrap gap-2 mt-8 mb-4"></div>
    </main>

    <!-- Registrar atendimento -->
    <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
        <div class="modal-box rounded-2xl w-full max-w-md p-6 relative" role="dialog" aria-modal="true" aria-labelledby="mNome">
            <button id="mFechar" class="absolute right-4 top-4 text-white opacity-50 hover:opacity-100 text-2xl leading-none" aria-label="Fechar">&times;</button>
            <h3 class="text-white font-bold text-lg mb-1">Registrar atendimento</h3>
            <p id="mNome" class="text-sm font-semibold text-white"></p>
            <p id="mInfo" class="pv-muted mb-5"></p>
            <div class="space-y-4">
                <div>
                    <label class="lbl" for="mStatus">Status</label>
                    <select id="mStatus" class="modal-input w-full py-2.5 px-3 rounded-xl text-sm">
                        <option value="pendente">Pendente</option>
                        <option value="em_contato">Em contato</option>
                        <option value="sem_resposta">Sem resposta</option>
                        <option value="concluido">Concluído</option>
                    </select>
                </div>
                <div id="mResWrap" class="hidden">
                    <label class="lbl" for="mRes">Resultado do atendimento</label>
                    <select id="mRes" class="modal-input w-full py-2.5 px-3 rounded-xl text-sm">
                        <option value="">Selecione...</option>
                        <option value="satisfeito">Cliente satisfeito</option>
                        <option value="problema_resolvido">Teve problema, resolvido</option>
                        <option value="precisa_visita">Precisa de visita técnica</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
                <div>
                    <label class="lbl" for="mNotas">Anotações</label>
                    <textarea id="mNotas" rows="4" maxlength="2000" placeholder="O que o cliente disse? Alguma pendência?"
                              class="modal-input w-full py-2.5 px-3 rounded-xl text-sm resize-none"></textarea>
                </div>
            </div>
            <p id="mErro" class="text-xs font-semibold mt-3 hidden" style="color:var(--coral);"></p>
            <div class="flex gap-3 mt-6">
                <button id="mCancel" class="sn-btn sn-btn-ghost">Cancelar</button>
                <button id="mSalvar" class="sn-btn sn-btn-primary">Salvar</button>
            </div>
        </div>
    </div>

    <!-- Configuracao -->
    <div id="modalCfg" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
        <div class="modal-box rounded-2xl w-full max-w-sm p-6 relative" role="dialog" aria-modal="true">
            <button id="cFechar" class="absolute right-4 top-4 text-white opacity-50 hover:opacity-100 text-2xl leading-none" aria-label="Fechar">&times;</button>
            <h3 class="text-white font-bold text-lg mb-1">Configuração</h3>
            <p class="pv-muted mb-5">Só contratos ativados a partir desta data entram na lista de pós-venda.</p>
            <label class="lbl" for="cInicio">Considerar ativações desde</label>
            <input id="cInicio" type="date" max="<?= date('Y-m-d') ?>" class="modal-input w-full py-2.5 px-3 rounded-xl text-sm" <?= $souAdmin ? '' : 'disabled' ?>>
            <?php if (!$souAdmin): ?><p class="pv-muted mt-2">Seu perfil é somente de visualização.</p><?php endif; ?>
            <div class="flex gap-3 mt-6">
                <button id="cCancel" class="sn-btn sn-btn-ghost">Fechar</button>
                <?php if ($souAdmin): ?><button id="cSalvar" class="sn-btn sn-btn-primary">Salvar e sincronizar</button><?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
    const ATENDENTE = <?= json_encode($atendente) ?>;
    const DIAS_ALVO = <?= POSVENDA_DIAS_ALVO ?>;
    const $ = id => document.getElementById(id);
    const RESULTADOS = { satisfeito: 'Cliente satisfeito', problema_resolvido: 'Problema resolvido', precisa_visita: 'Precisa de visita', outro: 'Outro' };
    const VAZIOS = {
        abertos: 'Nenhum atendimento em aberto.', prioridade: 'Nenhum cliente prioritário no momento.',
        aguardando: 'Nenhum cliente aguardando os 7 dias.', em_contato: 'Ninguém em contato agora.',
        sem_resposta: 'Nenhum cliente sem resposta.', concluidos: 'Nenhum atendimento concluído ainda.'
    };

    let filtro = 'abertos', q = '', page = 1, dados = [], editando = null, carregando = false;

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }
    function dataBR(s, comHora) {
        if (!s) return '—';
        const [d, h] = s.split(' ');
        const [y, m, dd] = d.split('-');
        return dd + '/' + m + '/' + y + (comHora && h ? ' ' + h.slice(0, 5) : '');
    }
    function foneWhats(t) {
        let n = (t || '').replace(/\D/g, '');
        if (n.length < 10) return '';
        if (!(n.startsWith('55') && n.length >= 12)) n = '55' + n;
        return n;
    }
    function mensagem(r) {
        const nome = (r.nome || '').trim().split(/\s+/)[0];
        const cap = nome.charAt(0).toUpperCase() + nome.slice(1).toLowerCase();
        return 'Olá, ' + cap + '! Aqui é ' + ATENDENTE + ', da ServiceNet. 😊\n\n' +
            'Já faz cerca de uma semana que sua internet foi ativada e queremos saber: está tudo certo com a sua conexão? ' +
            'Podemos ajudar em alguma coisa?\n\nSua opinião é muito importante para nós!';
    }
    function rotulo(r) {
        if (r.status === 'pendente') return r.dias < DIAS_ALVO ? ['aguardando', 'Aguardando'] : ['pendente', 'Pendente'];
        return [r.status, { em_contato: 'Em contato', sem_resposta: 'Sem resposta', concluido: 'Concluído' }[r.status]];
    }

    async function api(action, body) {
        const opt = body ? { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: new URLSearchParams({ action, ...body }) } : {};
        const url = 'posvenda_api.php?' + (body ? 'action=' + action : new URLSearchParams({ action, filtro, q, page }));
        const res = await fetch(url, opt);
        if (res.status === 401) { location.href = 'login.php'; throw new Error('sessao'); }
        return res.json();
    }

    async function carregar(silencioso) {
        if (carregando) return;
        carregando = true;
        if (!silencioso) {
            const l = $('lista'); l.replaceChildren();
            for (let i = 0; i < 4; i++) l.append(el('div', 'skeleton'));
        }
        try {
            const r = await api('list');
            if (!r.success) throw new Error(r.message);
            dados = r.rows; page = r.page;
            document.querySelectorAll('[data-c]').forEach(s => s.textContent = r.counts[s.dataset.c] ?? 0);
            $('subtitulo').textContent = r.counts.abertos + ' em acompanhamento · ' + r.counts.prioridade + ' prioritário' + (r.counts.prioridade === 1 ? '' : 's');
            $('cInicio').value = r.inicio;
            $('syncInfo').textContent = r.ultima_sync ? 'Atualizado ' + new Date(r.ultima_sync * 1000).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'Nunca sincronizado';
            desenhar(); paginar(r.pages);
        } catch (e) {
            if (e.message === 'sessao') return;
            $('lista').replaceChildren(vazio('fa-triangle-exclamation', 'Não foi possível carregar a lista.', 'Tente novamente em instantes.'));
        } finally { carregando = false; }
    }

    function vazio(icone, titulo, sub) {
        const d = el('div', 'text-center py-16');
        const i = el('i', 'fa-solid ' + icone + ' text-white opacity-30 text-5xl mb-4 block');
        d.append(i, el('p', 'text-white text-lg font-bold opacity-60', titulo));
        if (sub) d.append(el('p', 'text-white opacity-30 text-sm mt-2', sub));
        return d;
    }

    function desenhar() {
        const l = $('lista'); l.replaceChildren();
        if (!dados.length) {
            l.append(q ? vazio('fa-magnifying-glass', 'Nada encontrado.', 'Tente outro termo de busca.')
                       : vazio('fa-circle-check', VAZIOS[filtro], filtro === 'abertos' ? 'Use "Sincronizar" para buscar novos contratos no SGP.' : ''));
            return;
        }
        dados.forEach(r => l.append(linha(r)));
    }

    function linha(r) {
        const row = el('div', 'pv-row' + (r.prioridade ? ' prio' : ''));

        const c1 = el('div', 'flex items-center gap-3 min-w-0');
        c1.append(el('div', 'pv-avatar', (r.nome || '?').charAt(0).toUpperCase()));
        const nm = el('div', 'min-w-0');
        const linhaNome = el('div', 'flex items-center gap-2 flex-wrap');
        linhaNome.append(el('span', 'text-white font-bold text-sm truncate', r.nome));
        if (r.prioridade) { const t = el('span', 'tag-prio'); t.append(el('i', 'fa-solid fa-flag'), document.createTextNode('Prioridade')); linhaNome.append(t); }
        if (r.status_contrato && r.status_contrato.toLowerCase() !== 'ativo') linhaNome.append(el('span', 'badge badge-sem_resposta', 'Contrato ' + r.status_contrato.toLowerCase()));
        nm.append(linhaNome, el('p', 'pv-muted truncate', [r.bairro, r.cidade].filter(Boolean).join(' · ') || 'Endereço não informado'));
        c1.append(nm);

        const c2 = el('div', 'c-contato min-w-0');
        c2.append(el('p', 'text-sm text-white', r.telefone || 'Sem telefone'));
        c2.append(el('p', 'pv-muted truncate', r.ultimo_contato_em ? 'Último contato: ' + dataBR(r.ultimo_contato_em, true) + (r.ultimo_contato_por ? ' · ' + r.ultimo_contato_por : '') : 'Sem contato ainda'));

        const c3 = el('div', 'c-ativ');
        c3.append(el('p', 'text-sm text-white', 'Ativado em ' + dataBR(r.ativado_em)));
        if (r.status === 'concluido') {
            c3.append(el('p', 'pv-muted', (RESULTADOS[r.resultado] || 'Concluído') + ' · ' + dataBR(r.concluido_em)));
        } else if (r.dias >= DIAS_ALVO) {
            const p = el('p', 'text-xs font-bold', r.dias === DIAS_ALVO ? 'Completou 7 dias hoje' : r.dias + ' dias de ativação');
            p.style.color = 'var(--coral)'; c3.append(p);
        } else {
            c3.append(el('p', 'pv-muted', 'Dia ' + r.dias + ' de ' + DIAS_ALVO));
            const b = el('div', 'pv-bar'); const s = el('span'); s.style.width = Math.max(4, r.dias / DIAS_ALVO * 100) + '%'; b.append(s); c3.append(b);
        }

        const [cls, txt] = rotulo(r);
        const c4 = el('div', 'c-status'); c4.append(el('span', 'badge badge-' + cls, txt));

        const c5 = el('div', 'c-acoes flex gap-2');
        const num = foneWhats(r.telefone);
        const wa = el('a', 'pv-act pv-wa' + (num ? '' : ' off'));
        wa.append(el('i', 'fa-brands fa-whatsapp'), document.createTextNode('WhatsApp'));
        if (num) {
            wa.href = 'https://wa.me/' + num + '?text=' + encodeURIComponent(mensagem(r));
            wa.target = '_blank'; wa.rel = 'noopener';
            wa.addEventListener('click', () => marcarContato(r));
        }
        const reg = el('button', 'pv-act pv-reg'); reg.type = 'button';
        reg.append(el('i', 'fa-solid fa-pen-to-square'), document.createTextNode('Registrar'));
        reg.onclick = () => abrir(r);
        c5.append(wa, reg);

        row.append(c1, c2, c3, c4, c5);
        return row;
    }

    function paginar(total) {
        const p = $('paginacao'); p.replaceChildren();
        if (total <= 1) return;
        const mk = (txt, n, ativo, off) => {
            const b = el('button', 'page-btn px-4 py-2 rounded-xl text-sm font-bold' + (ativo ? ' active' : ''), txt);
            b.disabled = !!off; b.onclick = () => { page = n; carregar(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
            return b;
        };
        p.append(mk('Anterior', page - 1, false, page <= 1));
        for (let i = 1; i <= total; i++) p.append(mk(String(i), i, i === page));
        p.append(mk('Próximo', page + 1, false, page >= total));
    }

    async function marcarContato(r) {
        if (r.status !== 'pendente') return;
        try {
            const res = await api('contato', { id: r.id });
            if (res.alterado) { SN.toast('Marcado como "Em contato".', 'info'); carregar(true); }
        } catch (e) { /* o contato nao deve ser bloqueado por falha no registro */ }
    }

    // ---- Modal de atendimento ----
    function abrir(r) {
        editando = r;
        $('mNome').textContent = r.nome;
        $('mInfo').textContent = (r.telefone || 'Sem telefone') + ' · Ativado em ' + dataBR(r.ativado_em);
        $('mStatus').value = r.status;
        $('mRes').value = r.resultado || '';
        $('mNotas').value = r.anotacoes || '';
        $('mErro').classList.add('hidden');
        alternarResultado();
        mostrar('modal', true);
        $('mStatus').focus();
    }
    function mostrar(id, on) { $(id).classList.toggle('hidden', !on); $(id).classList.toggle('flex', on); }
    function alternarResultado() { $('mResWrap').classList.toggle('hidden', $('mStatus').value !== 'concluido'); }

    async function salvar() {
        const status = $('mStatus').value, res = $('mRes').value;
        const erro = $('mErro');
        if (status === 'concluido' && !res) {
            erro.textContent = 'Selecione o resultado do atendimento para concluir.';
            erro.classList.remove('hidden'); $('mRes').focus(); return;
        }
        if (status === 'concluido' && editando.status !== 'concluido') {
            const ok = await SN.confirm({ title: 'Concluir atendimento', message: 'O cliente sairá da lista em aberto e irá para "Concluídos".', confirmText: 'Concluir', tone: 'primary' });
            if (!ok) return;
        }
        const btn = $('mSalvar'); btn.disabled = true; btn.textContent = 'Salvando...';
        try {
            const r = await api('update', { id: editando.id, status, resultado: res, anotacoes: $('mNotas').value });
            if (r.success) { mostrar('modal', false); SN.toast(r.message); carregar(true); }
            else { erro.textContent = r.message || 'Erro ao salvar.'; erro.classList.remove('hidden'); }
        } catch (e) { if (e.message !== 'sessao') { erro.textContent = 'Erro de comunicação.'; erro.classList.remove('hidden'); } }
        btn.disabled = false; btn.textContent = 'Salvar';
    }

    // ---- Sincronizacao ----
    async function sincronizar(auto) {
        const btn = $('btnSync'), ic = btn.querySelector('i');
        btn.disabled = true; ic.classList.add('spin');
        try {
            const r = await api('sync', auto ? { auto: '1' } : {});
            if (!r.success) { if (!auto) SN.toast(r.message || 'Falha ao sincronizar.', 'error'); }
            else if (!auto || r.novos > 0) SN.toast(r.message, r.novos > 0 ? 'success' : 'info');
        } catch (e) { if (!auto && e.message !== 'sessao') SN.toast('Erro de comunicação.', 'error'); }
        btn.disabled = false; ic.classList.remove('spin');
        carregar(true);
    }

    // ---- Eventos ----
    document.querySelectorAll('#tabs .tab-btn').forEach(b => b.onclick = () => {
        document.querySelectorAll('#tabs .tab-btn').forEach(x => x.classList.remove('active'));
        b.classList.add('active'); filtro = b.dataset.f; page = 1; carregar();
    });
    let timer; $('busca').oninput = e => { clearTimeout(timer); timer = setTimeout(() => { q = e.target.value.trim(); page = 1; carregar(true); }, 300); };
    $('btnSync').onclick = () => sincronizar(false);
    $('btnConfig').onclick = () => mostrar('modalCfg', true);
    $('mStatus').onchange = alternarResultado;
    $('mSalvar').onclick = salvar;
    $('mCancel').onclick = $('mFechar').onclick = () => mostrar('modal', false);
    $('cCancel').onclick = $('cFechar').onclick = () => mostrar('modalCfg', false);
    if ($('cSalvar')) $('cSalvar').onclick = async () => {
        try {
            const r = await api('config', { inicio: $('cInicio').value });
            if (!r.success) { SN.toast(r.message, 'error'); return; }
            mostrar('modalCfg', false); SN.toast(r.message); sincronizar(false);
        } catch (e) { if (e.message !== 'sessao') SN.toast('Erro de comunicação.', 'error'); }
    };
    ['modal', 'modalCfg'].forEach(id => $(id).addEventListener('mousedown', e => { if (e.target === $(id)) mostrar(id, false); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { mostrar('modal', false); mostrar('modalCfg', false); } });

    carregar().then(() => sincronizar(true));
    </script>
    <script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/assets/ui.js') ?>" defer></script>
    <script src="assets/alertas.js" defer></script>
</body>
</html>
