<?php
// =============================================================
// Clientes por plano (SGP): lista nome, telefones e endereco de quem tem os planos escolhidos.
// O SGP so informa o plano no consultacliente (um cliente por chamada), entao a tela
// busca em lotes pequenos e o navegador monta a planilha (CSV).
// =============================================================
require_once 'session.php';
require_once 'db.php';
require_once 'schema.php';

const PLANOS_LOTE        = 24; // clientes por requisicao
const PLANOS_PARALELO    = 4;  // chamadas simultaneas ao SGP

$ehApi = isset($_GET['action']);
admin_require_login($ehApi);
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function planos_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/\s+/', ' ', $s);
}

function planos_sgp_post(string $rota, array $campos, int $timeout = 40): ?array
{
    if (!defined('SGP_BASE_URL') || !defined('SGP_TOKEN') || SGP_TOKEN === '') {
        return null;
    }
    $ch = curl_init(rtrim(SGP_BASE_URL, '/') . $rota);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => array_merge(['app' => defined('SGP_APP') ? SGP_APP : 'SGP', 'token' => SGP_TOKEN], $campos),
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = $resp === false ? null : json_decode($resp, true);
    return ($http === 200 && is_array($d)) ? $d : null;
}

function planos_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Contratos de um cliente que estao em algum dos planos pedidos -> linhas da planilha
function planos_linhas(array $resp, array $planos, bool $soAtivos): array
{
    $linhas = [];
    foreach ($resp['contratos'] ?? [] as $c) {
        $plano = trim((string) ($c['planointernet'] ?? ''));
        if ($plano === '' || !in_array(planos_norm($plano), $planos, true)) {
            continue;
        }
        $status = trim((string) ($c['contratoStatusDisplay'] ?? ''));
        if ($soAtivos && planos_norm($status) !== 'ativo') {
            continue;
        }
        $fones = [];
        foreach ($c['telefones'] ?? [] as $t) {
            $n = trim((string) ($t['contato'] ?? ''));
            if ($n !== '' && !in_array($n, $fones, true)) {
                $fones[] = $n;
            }
        }
        $linhas[] = [
            'contrato'    => (int) ($c['contratoId'] ?? 0),
            'nome'        => trim((string) ($c['razaoSocial'] ?? '')),
            'telefones'   => implode(' / ', $fones),
            'logradouro'  => trim((string) ($c['endereco_logradouro'] ?? '')),
            'numero'      => trim((string) ($c['endereco_numero'] ?? '')),
            'complemento' => trim((string) ($c['endereco_complemento'] ?? '')),
            'bairro'      => trim((string) ($c['endereco_bairro'] ?? '')),
            'cidade'      => trim((string) ($c['endereco_cidade'] ?? '')),
            'uf'          => trim((string) ($c['endereco_uf'] ?? '')),
            'cep'         => trim((string) ($c['endereco_cep'] ?? '')),
            'plano'       => $plano,
            'status'      => $status,
        ];
    }
    return $linhas;
}

