<?php
// =============================================================
// Comprovantes recebidos pelo canal do ChatMix (payload no formato WhatsApp Cloud API / Meta)
// =============================================================

const COMPROVANTE_DIR       = __DIR__ . '/comprovantes_arquivos';
const COMPROVANTE_MAX_BYTES = 10 * 1024 * 1024;
const COMPROVANTE_EXT       = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
];

// Extrai do payload as mensagens com imagem ou PDF (as de texto, audio etc. nao interessam).
function comprovantes_extrair(array $p): array
{
    $achados = [];
    foreach ($p['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $v = $change['value'] ?? [];
            $nomes = [];
            foreach ($v['contacts'] ?? [] as $c) {
                $nomes[$c['wa_id'] ?? ''] = trim($c['profile']['name'] ?? '');
            }
            foreach ($v['messages'] ?? [] as $m) {
                $tipo = $m['type'] ?? '';
                if ($tipo !== 'image' && $tipo !== 'document') {
                    continue;
                }
                $mid  = $m[$tipo] ?? [];
                $mime = (string) ($mid['mime_type'] ?? '');
                // Documento so interessa se for PDF ou imagem
                if ($tipo === 'document' && $mime !== 'application/pdf' && strpos($mime, 'image/') !== 0) {
                    continue;
                }
                $fone = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                if ($fone === '' || empty($m['id'])) {
                    continue;
                }
                $achados[] = [
                    'wamid'        => (string) $m['id'],
                    'telefone'     => $fone,
                    'nome'         => $nomes[$fone] ?? '',
                    'tipo'         => $tipo,
                    'mime'         => $mime,
                    'nome_arquivo' => (string) ($mid['filename'] ?? ''),
                    'legenda'      => (string) ($mid['caption'] ?? ''),
                    'media_id'     => (string) ($mid['id'] ?? ''),
                    'media_url'    => (string) ($mid['url'] ?? ''),
                    'recebido_em'  => date('Y-m-d H:i:s', (int) ($m['timestamp'] ?? time())),
                ];
            }
        }
    }
    return $achados;
}

function comprovantes_http_get(string $url, string $token, int $timeout = 12): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    return [$body === false ? null : $body, $http, $erro];
}

// Resumo curto do erro devolvido pela Graph API (sem expor o token)
function comprovantes_erro_graph(?string $body): string
{
    $j = $body !== null ? json_decode($body, true) : null;
    $m = $j['error']['message'] ?? '';
    return $m !== '' ? ' - ' . mb_substr($m, 0, 120) : '';
}

// Baixa o arquivo na Meta. Devolve o nome do arquivo salvo ou null (e o motivo em $erro).
function comprovantes_baixar_midia(array $c, ?string &$erro = null): ?string
{
    $erro  = null;
    $token = defined('META_WHATSAPP_TOKEN') ? trim((string) META_WHATSAPP_TOKEN) : '';
    if ($token === '') {
        $erro = 'META_WHATSAPP_TOKEN não configurado no config.php';
        return null;
    }
    $mime = (string) $c['mime'];
    if (!isset(COMPROVANTE_EXT[$mime])) {
        $erro = 'tipo de arquivo não suportado: ' . $mime;
        return null;
    }

    $body = null;
    $http = 0;
    $url  = (string) $c['media_url'];
    // So usa o token em dominios da Meta/WhatsApp (evita enviar o token a URL arbitraria)
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $ok = $host !== '' && (substr($host, -10) === '.fbsbx.com' || substr($host, -13) === '.facebook.com'
        || substr($host, -12) === '.whatsapp.net' || substr($host, -11) === '.fbcdn.net');
    if ($ok) {
        [$body, $http, $ce] = comprovantes_http_get($url, $token);
        if ($body === null || $http !== 200) {
            $erro = 'link direto: ' . ($http ?: ('sem resposta ' . $ce));
            $body = null;
        }
    }

    // Plano B: pergunta o link novo pelo id da midia
    if ($body === null && !empty($c['media_id'])) {
        [$meta, $h2, $ce] = comprovantes_http_get('https://graph.facebook.com/v21.0/' . rawurlencode($c['media_id']), $token);
        $j = $meta !== null && $h2 === 200 ? json_decode($meta, true) : null;
        if (!empty($j['url'])) {
            [$body, $http, $ce] = comprovantes_http_get($j['url'], $token);
            if ($body === null || $http !== 200) {
                $erro = 'download pelo id: ' . ($http ?: ('sem resposta ' . $ce));
                $body = null;
            }
        } else {
            $erro = ($erro ? $erro . '; ' : '') . 'consulta da mídia: HTTP ' . ($h2 ?: ('sem resposta ' . $ce)) . comprovantes_erro_graph($meta);
        }
    }
    if ($body === null) {
        return null;
    }
    if ($body === '' || strlen($body) > COMPROVANTE_MAX_BYTES) {
        $erro = 'arquivo vazio ou maior que 10 MB';
        return null;
    }

    if (!is_dir(COMPROVANTE_DIR)) {
        @mkdir(COMPROVANTE_DIR, 0755, true);
    }
    // Garante que nenhum arquivo daqui seja executado/listado pelo servidor web
    $ht = COMPROVANTE_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
    }
    $nome = bin2hex(random_bytes(12)) . '.' . COMPROVANTE_EXT[$mime];
    if (@file_put_contents(COMPROVANTE_DIR . '/' . $nome, $body) === false) {
        $erro = 'sem permissão para gravar em comprovantes_arquivos/';
        return null;
    }
    $erro = null;
    return $nome;
}

