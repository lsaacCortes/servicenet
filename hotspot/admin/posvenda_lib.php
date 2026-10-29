<?php
// =============================================================
// Pos-venda: sincronizacao dos contratos recem-ativados no SGP
// =============================================================

const POSVENDA_DIAS_ALVO      = 7;    // dias apos a ativacao para o contato de pos-venda
const POSVENDA_JANELA_DIAS    = 60;   // ate quantos dias atras um cadastro ainda pode ser ativado
const POSVENDA_SGP_MAX_DIAS   = 30;   // o SGP recusa intervalos de cadastro maiores que 31 dias
const POSVENDA_SYNC_AUTO_MIN  = 10;   // intervalo minimo entre sincronizacoes automaticas

function posvenda_cfg_get(PDO $pdo, string $chave): ?string
{
    $stmt = $pdo->prepare("SELECT valor FROM alert_config WHERE chave = ?");
    $stmt->execute([$chave]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

function posvenda_cfg_set(PDO $pdo, string $chave, string $valor): void
{
    $pdo->prepare("INSERT INTO alert_config (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([$chave, $valor]);
}

// Data (AAAA-MM-DD) a partir da qual as ativacoes entram no pos-venda. Na primeira vez: hoje.
function posvenda_inicio(PDO $pdo): string
{
    $v = posvenda_cfg_get($pdo, 'posvenda_inicio');
    if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $v = date('Y-m-d');
        posvenda_cfg_set($pdo, 'posvenda_inicio', $v);
    }
    return $v;
}

// Consulta o SGP (POST) e devolve ['ok' => bool, 'dados' => array, 'message' => string]
function posvenda_sgp_listar(string $ini, string $fim): array
{
    if (!defined('SGP_BASE_URL') || !defined('SGP_TOKEN') || SGP_TOKEN === '') {
        return ['ok' => false, 'dados' => [], 'message' => 'SGP não configurado: defina SGP_BASE_URL e SGP_TOKEN no config.php.'];
    }
    $base  = SGP_BASE_URL;
    $app   = defined('SGP_APP') ? SGP_APP : 'SGP';
    $token = SGP_TOKEN;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => rtrim($base, '/') . '/api/ura/listacontrato/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'app'               => $app,
            'token'             => $token,
            'data_cadastro_ini' => $ini,
            'data_cadastro_fim' => $fim,
            'exibir_endereco'   => 'true',
        ],
    ]);
    $resp   = curl_exec($curl);
    $http   = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroNo = curl_errno($curl);
    curl_close($curl);

    if ($resp === false || $erroNo) {
        return ['ok' => false, 'dados' => [], 'message' => 'Não foi possível conectar ao SGP.'];
    }
    $dados = json_decode($resp, true);
    if ($http !== 200 || !is_array($dados)) {
        return ['ok' => false, 'dados' => [], 'message' => 'O SGP respondeu com erro (HTTP ' . $http . ').'];
    }
    return ['ok' => true, 'dados' => $dados, 'message' => ''];
}

// Traz do SGP os contratos ativos a partir da data de inicio e atualiza a lista.
// $auto = true respeita o intervalo minimo entre sincronizacoes.
function posvenda_sync(PDO $pdo, bool $auto = false): array
{
    $ultima = (int) posvenda_cfg_get($pdo, 'posvenda_ultima_sync');
    if ($auto && $ultima > 0 && time() - $ultima < POSVENDA_SYNC_AUTO_MIN * 60) {
        return ['success' => true, 'skipped' => true, 'novos' => 0, 'message' => 'Já sincronizado recentemente.'];
    }

    $inicio = posvenda_inicio($pdo);

    // Busca em janelas (limite do SGP), da mais recente para a mais antiga
    $contratos = [];
    $fim = date('Y-m-d');
    $limite = date('Y-m-d', strtotime('-' . POSVENDA_JANELA_DIAS . ' days'));
    while ($fim >= $limite) {
        $ini = max($limite, date('Y-m-d', strtotime($fim . ' -' . POSVENDA_SGP_MAX_DIAS . ' days')));
        $res = posvenda_sgp_listar($ini, $fim);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $res['message']];
        }
        array_push($contratos, ...$res['dados']);
        $fim = date('Y-m-d', strtotime($ini . ' -1 day'));
    }

    $ins = $pdo->prepare("
        INSERT INTO posvenda (id_contrato, id_cliente, nome, cpfcnpj, telefone, email, bairro, cidade,
                              status_contrato, data_cadastro, ativado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nome = VALUES(nome), telefone = VALUES(telefone), email = VALUES(email),
            bairro = VALUES(bairro), cidade = VALUES(cidade), status_contrato = VALUES(status_contrato)
    ");
    $existe = $pdo->prepare("SELECT 1 FROM posvenda WHERE id_contrato = ?");

    $novos = 0;
    foreach ($contratos as $c) {
        $contrato = (int) ($c['id_contrato'] ?? 0);
        if ($contrato <= 0 || !is_array($c)) {
            continue;
        }
        $statusSgp = trim((string) ($c['status'] ?? ''));
        $ativo     = mb_strtolower($statusSgp) === 'ativo';
        $cadastro  = posvenda_data($c['data_cadastro'] ?? null);
        // O SGP nao informa a data de ativacao: usa o momento em que o contrato ficou "Ativo"
        $ativadoEm = posvenda_data($c['data_status'] ?? null) ?? $cadastro;

        $existe->execute([$contrato]);
        $jaTem = (bool) $existe->fetchColumn();

        // Contrato novo so entra se estiver ativo e tiver sido ativado a partir da data de inicio
        if (!$jaTem && (!$ativo || $ativadoEm === null || substr($ativadoEm, 0, 10) < $inicio)) {
            continue;
        }

        $end = is_array($c['endereco'] ?? null) ? $c['endereco'] : [];
        $ins->execute([
            $contrato,
            (int) ($c['id_cliente'] ?? 0) ?: null,
            mb_substr(trim((string) ($c['nome'] ?? 'Sem nome')), 0, 150),
            preg_replace('/\D/', '', (string) ($c['cpfcnpj'] ?? '')) ?: null,
            mb_substr(trim((string) ($c['telefone'] ?? '')), 0, 30) ?: null,
            mb_substr(trim((string) ($c['email'] ?? '')), 0, 150) ?: null,
            mb_substr(trim((string) ($end['bairro'] ?? '')), 0, 100) ?: null,
            mb_substr(trim((string) ($end['cidade'] ?? '')), 0, 100) ?: null,
            mb_substr($statusSgp, 0, 30) ?: null,
            $cadastro,
            $ativadoEm ?? date('Y-m-d H:i:s'),
        ]);
        if (!$jaTem) {
            $novos++;
        }
    }

    posvenda_cfg_set($pdo, 'posvenda_ultima_sync', (string) time());
    return [
        'success' => true,
        'novos'   => $novos,
        'message' => $novos > 0
            ? $novos . ($novos === 1 ? ' novo contrato adicionado.' : ' novos contratos adicionados.')
            : 'Lista atualizada. Nenhum contrato novo.',
    ];
}

function posvenda_data($v): ?string
{
    if (!is_string($v) || $v === '') {
        return null;
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}