if ($ehApi) {
    header('Cache-Control: no-store');
    $action = $_GET['action'];

    // Todos os clientes (nome + CPF) numa chamada so
    if ($action === 'clientes') {
        $d = planos_sgp_post('/api/ura/listacliente/', [], 90);
        if ($d === null) {
            planos_out(['success' => false, 'message' => 'Não foi possível consultar o SGP (veja SGP_BASE_URL/SGP_TOKEN no config.php).'], 502);
        }
        $cpfs = [];
        foreach ($d as $c) {
            $cpf = preg_replace('/\D/', '', (string) ($c['cpfcnpj'] ?? ''));
            if ($cpf !== '') {
                $cpfs[$cpf] = true;
            }
        }
        planos_out(['success' => true, 'cpfs' => array_keys($cpfs), 'lote' => PLANOS_LOTE]);
    }

    // Um lote de CPFs: consulta cada um no SGP (algumas chamadas ao mesmo tempo) e filtra pelos planos
    if ($action === 'lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entrada = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($entrada) || !hash_equals($_SESSION['csrf'], (string) ($entrada['csrf'] ?? ''))) {
            planos_out(['success' => false, 'message' => 'Sessão inválida. Recarregue a página.'], 403);
        }
        if (!defined('SGP_BASE_URL') || !defined('SGP_TOKEN') || SGP_TOKEN === '') {
            planos_out(['success' => false, 'message' => 'SGP não configurado no config.php.'], 500);
        }
        $cpfs   = array_slice(array_values(array_filter(array_map(fn($c) => preg_replace('/\D/', '', (string) $c), (array) ($entrada['cpfs'] ?? [])))), 0, PLANOS_LOTE);
        $planos = array_values(array_filter(array_map(fn($p) => planos_norm((string) $p), (array) ($entrada['planos'] ?? []))));
        $soAtivos = !empty($entrada['so_ativos']);
        if (!$planos) {
            planos_out(['success' => false, 'message' => 'Informe ao menos um plano.'], 400);
        }

        $mh = curl_multi_init();
        $pendentes = $cpfs;
        $ativos = [];       // (int) handle => cpf
        $linhas = [];
        $falhas = [];
        $abrir = function () use (&$pendentes, &$ativos, $mh) {
            while ($pendentes && count($ativos) < PLANOS_PARALELO) {
                $cpf = array_shift($pendentes);
                $ch = curl_init(rtrim(SGP_BASE_URL, '/') . '/api/ura/consultacliente/');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT        => 40,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => ['app' => defined('SGP_APP') ? SGP_APP : 'SGP', 'token' => SGP_TOKEN, 'cpfcnpj' => $cpf],
                ]);
                curl_multi_add_handle($mh, $ch);
                $ativos[(int) $ch] = [$cpf, $ch];
            }
        };
        $abrir();
        while ($ativos) {
            curl_multi_exec($mh, $rodando);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [$cpf] = $ativos[(int) $ch];
                $resp = curl_multi_getcontent($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $d = ($info['result'] === CURLE_OK && $http === 200) ? json_decode((string) $resp, true) : null;
                if (is_array($d)) {
                    array_push($linhas, ...planos_linhas($d, $planos, $soAtivos));
                } else {
                    $falhas[] = $cpf;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($ativos[(int) $ch]);
                $abrir();
            }
            if ($ativos) {
                curl_multi_select($mh, 1.0);
            }
        }
        curl_multi_close($mh);
        planos_out(['success' => true, 'linhas' => $linhas, 'falhas' => $falhas]);
    }

    // Registro na auditoria quando a planilha e baixada
    if ($action === 'log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entrada = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($entrada) || !hash_equals($_SESSION['csrf'], (string) ($entrada['csrf'] ?? ''))) {
            planos_out(['success' => false], 403);
        }
        $pdo = getDB();
        ensure_schema($pdo);
        admin_log($pdo, 'planos_sgp_export', 'planos SGP', mb_substr((int) ($entrada['n'] ?? 0) . ' linhas: ' . implode(', ', array_map('strval', (array) ($entrada['planos'] ?? []))), 0, 250));
        planos_out(['success' => true]);
    }

    planos_out(['success' => false, 'message' => 'Ação inválida.'], 400);
}

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$planosPadrao = ['50MEGA Rural VAN', 'APUAREMA 50MEGA', 'Fibra 50 Mega', 'PLANO 50MEGA ZONA RURAL'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes por plano — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .pl-input { background: var(--w08); border: 1px solid var(--w15); color: var(--text); }
        .pl-input:focus { outline: none; border-color: #ff4c30; }
        .pl-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 18px; border-radius: 12px;
            font-size: 13px; font-weight: 700; cursor: pointer; background: rgba(255,76,48,0.25); border: 1px solid rgba(255,76,48,0.5); color: var(--text); }
        .pl-btn:hover { background: rgba(255,76,48,0.4); }
        .pl-btn[disabled] { opacity: .5; cursor: not-allowed; }
        .pl-bar { height: 8px; border-radius: 999px; background: var(--w10); overflow: hidden; }
        .pl-bar > div { height: 100%; width: 0; background: #ff4c30; transition: width .2s; }
        .pl-muted { color: var(--w50); font-size: 12px; }
        .pl-tab { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pl-tab th { text-align: left; padding: 8px 10px; color: var(--w70); border-bottom: 1px solid var(--w15); position: sticky; top: 0; background: var(--w10); }
        .pl-tab td { padding: 7px 10px; border-bottom: 1px solid var(--w08); color: var(--text); vertical-align: top; }
        .pl-scroll { max-height: 60vh; overflow: auto; border-radius: 12px; }
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
            <a href="planos_sgp.php" class="nav-item active"><i class="fa-solid fa-table-list w-5 text-center"></i><span>Clientes por plano</span></a>
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
                    <h1 class="text-white font-bold text-lg leading-none">Clientes por plano</h1>
                    <p class="text-xs opacity-50 text-white">Consulta o SGP e gera a planilha com nome, telefones e endereço</p>
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
            <label class="text-white text-sm font-semibold" for="planos">Planos (um por linha)</label>
            <textarea id="planos" rows="5" class="pl-input w-full mt-2 p-3 rounded-xl text-sm"><?= $h(implode("\n", $planosPadrao)) ?></textarea>
            <p class="pl-muted mt-1">O nome precisa ser igual ao do SGP (maiúsculas, acentos e espaços extras não importam).</p>
            <label class="flex items-center gap-2 mt-3 text-white text-sm">
                <input type="checkbox" id="soAtivos" checked> Somente contratos ativos
            </label>
            <div class="flex flex-wrap items-center gap-3 mt-4">
                <button id="btnBuscar" class="pl-btn"><i class="fa-solid fa-magnifying-glass"></i> Buscar no SGP</button>
                <button id="btnCsv" class="pl-btn" disabled><i class="fa-solid fa-file-csv"></i> Baixar planilha (CSV)</button>
                <span id="status" class="pl-muted"></span>
            </div>
            <div class="pl-bar mt-4" id="barra" style="display:none"><div id="barraFill"></div></div>
        </section>

        <section class="glass-card rounded-2xl p-5 mt-6" id="resultado" style="display:none">
            <p class="text-white text-sm font-semibold mb-3" id="resumo"></p>
            <div class="pl-scroll">
                <table class="pl-tab">
                    <thead><tr>
                        <th>Contrato</th><th>Nome</th><th>Telefones</th><th>Endereço</th><th>Plano</th><th>Status</th>
                    </tr></thead>
                    <tbody id="corpo"></tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
    const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
    const $ = id => document.getElementById(id);
    let linhas = [], planosUsados = [];

    async function api(action, corpo) {
        const r = await fetch('planos_sgp.php?action=' + action, corpo
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: CSRF, ...corpo }) }
            : {});
        const d = await r.json().catch(() => ({ success: false, message: 'Resposta inválida (HTTP ' + r.status + ').' }));
        if (!d.success) throw new Error(d.message || 'Erro');
        return d;
    }
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const endereco = l => [l.logradouro + (l.numero ? ', ' + l.numero : ''), l.complemento, l.bairro, l.cidade + (l.uf ? '/' + l.uf : ''), l.cep && 'CEP ' + l.cep].filter(x => x && x.trim()).join(' - ');

    function desenhar() {
        $('corpo').innerHTML = linhas.map(l => `<tr><td>${l.contrato}</td><td>${esc(l.nome)}</td><td>${esc(l.telefones)}</td><td>${esc(endereco(l))}</td><td>${esc(l.plano)}</td><td>${esc(l.status)}</td></tr>`).join('');
    }

    $('btnBuscar').onclick = async () => {
        planosUsados = $('planos').value.split('\n').map(s => s.trim()).filter(Boolean);
        if (!planosUsados.length) { $('status').textContent = 'Informe ao menos um plano.'; return; }
        linhas = []; desenhar();
        $('btnBuscar').disabled = true; $('btnCsv').disabled = true;
        $('resultado').style.display = 'none'; $('barra').style.display = 'block'; $('barraFill').style.width = '0';
        const falhas = [];
        try {
            $('status').textContent = 'Listando clientes no SGP…';
            const { cpfs, lote } = await api('clientes');
            for (let i = 0; i < cpfs.length; i += lote) {
                const parte = cpfs.slice(i, i + lote);
                const d = await api('lote', { cpfs: parte, planos: planosUsados, so_ativos: $('soAtivos').checked });
                linhas.push(...d.linhas); falhas.push(...d.falhas);
                const feitos = Math.min(i + lote, cpfs.length);
                $('barraFill').style.width = (feitos / cpfs.length * 100) + '%';
                $('status').textContent = `Consultando clientes: ${feitos} de ${cpfs.length} — ${linhas.length} encontrado(s)`;
            }
            // Repete uma vez o que falhou
            if (falhas.length) {
                $('status').textContent = `Repetindo ${falhas.length} consulta(s) que falharam…`;
                const de = falhas.splice(0);
                for (let i = 0; i < de.length; i += lote) {
                    const d = await api('lote', { cpfs: de.slice(i, i + lote), planos: planosUsados, so_ativos: $('soAtivos').checked });
                    linhas.push(...d.linhas); falhas.push(...d.falhas);
                }
            }
            linhas.sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
            desenhar();
            $('resultado').style.display = 'block';
            $('resumo').textContent = `${linhas.length} contrato(s) encontrado(s)` + (falhas.length ? ` — ATENÇÃO: ${falhas.length} cliente(s) não puderam ser consultados, rode de novo.` : '.');
            $('status').textContent = 'Concluído.';
            $('btnCsv').disabled = linhas.length === 0;
        } catch (e) {
            $('status').textContent = 'Erro: ' + e.message;
        } finally {
            $('btnBuscar').disabled = false;
        }
    };

    $('btnCsv').onclick = () => {
        const cab = ['Contrato', 'Nome', 'Telefones', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Cidade', 'UF', 'CEP', 'Plano', 'Status'];
        const cel = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
        const csv = [cab, ...linhas.map(l => [l.contrato, l.nome, l.telefones, l.logradouro, l.numero, l.complemento, l.bairro, l.cidade, l.uf, l.cep, l.plano, l.status])]
            .map(r => r.map(cel).join(';')).join('\r\n');
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'clientes-50mega-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        api('log', { n: linhas.length, planos: planosUsados }).catch(() => {});
    };
    </script>
</body>
</html>
