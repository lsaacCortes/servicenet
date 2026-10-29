<?php
require_once 'session.php';
admin_require_login();
require_once 'db.php';
$pdo = getDB();

// Filtros
$search    = trim($_GET['q'] ?? '');
$statusFil = $_GET['status'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 12;
$offset    = ($page - 1) * $perPage;

// Query base
$where  = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where  .= " AND (nome LIKE ? OR telefone LIKE ? OR bairro LIKE ?)";
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($statusFil !== '') {
    $where  .= " AND status = ?";
    $params[] = $statusFil;
}

$totalRows = $pdo->prepare("SELECT COUNT(*) FROM leads $where");
$totalRows->execute($params);
$totalRows = (int)$totalRows->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM leads $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$leads = $stmt->fetchAll();

$adminNome = $_SESSION['admin_nome'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        .lead-card {
            background: var(--w07);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border: 1px solid var(--w12);
            box-shadow: 0 4px 24px rgba(0,0,0,0.3), inset 0 1px 0 var(--w08);
            transition: all 0.3s ease;
        }
        .lead-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,76,48,0.35);
            box-shadow: 0 12px 40px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,76,48,0.2), inset 0 1px 0 var(--w12);
        }
        .filter-bar {
            background: var(--w06);
            backdrop-filter: blur(16px);
            border: 1px solid var(--w10);
        }
        .search-input {
            background: var(--w08);
            border: 1px solid var(--w15);
            color: var(--text);
        }
        .search-input::placeholder { color: var(--w35); }
        .search-input:focus { outline: none; border-color: #ff4c30; background: var(--w12); }

        .filter-btn {
            background: var(--w07);
            border: 1px solid var(--w15);
            color: var(--w70);
            transition: all 0.2s;
        }
        .filter-btn:hover, .filter-btn.active {
            background: rgba(255,76,48,0.25);
            border-color: rgba(255,76,48,0.5);
            color: var(--text);
        }

        /* Modal */
        .modal-backdrop {
            background: var(--backdrop);
            backdrop-filter: blur(8px);
        }
        .modal-box {
            background: var(--surface);
            border: 1px solid var(--w15);
        }
        .modal-input {
            background: var(--w08);
            border: 1px solid var(--w20);
            color: var(--text);
        }
        .modal-input:focus { outline: none; border-color: #ff4c30; }
        .modal-input::placeholder { color: var(--w30); }
        select.modal-input option { background: var(--option-bg); color: var(--text); }

        .avatar {
            background: linear-gradient(135deg, #172a4c, #ff4c30);
        }

        .card-phone-btn {
            background: rgba(34,197,94,0.15);
            border: 1px solid rgba(34,197,94,0.3);
            color: var(--green);
            transition: all 0.2s;
        }
        .card-phone-btn:hover {
            background: rgba(34,197,94,0.25);
        }

        .page-btn {
            background: var(--w08);
            border: 1px solid var(--w15);
            color: var(--text);
            transition: all 0.2s;
        }
        .page-btn:hover, .page-btn.active {
            background: rgba(255,76,48,0.35);
            border-color: #ff4c30;
        }
        .page-btn:disabled { opacity: 0.3; cursor: not-allowed; }
    </style>
</head>
<body class="admin-body">

    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo"><img src="../assets/img/logo.PNG" class="logo-bordered" alt="ServiceNet"
                 onerror="this.outerHTML='<span class=\'text-white font-extrabold text-lg\'>ServiceNet</span>'">
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="leads.php" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/>
                </svg>
                <span>Leads</span>
            </a>
            <a href="posvenda.php" class="nav-item"><i class="fa-solid fa-headset w-5 text-center"></i><span>Pós-venda</span></a>
            <a href="rede.php" class="nav-item">
                <i class="fa-solid fa-network-wired w-5 text-center"></i>
                <span>Rede</span>
            </a>
            <a href="conectados.php" class="nav-item">
                <i class="fa-solid fa-wifi w-5 text-center"></i>
                <span>Conectados</span>
            </a>
            <a href="historico.php" class="nav-item">
                <i class="fa-solid fa-clock-rotate-left w-5 text-center"></i>
                <span>Histórico</span>
            </a>
            <a href="alertas.php" class="nav-item">
                <i class="fa-solid fa-bell w-5 text-center"></i>
                <span>Alertas</span>
            </a>
            <a href="auditoria.php" class="nav-item">
                <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                <span>Auditoria</span>
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

    <!-- Main -->
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
                    <h1 class="text-white font-bold text-lg leading-none">Gestao de Leads</h1>
                    <p class="text-xs opacity-50 text-white"><?= $totalRows ?> lead<?= $totalRows !== 1 ? 's' : '' ?> encontrado<?= $totalRows !== 1 ? 's' : '' ?></p>
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

        <!-- Filter Bar -->
        <form method="GET" action="" class="filter-bar rounded-2xl p-4 mt-6 flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-48 relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 absolute left-3 top-1/2 -translate-y-1/2 opacity-40 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="q" placeholder="Buscar por nome, telefone ou bairro..."
                       value="<?= htmlspecialchars($search) ?>"
                       class="search-input w-full pl-9 pr-4 py-2.5 rounded-xl text-sm">
            </div>
            <div class="flex flex-wrap gap-2">
                <?php
                $statuses = ['' => 'Todos', 'novo' => 'Novos', 'em_contato' => 'Em Contato', 'convertido' => 'Convertidos', 'descartado' => 'Descartados'];
                foreach ($statuses as $val => $label):
                ?>
                <button type="submit" name="status" value="<?= $val ?>"
                        class="filter-btn px-4 py-2 rounded-xl text-sm font-semibold <?= $statusFil === $val ? 'active' : '' ?>">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>
        </form>

        <!-- Leads Grid -->
        <?php if (empty($leads)): ?>
        <div class="text-center py-20">
            <div class="text-6xl mb-4"><i class="fa-solid fa-magnifying-glass opacity-40 text-white text-5xl mb-4 block"></i></div>
            <p class="text-white text-xl font-bold opacity-60">Nenhum lead encontrado.</p>
            <p class="text-white opacity-30 text-sm mt-2">Tente ajustar os filtros de busca.</p>
        </div>
        <?php else: ?>
        <div class="leads-grid mt-6">
            <?php foreach ($leads as $lead):
                $statusLabel = match($lead['status']) {
                    'novo'       => 'Novo',
                    'em_contato' => 'Em Contato',
                    'convertido' => 'Convertido',
                    'descartado' => 'Descartado',
                    default      => ucfirst($lead['status'])
                };
                $ini = strtoupper(substr($lead['nome'], 0, 1));
                $dataFormatada = date('d/m/Y H:i', strtotime($lead['created_at']));
            ?>
            <div class="lead-card rounded-2xl p-5 flex flex-col gap-4" id="card-<?= $lead['id'] ?>">
                <!-- Card Header -->
                <div class="flex items-start gap-3">
                    <div class="avatar w-11 h-11 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                        <?= htmlspecialchars($ini) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-white font-bold text-sm leading-tight truncate"><?= htmlspecialchars($lead['nome']) ?></h3>
                        <p class="text-xs mt-0.5" style="color: var(--w45);"><?= $dataFormatada ?></p>
                    </div>
                    <span class="badge badge-<?= $lead['status'] ?> flex-shrink-0" id="badge-<?= $lead['id'] ?>">
                        <?= $statusLabel ?>
                    </span>
                </div>

                <!-- Info -->
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm" style="color: var(--w70);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        <span class="font-medium"><?= htmlspecialchars($lead['telefone']) ?></span>
                    </div>
                    <?php if (!empty($lead['bairro']) && $lead['bairro'] !== 'Nao informado'): ?>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--w55);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span><?= htmlspecialchars($lead['bairro']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($lead['anotacoes'])): ?>
                    <div class="text-xs mt-1 p-2 rounded-lg" style="background: var(--w05); color: var(--w50);">
                        <i class="fa-solid fa-note-sticky"></i> <?= nl2br(htmlspecialchars(mb_substr($lead['anotacoes'], 0, 80))) ?><?= strlen($lead['anotacoes']) > 80 ? '...' : '' ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="flex gap-2 mt-auto pt-2 border-t" style="border-color: var(--w08);">
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $lead['telefone']) ?>" target="_blank"
                       class="card-phone-btn flex-1 py-2 rounded-xl text-xs font-bold text-center flex items-center justify-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                        </svg>
                        WhatsApp
                    </a>
                    <button onclick="openModal(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['nome'])) ?>', '<?= $lead['status'] ?>', '<?= htmlspecialchars(addslashes($lead['anotacoes'] ?? '')) ?>')"
                            class="flex-1 py-2 rounded-xl text-xs font-bold text-white flex items-center justify-center gap-1"
                            style="background: var(--w10); border: 1px solid var(--w15); transition: all 0.2s;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Editar
                    </button>
                    <button onclick="deleteLead(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['nome'])) ?>')"
                            class="py-2 px-3 rounded-xl text-xs font-bold flex items-center justify-center"
                            style="background: rgba(255,76,48,0.15); border: 1px solid rgba(255,76,48,0.3); color: var(--coral); transition: all 0.2s;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-center gap-2 mt-8 mb-4">
            <a href="?q=<?= urlencode($search) ?>&status=<?= urlencode($statusFil) ?>&page=<?= max(1, $page - 1) ?>"
               class="page-btn px-4 py-2 rounded-xl text-sm font-bold <?= $page <= 1 ? 'pointer-events-none opacity-30' : '' ?>">
               <i class="fa-solid fa-arrow-left"></i> Anterior
            </a>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?q=<?= urlencode($search) ?>&status=<?= urlencode($statusFil) ?>&page=<?= $p ?>"
               class="page-btn px-4 py-2 rounded-xl text-sm font-bold <?= $p === $page ? 'active' : '' ?>">
               <?= $p ?>
            </a>
            <?php endfor; ?>
            <a href="?q=<?= urlencode($search) ?>&status=<?= urlencode($statusFil) ?>&page=<?= min($totalPages, $page + 1) ?>"
               class="page-btn px-4 py-2 rounded-xl text-sm font-bold <?= $page >= $totalPages ? 'pointer-events-none opacity-30' : '' ?>">
               Proximo <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
        <div class="modal-box rounded-2xl w-full max-w-sm p-6">
            <h3 class="text-white font-bold text-lg mb-1">Editar Lead</h3>
            <p id="modalNome" class="text-sm mb-5" style="color: var(--w50);"></p>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color: var(--w70);">Status</label>
                    <select id="modalStatus" class="modal-input w-full py-2.5 px-3 rounded-xl text-sm">
                        <option value="novo">Novo</option>
                        <option value="em_contato">Em Contato</option>
                        <option value="convertido">Convertido</option>
                        <option value="descartado">Descartado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color: var(--w70);">Anotacoes</label>
                    <textarea id="modalAnotacoes" rows="4" placeholder="Adicione observacoes sobre este lead..."
                              class="modal-input w-full py-2.5 px-3 rounded-xl text-sm resize-none"></textarea>
                </div>
            </div>

            <div class="flex gap-3 mt-6">
                <button onclick="closeModal()" class="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
                        style="background: var(--w10); border: 1px solid var(--w20);">
                    Cancelar
                </button>
                <button onclick="saveLead()" id="saveBtn"
                        class="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
                        style="background: linear-gradient(135deg, #ff4c30, #e63825);">
                    Salvar
                </button>
            </div>
            <p id="modalMsg" class="text-center text-xs mt-3 font-semibold hidden"></p>
        </div>
    </div>

    <script>
    let currentLeadId = null;

    function openModal(id, nome, status, anotacoes) {
        currentLeadId = id;
        document.getElementById('modalNome').textContent = nome;
        document.getElementById('modalStatus').value = status;
        document.getElementById('modalAnotacoes').value = anotacoes;
        document.getElementById('modalMsg').classList.add('hidden');
        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('editModal').classList.add('flex');
    }

    function closeModal() {
        document.getElementById('editModal').classList.add('hidden');
        document.getElementById('editModal').classList.remove('flex');
        currentLeadId = null;
    }

    async function saveLead() {
        if (!currentLeadId) return;
        const status    = document.getElementById('modalStatus').value;
        const anotacoes = document.getElementById('modalAnotacoes').value;
        const btn       = document.getElementById('saveBtn');
        const msg       = document.getElementById('modalMsg');

        btn.disabled = true;
        btn.textContent = 'Salvando...';
        msg.classList.add('hidden');

        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('id', currentLeadId);
        fd.append('status', status);
        fd.append('anotacoes', anotacoes);

        try {
            const res  = await fetch('actions.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> Salvo com sucesso!';
                msg.style.color = 'var(--green)';
                msg.classList.remove('hidden');
                // Atualiza badge no card
                const badge = document.getElementById('badge-' + currentLeadId);
                if (badge) {
                    const labels = { novo: 'Novo', em_contato: 'Em Contato', convertido: 'Convertido', descartado: 'Descartado' };
                    badge.textContent = labels[status] || status;
                    badge.className = 'badge badge-' + status + ' flex-shrink-0';
                }
                setTimeout(closeModal, 1200);
            } else {
                msg.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ';
                msg.append(data.message || 'Erro ao salvar.');
                msg.style.color = 'var(--coral)';
                msg.classList.remove('hidden');
            }
        } catch (e) {
            msg.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Erro de comunicacao.';
            msg.style.color = 'var(--coral)';
            msg.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar';
        }
    }

    async function deleteLead(id, nome) {
        const ok = await SN.confirm({
            title: 'Excluir lead',
            message: 'Excluir o lead "' + nome + '"? Esta ação não pode ser desfeita.',
            confirmText: 'Excluir',
            tone: 'danger',
        });
        if (!ok) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        try {
            const res  = await fetch('actions.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const card = document.getElementById('card-' + id);
                if (card) {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(() => card.remove(), 400);
                }
                SN.toast('Lead excluído.');
            } else {
                SN.toast('Erro ao excluir: ' + (data.message || 'Tente novamente.'), 'error');
            }
        } catch (e) {
            SN.toast('Erro de comunicação.', 'error');
        }
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
    }

    // Fecha modal ao clicar no backdrop
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    </script>
    <script src="assets/ui.js?v=<?= filemtime(__DIR__ . '/assets/ui.js') ?>" defer></script>
    <script src="assets/alertas.js" defer></script>
</body>
</html>