// Tenta baixar de novo o arquivo de um comprovante ja registrado. Devolve [ok, mensagem].
function comprovantes_rebaixar(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM comprovantes WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) {
        return [false, 'Comprovante não encontrado.'];
    }
    if (!empty($c['arquivo'])) {
        return [true, 'O arquivo já foi baixado.'];
    }
    $erro = null;
    $arq  = comprovantes_baixar_midia($c, $erro);
    $pdo->prepare("UPDATE comprovantes SET arquivo = ?, erro_midia = ? WHERE id = ?")
        ->execute([$arq, $erro ? mb_substr($erro, 0, 250) : null, $id]);
    return $arq ? [true, 'Arquivo baixado.'] : [false, 'Não foi possível baixar: ' . $erro];
}

// ---------------------------------------------------------------
// Identificacao do cliente no SGP pelo telefone de quem enviou
// ---------------------------------------------------------------

const COMPROVANTE_SGP_MAX_CLIENTES = 5; // mais que isso = o SGP provavelmente ignorou o filtro

// Formas em que o numero pode estar cadastrado: so DDD+numero, com e sem o 9 do celular.
function comprovantes_fone_variantes(string $f): array
{
    $f = preg_replace('/\D/', '', $f);
    if (strpos($f, '55') === 0 && strlen($f) >= 12) {
        $f = substr($f, 2);
    }
    $v = [$f];
    if (strlen($f) === 10 && $f[2] >= '6') {
        $v[] = substr($f, 0, 2) . '9' . substr($f, 2);   // celular sem o 9
    } elseif (strlen($f) === 11 && $f[2] === '9') {
        $v[] = substr($f, 0, 2) . substr($f, 3);         // celular cadastrado sem o 9
    }
    return array_values(array_unique($v));
}

function comprovantes_primeiro(array $a, array $chaves)
{
    foreach ($chaves as $k) {
        if (isset($a[$k]) && $a[$k] !== '' && !is_array($a[$k])) {
            return $a[$k];
        }
    }
    return null;
}

// Consulta o SGP. Devolve ['ok'=>bool, 'clientes'=>[['nome','cpf','contratos'=>[['id','status']]]], 'message'=>string]
function comprovantes_sgp_por_telefone(string $telefone): array
{
    if (!defined('SGP_BASE_URL') || !defined('SGP_TOKEN') || SGP_TOKEN === '') {
        return ['ok' => false, 'clientes' => [], 'message' => 'SGP não configurado no config.php'];
    }
    $ch = curl_init(rtrim(SGP_BASE_URL, '/') . '/api/ura/consultacliente/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'app'      => defined('SGP_APP') ? SGP_APP : 'SGP',
            'token'    => SGP_TOKEN,
            'telefone' => $telefone,
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'clientes' => [], 'message' => 'Não foi possível conectar ao SGP'];
    }
    $dados = json_decode($resp, true);
    // O SGP pode responder erro/nao encontrado com 4xx; sem contratos tratamos como "nenhum"
    if (!is_array($dados)) {
        return ['ok' => false, 'clientes' => [], 'message' => 'SGP respondeu HTTP ' . $http . ' fora do formato esperado'];
    }
    $contratos = $dados['contratos'] ?? [];
    if (!is_array($contratos) || !$contratos) {
        return $http >= 500
            ? ['ok' => false, 'clientes' => [], 'message' => 'SGP respondeu HTTP ' . $http]
            : ['ok' => true, 'clientes' => [], 'message' => ''];
    }

    $clientes = [];
    foreach ($contratos as $ct) {
        if (!is_array($ct)) continue;
        $nome = comprovantes_primeiro($ct, ['razaosocial', 'razao_social', 'nome', 'nome_razaosocial', 'cliente_nome']);
        $cpf  = preg_replace('/\D/', '', (string) comprovantes_primeiro($ct, ['cpfcnpj', 'cpf_cnpj', 'cpf']));
        if ($nome === null && $cpf === '') {
            return ['ok' => false, 'clientes' => [], 'message' => 'Resposta do SGP sem nome/CPF. Campos recebidos: '
                . mb_substr(implode(', ', array_keys($ct)), 0, 150)];
        }
        $chave = $cpf !== '' ? $cpf : mb_strtolower((string) $nome);
        $clientes[$chave]['nome'] = mb_substr(trim((string) $nome), 0, 150);
        $clientes[$chave]['cpf']  = $cpf;
        $clientes[$chave]['contratos'][] = [
            'id'     => comprovantes_primeiro($ct, ['contratoId', 'contrato_id', 'id_contrato', 'contrato']),
            'status' => comprovantes_primeiro($ct, ['contratoStatusDisplay', 'status_display', 'status']),
        ];
    }
    return ['ok' => true, 'clientes' => array_values($clientes), 'message' => ''];
}

