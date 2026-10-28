<?php
require_once 'session.php';
require_once 'db.php';
require_once 'schema.php';
require_once 'routeros.php';

date_default_timezone_set('America/Sao_Paulo');
admin_require_login(true);
header('Content-Type: application/json');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

const MAC_RE = '/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i';
const ID_RE  = '/^\*[0-9A-Fa-f]+$/';

try {
    $pdo = getDB();
    ensure_schema($pdo);
    $perfil = admin_perfil($pdo);
    $action = $_REQUEST['action'] ?? '';
    $leitura = in_array($action, ['list', 'blocked', 'audit'], true);

    if (!$leitura) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
            out(['success' => false, 'message' => 'Requisicao invalida.'], 400);
        }
        if ($perfil !== 'admin') {
            out(['success' => false, 'message' => 'Seu perfil e somente de visualizacao.'], 403);
        }
    }

    if ($action === 'audit') {
        $rows = $pdo->query("SELECT admin_user, acao, alvo, detalhes, created_at FROM admin_audit ORDER BY id DESC LIMIT 100")->fetchAll();
        out(['success' => true, 'audit' => $rows]);
    }

    $router = hotspot_router();

    // Junta cada sessao ativa ao CPF que fez o ultimo login com aquele MAC
    $cpfs = function (array $macs) use ($pdo): array {
        $macs = array_values(array_unique(array_filter($macs)));
        if (!$macs) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($macs), '?'));
        $stmt = $pdo->prepare("SELECT mac, cpf FROM hotspot_logins WHERE id IN (SELECT MAX(id) FROM hotspot_logins WHERE mac IN ($in) GROUP BY mac)");
        $stmt->execute($macs);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    $findActive = function (string $id) use ($router): ?array {
        foreach ($router->activeList() as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }
        return null;
    };

    // Derruba a sessao e apaga o cookie do aparelho; sem isso o roteador pode
    // reabrir o acesso sozinho (login por cookie) e o cliente nao ve o portal.
    $derrubar = function (array $a) use ($router): void {
        $router->removeActive($a['id']);
        if (preg_match(MAC_RE, $a['mac'])) {
            try {
                $router->clearCookies($a['mac']);
            } catch (RouterOsException $e) {
                // a sessao ja caiu; a limpeza do cookie e complementar
            }
        }
    };

    switch ($action) {
        case 'list':
            $active = $router->activeList();
            $map    = $cpfs(array_column($active, 'mac'));
            foreach ($active as &$a) {
                $a['cpf'] = mask_cpf($map[$a['mac']] ?? '');
            }
            unset($a);
            out(['success' => true, 'perfil' => $perfil, 'active' => $active]);

        case 'blocked':
            $blocked = $router->blockedList();
            $map     = $cpfs(array_column($blocked, 'mac'));
            foreach ($blocked as &$b) {
                $b['cpf'] = mask_cpf($map[$b['mac']] ?? '');
            }
            unset($b);
            out(['success' => true, 'blocked' => $blocked]);

        case 'disconnect':
        case 'block':
            $id = $_POST['id'] ?? '';
            if (!preg_match(ID_RE, $id)) {
                out(['success' => false, 'message' => 'Sessao invalida.'], 400);
            }
            $a = $findActive($id);
            if (!$a) {
                out(['success' => false, 'message' => 'Esse cliente ja nao esta conectado.']);
            }
            if ($action === 'block') {
                if (!preg_match(MAC_RE, $a['mac'])) {
                    out(['success' => false, 'message' => 'MAC do cliente indisponivel.']);
                }
                $ja = array_filter($router->blockedList(), fn($b) => $b['mac'] === $a['mac']);
                if (!$ja) {
                    $router->block($a['mac'], 'Bloqueado pelo painel por ' . ($_SESSION['admin_user'] ?? '?') . ' em ' . date('d/m/Y H:i'));
                }
            }
            $derrubar($a);
            $cpf = mask_cpf($cpfs([$a['mac']])[$a['mac']] ?? '');
            admin_log($pdo, $action === 'block' ? 'bloquear' : 'desconectar', $a['mac'], "IP {$a['ip']}" . ($cpf ? " CPF $cpf" : ''));
            out(['success' => true, 'message' => $action === 'block' ? 'Cliente bloqueado e desconectado.' : 'Cliente desconectado.']);

        case 'unblock':
            $id = $_POST['id'] ?? '';
            if (!preg_match(ID_RE, $id)) {
                out(['success' => false, 'message' => 'Bloqueio invalido.'], 400);
            }
            $mac = '';
            foreach ($router->blockedList() as $b) {
                if ($b['id'] === $id) {
                    $mac = $b['mac'];
                }
            }
            $router->unblock($id);
            admin_log($pdo, 'desbloquear', $mac);
            out(['success' => true, 'message' => 'Cliente desbloqueado.']);

        case 'disconnect_all':
            $n = 0;
            foreach ($router->activeList() as $a) {
                $derrubar($a);
                $n++;
            }
            admin_log($pdo, 'desconectar_todos', '', "$n sessoes derrubadas");
            out(['success' => true, 'message' => "$n cliente(s) desconectado(s)."]);

        default:
            out(['success' => false, 'message' => 'Acao invalida.'], 400);
    }
} catch (RouterOsException $e) {
    out(['success' => false, 'message' => 'Roteador: ' . $e->getMessage()], 502);
} catch (Throwable $e) {
    out(['success' => false, 'message' => 'Erro interno ao processar a solicitacao.'], 500);
}
