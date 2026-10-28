<?php
require_once 'session.php';
require_once 'db.php';
require_once 'schema.php';
require_once 'routeros.php';

date_default_timezone_set('America/Sao_Paulo');
admin_require_login(true);
header('Content-Type: application/json');

const IDLE_LIMITE_S = 3 * 3600;

// "1d2h3m4s" (formato RouterOS) -> segundos
function dur_segundos(string $s): int
{
    $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    $t = 0;
    if (preg_match_all('/(\d+)([wdhms])/', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $p) {
            $t += (int) $p[1] * $mult[$p[2]];
        }
    }
    return $t;
}

try {
    $pdo = getDB();
    ensure_schema($pdo);
    $out = ['success' => true];

    // ---- Dados do roteador (se falhar, o resto do dashboard continua) ----
    try {
        $router = hotspot_router();
        $ativos = $router->activeList();
        $macs   = array_values(array_unique(array_filter(array_column($ativos, 'mac'))));
        $cpfs   = [];
        if ($macs) {
            $in   = implode(',', array_fill(0, count($macs), '?'));
            $stmt = $pdo->prepare("SELECT mac, cpf FROM hotspot_logins WHERE id IN (SELECT MAX(id) FROM hotspot_logins WHERE mac IN ($in) GROUP BY mac)");
            $stmt->execute($macs);
            $cpfs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        $up = $down = $ociosos = 0;
        foreach ($ativos as &$a) {
            $up   += $a['up'];
            $down += $a['down'];
            if (dur_segundos($a['idle']) >= IDLE_LIMITE_S) {
                $ociosos++;
            }
            $a['cpf']   = mask_cpf($cpfs[$a['mac']] ?? '');
            $a['total'] = $a['up'] + $a['down'];
        }
        unset($a);
        usort($ativos, fn($x, $y) => $y['total'] <=> $x['total']);

        $out['router'] = [
            'conectados' => count($ativos),
            'bloqueados' => count($router->blockedList()),
            'ociosos'    => $ociosos,
            'up'         => $up,
            'down'       => $down,
            'top'        => array_map(
                fn($a) => ['cpf' => $a['cpf'], 'ip' => $a['ip'], 'mac' => $a['mac'], 'up' => $a['up'], 'down' => $a['down'], 'uptime' => $a['uptime']],
                array_slice($ativos, 0, 10)
            ),
        ];
    } catch (RouterOsException $e) {
        $out['router_error'] = $e->getMessage();
    }

    // ---- Historico de logins (banco) ----
    $dia = $pdo->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS logins, COUNT(DISTINCT cpf) AS unicos
        FROM hotspot_logins
        WHERE created_at >= CURDATE() - INTERVAL 13 DAY
        GROUP BY DATE(created_at)
    ")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $porDia = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $porDia[] = [
            'dia'    => date('d/m', strtotime($d)),
            'logins' => (int) ($dia[$d]['logins'] ?? 0),
            'unicos' => (int) ($dia[$d]['unicos'] ?? 0),
        ];
    }

    $hora = $pdo->query("
        SELECT HOUR(created_at) AS h, COUNT(*) AS n
        FROM hotspot_logins
        WHERE created_at >= NOW() - INTERVAL 7 DAY
        GROUP BY HOUR(created_at)
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $porHora = [];
    for ($h = 0; $h < 24; $h++) {
        $porHora[] = (int) ($hora[$h] ?? 0);
    }

    $hoje = $pdo->query("SELECT COUNT(*) AS logins, COUNT(DISTINCT cpf) AS unicos FROM hotspot_logins WHERE created_at >= CURDATE()")->fetch();

    $out['logins'] = [
        'por_dia'      => $porDia,
        'por_hora'     => $porHora,
        'hoje'         => (int) $hoje['logins'],
        'unicos_hoje'  => (int) $hoje['unicos'],
    ];
    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao montar o dashboard.']);
}
