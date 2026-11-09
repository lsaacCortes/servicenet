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
    curl_close($ch);
    return [$body === false ? null : $body, $http];
}

// Baixa o arquivo na Meta (o link expira em minutos). Devolve o nome do arquivo salvo ou null.
function comprovantes_baixar_midia(array $c): ?string
{
    $token = defined('META_WHATSAPP_TOKEN') ? (string) META_WHATSAPP_TOKEN : '';
    if ($token === '') {
        return null;
    }
    $mime = $c['mime'];
    if (!isset(COMPROVANTE_EXT[$mime])) {
        return null;
    }

    $url = $c['media_url'];
    // So baixa de dominios da Meta/WhatsApp (evita usar o token em URL arbitraria)
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $ok = $host !== '' && (substr($host, -10) === '.fbsbx.com' || substr($host, -13) === '.facebook.com'
        || substr($host, -12) === '.whatsapp.net' || substr($host, -11) === '.fbcdn.net');
    [$body, $http] = $ok ? comprovantes_http_get($url, $token) : [null, 0];

    // Plano B: pergunta o link novo pelo id da midia
    if (($body === null || $http !== 200) && $c['media_id'] !== '') {
        [$meta, $h2] = comprovantes_http_get('https://graph.facebook.com/v21.0/' . rawurlencode($c['media_id']), $token);
        $j = $meta !== null && $h2 === 200 ? json_decode($meta, true) : null;
        if (!empty($j['url'])) {
            [$body, $http] = comprovantes_http_get($j['url'], $token);
        }
    }
    if ($body === null || $http !== 200 || $body === '' || strlen($body) > COMPROVANTE_MAX_BYTES) {
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
    return @file_put_contents(COMPROVANTE_DIR . '/' . $nome, $body) === false ? null : $nome;
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
        $arquivo = comprovantes_baixar_midia($c);
        $ins = $pdo->prepare("INSERT IGNORE INTO comprovantes
            (wamid, telefone, nome, tipo, mime, nome_arquivo, legenda, media_id, media_url, arquivo, recebido_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $c['wamid'], $c['telefone'], mb_substr($c['nome'], 0, 150) ?: null, $c['tipo'], mb_substr($c['mime'], 0, 80) ?: null,
            mb_substr($c['nome_arquivo'], 0, 150) ?: null, mb_substr($c['legenda'], 0, 500) ?: null,
            $c['media_id'] ?: null, $c['media_url'] ?: null, $arquivo, $c['recebido_em'],
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