// Procura o cliente do comprovante no SGP e grava o resultado. Devolve [ok, mensagem].
function comprovantes_identificar(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT telefone FROM comprovantes WHERE id = ?");
    $st->execute([$id]);
    $fone = $st->fetchColumn();
    if ($fone === false) {
        return [false, 'Comprovante não encontrado.'];
    }

    $res = ['ok' => true, 'clientes' => [], 'message' => ''];
    foreach (comprovantes_fone_variantes((string) $fone) as $var) {
        $res = comprovantes_sgp_por_telefone($var);
        if (!$res['ok'] || $res['clientes']) {
            break; // erro ou achou: nao precisa tentar a outra forma do numero
        }
    }

    $n = count($res['clientes']);
    if (!$res['ok']) {
        $status = 'erro';
    } elseif ($n === 0) {
        $status = 'nenhum';
    } elseif ($n > COMPROVANTE_SGP_MAX_CLIENTES) {
        $status = 'erro';
        $res['message'] = $n . ' clientes retornados: o filtro de telefone parece não ter sido aplicado';
        $res['clientes'] = [];
    } else {
        $status = $n === 1 ? 'unico' : 'varios';
    }

    $pdo->prepare("UPDATE comprovantes SET cliente_status = ?, cliente_json = ?, cliente_erro = ?, identificado_em = NOW() WHERE id = ?")
        ->execute([
            $status,
            $res['clientes'] ? json_encode($res['clientes'], JSON_UNESCAPED_UNICODE) : null,
            $res['message'] !== '' ? mb_substr($res['message'], 0, 250) : null,
            $id,
        ]);

    $msgs = ['unico' => 'Cliente identificado.', 'varios' => 'Há mais de um cliente com esse telefone.',
             'nenhum' => 'Telefone não encontrado no SGP.', 'erro' => 'Falha na consulta: ' . $res['message']];
    return [$status !== 'erro', $msgs[$status]];
}

// CPF/CNPJ parcialmente oculto
function comprovantes_doc_mascarado(string $d): string
{
    if (strlen($d) === 11) return mask_cpf($d);
    if (strlen($d) === 14) return substr($d, 0, 2) . '.***.***/' . substr($d, 8, 4) . '-' . substr($d, 12);
    return '';
}

function comprovantes_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    return preg_replace('/[^a-z ]/', '', strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']));
}

// Registra os comprovantes de um payload. Devolve quantos novos foram gravados.
function comprovantes_processar(PDO $pdo, array $payload): int
{
    $novos = 0;
    foreach (comprovantes_extrair($payload) as $c) {
        $ja = $pdo->prepare("SELECT 1 FROM comprovantes WHERE wamid = ?");
        $ja->execute([$c['wamid']]);
        if ($ja->fetchColumn()) {
            continue; // o webhook pode repetir a entrega
        }
        $erro    = null;
        $arquivo = comprovantes_baixar_midia($c, $erro);
        $ins = $pdo->prepare("INSERT IGNORE INTO comprovantes
            (wamid, telefone, nome, tipo, mime, nome_arquivo, legenda, media_id, media_url, arquivo, erro_midia, recebido_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $c['wamid'], $c['telefone'], mb_substr($c['nome'], 0, 150) ?: null, $c['tipo'], mb_substr($c['mime'], 0, 80) ?: null,
            mb_substr($c['nome_arquivo'], 0, 150) ?: null, mb_substr($c['legenda'], 0, 500) ?: null,
            $c['media_id'] ?: null, $c['media_url'] ?: null, $arquivo, $erro ? mb_substr($erro, 0, 250) : null, $c['recebido_em'],
        ]);
        if ($ins->rowCount() > 0) {
            $novos++;
            try {
                comprovantes_identificar($pdo, (int) $pdo->lastInsertId());
            } catch (Throwable $e) {
                // a identificacao pode ser refeita pelo botao na pagina
            }
        }
    }
    return $novos;
}

// 557398441458 -> (73) 98441-458x
function comprovantes_fone_fmt(string $f): string
{
    if (strpos($f, '55') === 0 && strlen($f) >= 12) {
        $f = substr($f, 2);
    }
    if (strlen($f) === 11) return sprintf('(%s) %s-%s', substr($f, 0, 2), substr($f, 2, 5), substr($f, 7));
    if (strlen($f) === 10) return sprintf('(%s) %s-%s', substr($f, 0, 2), substr($f, 2, 4), substr($f, 6));
    return $f;
}
