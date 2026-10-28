<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
$pdo = getDB();

// Buscar estatisticas
$totalLeads    = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$novos         = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='novo'")->fetchColumn();
$emContato     = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='em_contato'")->fetchColumn();
$convertidos   = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='convertido'")->fetchColumn();
$descartados   = $pdo->query("SELECT COUNT(*) FROM leads WHERE status='descartado'")->fetchColumn();

// Leads dos ultimos 7 dias (por dia)
$stmt7 = $pdo->query("
    SELECT DATE(created_at) as dia, COUNT(*) as total
    FROM leads
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY dia ASC
");
$dados7dias = $stmt7->fetchAll();

// Preencher dias sem leads com 0
$chartDias    = [];
$chartTotais  = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDias[]   = date('d/m', strtotime($d));
    $found = array_filter($dados7dias, fn($r) => $r['dia'] === $d);
    $chartTotais[] = $found ? (int)reset($found)['total'] : 0;
}

// Top bairros
$topBairros = $pdo->query("
    SELECT bairro, COUNT(*) as total
    FROM leads
    WHERE bairro IS NOT NULL AND bairro != '' AND bairro != 'Nao informado'
    GROUP BY bairro ORDER BY total DESC LIMIT 5
")->fetchAll();

// Leads recentes
$recentes = $pdo->query("
    SELECT * FROM leads ORDER BY created_at DESC LIMIT 5
")->fetchAll();

$taxaConversao = $totalLeads > 0 ? round(($convertidos / $totalLeads) * 100, 1) : 0;

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-body">

    <!-- Background orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo"><img src="../assets/img/logo.PNG" class="logo-bordered" alt="ServiceNet"
                 onerror="this.outerHTML='<span class=\'text-white font-extrabold text-lg\'>ServiceNet</span>'">
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="leads.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>
                </svg>
                <span>Leads</span>
            </a>
            <a href="rede.php" class="nav-item">
                <i class="fa-solid fa-network-wired w-5 text-center"></i>
                <span>Rede</span>
            </a>
            <a href="conectados.php" class="nav-item">
                <i class="fa-solid fa-wifi w-5 text-center"></i>
                <span>Conectados</span>
            </a>
            <a href="usuarios.php" class="nav-item">
                <i class="fa-solid fa-user-shield w-5 text-center"></i>
                <span>Administradores</span>
            </a>
            <a href="senha.php" class="nav-item">
                <i class="fa-solid fa-key w-5 text-center"></i>
                <span>Alterar senha</span>
            </a>
            <a href="auth.php?action=logout" class="nav-item mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/>
                </svg>
                <span>Sair</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <header class="topbar glass-card">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-white opacity-70 hover:opacity-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div>
                    <h1 class="text-white font-bold text-lg leading-none">Dashboard</h1>
                    <p class="text-xs opacity-50 text-white">Visao Geral de Leads</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-white text-sm font-semibold"><?= htmlspecialchars($adminNome) ?></p>
                    <p class="text-xs opacity-50 text-white"><?= date('d/m/Y') ?></p>
                </div>
                <a href="auth.php?action=logout" class="btn-sair" title="Sair"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white" style="background: rgba(255,76,48,0.8);">
                    <?= strtoupper(substr($adminNome, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(59,130,246,0.2); color: #60a5fa;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Total de Leads</p>
                    <p class="stat-value"><?= number_format($totalLeads) ?></p>
                </div>
            </div>

            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(251,191,36,0.2); color: #fbbf24;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Novos</p>
                    <p class="stat-value"><?= $novos ?></p>
                </div>
            </div>

            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(168,85,247,0.2); color: #c084fc;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Em Contato</p>
                    <p class="stat-value"><?= $emContato ?></p>
                </div>
            </div>

            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(34,197,94,0.2); color: #4ade80;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Convertidos</p>
                    <p class="stat-value"><?= $convertidos ?></p>
                </div>
            </div>

            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(255,76,48,0.2); color: #ff7a61;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Descartados</p>
                    <p class="stat-value"><?= $descartados ?></p>
                </div>
            </div>

            <div class="stat-card glass-card">
                <div class="stat-icon" style="background: rgba(255,76,48,0.25); color: #ff4c30;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-label">Taxa de Conversao</p>
                    <p class="stat-value"><?= $taxaConversao ?>%</p>
                </div>
            </div>
        </div>

        <!-- Charts & Tables Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">

            <!-- Line Chart: Ultimos 7 dias -->
            <div class="lg:col-span-2 glass-card rounded-2xl p-6">
                <h2 class="text-white font-bold text-base mb-4">Leads — Ultimos 7 Dias</h2>
                <div style="height: 220px;">
                    <canvas id="chartLeads"></canvas>
                </div>
            </div>

            <!-- Donut Chart: Status -->
            <div class="glass-card rounded-2xl p-6">
                <h2 class="text-white font-bold text-base mb-4">Distribuicao por Status</h2>
                <div style="height: 200px;">
                    <canvas id="chartStatus"></canvas>
                </div>
                <div class="mt-3 space-y-1">
                    <div class="flex justify-between text-xs"><span class="text-yellow-300"><i class="fa-solid fa-square mr-1"></i>Novo</span><span class="text-white font-semibold"><?= $novos ?></span></div>
                    <div class="flex justify-between text-xs"><span class="text-purple-300"><i class="fa-solid fa-square mr-1"></i>Em Contato</span><span class="text-white font-semibold"><?= $emContato ?></span></div>
                    <div class="flex justify-between text-xs"><span class="text-green-300"><i class="fa-solid fa-square mr-1"></i>Convertido</span><span class="text-white font-semibold"><?= $convertidos ?></span></div>
                    <div class="flex justify-between text-xs"><span style="color:#ff7a61"><i class="fa-solid fa-square mr-1"></i>Descartado</span><span class="text-white font-semibold"><?= $descartados ?></span></div>
                </div>
            </div>
        </div>

        <!-- Bottom Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">

            <!-- Recentes -->
            <div class="glass-card rounded-2xl p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-white font-bold text-base">Leads Recentes</h2>
                    <a href="leads.php" class="text-xs text-accent hover:underline" style="color: #ff7a61;">Ver todos <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($recentes as $l): ?>
                    <?php
                        $statusColor = match($l['status']) {
                            'novo'        => 'badge-novo',
                            'em_contato'  => 'badge-em_contato',
                            'convertido'  => 'badge-convertido',
                            'descartado'  => 'badge-descartado',
                            default       => 'badge-novo'
                        };
                        $statusLabel = match($l['status']) {
                            'novo'       => 'Novo',
                            'em_contato' => 'Em Contato',
                            'convertido' => 'Convertido',
                            'descartado' => 'Descartado',
                            default      => ucfirst($l['status'])
                        };
                    ?>
                    <div class="flex items-center gap-3 p-3 rounded-xl" style="background: rgba(255,255,255,0.05);">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm text-white flex-shrink-0" style="background: linear-gradient(135deg, #172a4c, #ff4c30);">
                            <?= strtoupper(substr($l['nome'], 0, 1)) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white text-sm font-semibold truncate"><?= htmlspecialchars($l['nome']) ?></p>
                            <p class="text-xs opacity-50 text-white"><?= htmlspecialchars($l['telefone']) ?></p>
                        </div>
                        <span class="badge <?= $statusColor ?>"><?= $statusLabel ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentes)): ?>
                        <p class="text-center text-white opacity-40 text-sm py-4">Nenhum lead ainda.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Bairros -->
            <div class="glass-card rounded-2xl p-6">
                <h2 class="text-white font-bold text-base mb-4">Top Bairros / Regioes</h2>
                <?php if (empty($topBairros)): ?>
                    <p class="text-center text-white opacity-40 text-sm py-4">Nenhum bairro registrado.</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php
                    $maxBairro = $topBairros[0]['total'] ?? 1;
                    foreach ($topBairros as $b):
                        $pct = round(($b['total'] / $maxBairro) * 100);
                    ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-white font-medium truncate"><?= htmlspecialchars($b['bairro']) ?></span>
                            <span class="text-white opacity-60 ml-2 flex-shrink-0"><?= $b['total'] ?> leads</span>
                        </div>
                        <div class="h-2 rounded-full" style="background: rgba(255,255,255,0.1);">
                            <div class="h-2 rounded-full transition-all" style="width: <?= $pct ?>%; background: linear-gradient(90deg, #ff4c30, #ff8c6b);"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <script>
    // Chart: Leads ultimos 7 dias
    const ctx1 = document.getElementById('chartLeads').getContext('2d');
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartDias) ?>,
            datasets: [{
                label: 'Leads',
                data: <?= json_encode($chartTotais) ?>,
                borderColor: '#ff4c30',
                backgroundColor: 'rgba(255,76,48,0.15)',
                borderWidth: 2.5,
                pointBackgroundColor: '#ff4c30',
                pointRadius: 4,
                fill: true,
                tension: 0.4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: 'rgba(255,255,255,0.5)', font: { family: 'Raleway', size: 11 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: 'rgba(255,255,255,0.5)', font: { family: 'Raleway', size: 11 }, stepSize: 1 }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
            }
        }
    });

    // Chart: Status donut
    const ctx2 = document.getElementById('chartStatus').getContext('2d');
    new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: ['Novo', 'Em Contato', 'Convertido', 'Descartado'],
            datasets: [{
                data: [<?= $novos ?>, <?= $emContato ?>, <?= $convertidos ?>, <?= $descartados ?>],
                backgroundColor: ['rgba(251,191,36,0.8)', 'rgba(168,85,247,0.8)', 'rgba(34,197,94,0.8)', 'rgba(255,76,48,0.8)'],
                borderColor: 'transparent',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return ' ' + c.label + ': ' + c.raw; } } }
            },
            cutout: '65%'
        }
    });

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
    }
    </script>
</body>
</html>