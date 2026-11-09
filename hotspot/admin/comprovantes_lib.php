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
        $novos += $ins->rowCount();
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
