<?php
// =============================================================
// Alertas: conectados acima do limite, cliente com muito trafego
// e roteador fora do ar. Um alerta abre quando a condicao aparece
// e fecha sozinho quando ela some (sem repetir aviso enquanto durar).
// =============================================================
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/routeros.php';

const ALERT_INTERVALO_S = 30; // no maximo uma verificacao a cada 30 s, mesmo com varias telas abertas

function alert_config(PDO $pdo): array
{
    $cfg = $pdo->query("SELECT chave, valor FROM alert_config")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'max_conectados' => (int) ($cfg['max_conectados'] ?? 0),
        'max_trafego_mb' => (int) ($cfg['max_trafego_mb'] ?? 0),
    ];
}

function alert_fmt_bytes(int $n): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $n >= 1024 && $i < 4; $i++) {
        $n /= 1024;
    }
    return number_format($n, $i ? 1 : 0, ',', '.') . ' ' . $u[$i];
}

function alert_telegram(string $texto): void
{
    if (!defined('ALERT_TELEGRAM_TOKEN') || !defined('ALERT_TELEGRAM_CHAT')
        || ALERT_TELEGRAM_TOKEN === '' || ALERT_TELEGRAM_CHAT === '' || !function_exists('curl_init')) {
        return;
    }
    $ch = curl_init('https://api.telegram.org/bot' . ALERT_TELEGRAM_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['chat_id' => ALERT_TELEGRAM_CHAT, 'text' => "ServiceNet Hotspot\n" . $texto],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch); // falha de envio nao pode atrapalhar o painel
    curl_close($ch);
}

/**
 * Avalia as regras e abre/fecha alertas. $forcar ignora o intervalo minimo (usado pelo cron).
 * Retorna true se a verificacao foi feita.
 */
function alert_check(PDO $pdo, bool $forcar = false): bool
{
    $agora = time();
    if (!$forcar) {
        // "Reserva" a execucao: so uma requisicao por intervalo passa daqui
        $reservou = $pdo->prepare("UPDATE alert_config SET valor = ? WHERE chave = 'ultima_execucao' AND CAST(valor AS UNSIGNED) <= ?");
        $reservou->execute([(string) $agora, $agora - ALERT_INTERVALO_S]);
        if ($reservou->rowCount() === 0) {
            return false;
        }
    } else {
        $pdo->prepare("UPDATE alert_config SET valor = ? WHERE chave = 'ultima_execucao'")->execute([(string) $agora]);
    }

    $cfg = alert_config($pdo);
    $condicoes = []; // chave => [tipo, mensagem]

    try {
        $ativos = hotspot_router()->activeList();

        if ($cfg['max_conectados'] > 0 && count($ativos) > $cfg['max_conectados']) {
            $condicoes['conectados'] = ['conectados',
                count($ativos) . ' clientes conectados (limite: ' . $cfg['max_conectados'] . ')'];
        }

        if ($cfg['max_trafego_mb'] > 0) {
            $limite = $cfg['max_trafego_mb'] * 1048576;
            $pesados = array_filter($ativos, fn($a) => $a['up'] + $a['down'] >= $limite && preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $a['mac']));
            $cpfs = [];
            if ($pesados) {
                $macs = array_column($pesados, 'mac');
                $in   = implode(',', array_fill(0, count($macs), '?'));
                $s    = $pdo->prepare("SELECT mac, cpf FROM hotspot_logins WHERE id IN (SELECT MAX(id) FROM hotspot_logins WHERE mac IN ($in) GROUP BY mac)");
                $s->execute($macs);
                $cpfs = $s->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            foreach ($pesados as $a) {
                $quem = mask_cpf($cpfs[$a['mac']] ?? '') ?: $a['ip'];
                $condicoes['trafego:' . $a['mac']] = ['trafego',
                    "Alto consumo: $quem ({$a['ip']}) usou " . alert_fmt_bytes($a['up'] + $a['down'])
                    . ' (limite: ' . alert_fmt_bytes($limite) . ')'];
            }
        }
    } catch (RouterOsException $e) {
        $condicoes['roteador'] = ['roteador', 'Nao foi possivel consultar o roteador: ' . $e->getMessage()];
    }

    $abertos = $pdo->query("SELECT id, chave FROM alertas WHERE resolvido_em IS NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
    // $abertos: id => chave
    $chavesAbertas = array_flip($abertos);

    // Fecha o que deixou de valer
    foreach ($abertos as $id => $chave) {
        if (!isset($condicoes[$chave])) {
            $pdo->prepare("UPDATE alertas SET resolvido_em = NOW() WHERE id = ?")->execute([$id]);
        }
    }

    // Abre o que e novo
    foreach ($condicoes as $chave => [$tipo, $msg]) {
        if (isset($chavesAbertas[$chave])) {
            continue;
        }
        $pdo->prepare("INSERT INTO alertas (tipo, chave, mensagem) VALUES (?, ?, ?)")
            ->execute([$tipo, $chave, mb_substr($msg, 0, 255)]);
        alert_telegram('ALERTA: ' . $msg);
    }
    return true;
}
