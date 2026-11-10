<?php
// =============================================================
// Roteamento das mensagens recebidas no canal de disparos do ChatMix
//  - imagem/PDF: tratados por comprovantes_lib.php
//  - resposta de quem recebeu o pos-venda nos ultimos dias: pos-venda
//  - o resto: caixa "Outras mensagens", com etiqueta por palavras-chave
// =============================================================

const MENSAGENS_DIAS_POSVENDA = 7; // ate quantos dias apos o disparo uma resposta conta como resposta ao pos-venda

// Chave que ignora 55 e o 9 do celular: DDD + ultimos 8 digitos
function mensagens_fone_chave(string $f): string
{
    $f = preg_replace('/\D/', '', $f);
    if (strpos($f, '55') === 0 && strlen($f) >= 12) {
        $f = substr($f, 2);
    }
    if (strlen($f) < 10) {
        return $f;
    }
    return substr($f, 0, 2) . substr($f, -8);
}

// Registra que o template de pos-venda foi enviado a este cliente
function mensagens_registrar_envio(PDO $pdo, int $idPosvenda, string $telefone, string $por): void
{
    $chave = mensagens_fone_chave($telefone);
    if ($chave === '') {
        return;
    }
    $pdo->prepare("INSERT INTO posvenda_envios (id_posvenda, fone_chave, enviado_por) VALUES (?, ?, ?)")
        ->execute([$idPosvenda, $chave, mb_substr($por, 0, 100)]);
}

// Texto legivel de uma mensagem do payload; null = ignorar (ex.: reacoes)
function mensagens_texto(array $m): ?string
{
    switch ($m['type'] ?? '') {
        case 'text':        return (string) ($m['text']['body'] ?? '');
        case 'button':      return (string) ($m['button']['text'] ?? $m['button']['payload'] ?? '');
        case 'interactive':
            $i = $m['interactive'] ?? [];
            return (string) ($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '');
        case 'audio':       return '[áudio]';
        case 'video':       return '[vídeo]';
        case 'sticker':     return '[figurinha]';
        case 'location':    return '[localização]';
        case 'contacts':    return '[contato]';
        case 'image':       return '[imagem]' . (isset($m['image']['caption']) ? ' ' . $m['image']['caption'] : '');
        case 'document':    return '[documento]' . (isset($m['document']['filename']) ? ' ' . $m['document']['filename'] : '');
        case 'reaction':
        case 'system':
        case 'request_welcome':
            return null;
        default:            return '[mensagem não suportada]';
    }
}

// Etiqueta de triagem por palavras-chave (so ajuda visual: na duvida, 'outros')
function mensagens_etiqueta(string $texto): string
{
    $t = ' ' . comprovantes_norm($texto) . ' ';
    $tem = function (array $palavras) use ($t): bool {
        foreach ($palavras as $p) {
            if (strpos($t, $p) !== false) return true;
        }
        return false;
    };
    if ($tem([' sem internet', ' sem sinal', ' caiu ', ' lenta', ' lento', ' nao funciona', ' nao conecta', ' nao esta funcionando', ' travando', ' sem conexao', ' suporte', ' tecnico'])) {
        return 'suporte';
    }
    if ($tem([' ja paguei', ' paguei', ' ja foi pago', ' ja foi paga', ' pagamento feito', ' pago ontem', ' pix feito', ' fiz o pix', ' fiz pix', ' comprovante'])) {
        return 'ja_pagou';
    }
    if ($tem([' vou pagar', ' vou paga', ' ja vou pagar', ' pago hoje', ' pago amanha', ' pago sexta', ' pago segunda', ' pago ate', ' pagarei', ' pagar hoje', ' pagar amanha', ' pagar ate', ' to pagando', ' vou fazer o pix'])) {
        return 'vai_pagar';
    }
    if ($tem([' ok ', ' obrigado', ' obrigada', ' valeu', ' blz ', ' beleza', ' certo ', ' tudo bem', ' tudo certo', ' tudo otimo', ' otimo', ' show ', ' bom dia', ' boa tarde', ' boa noite'])) {
        return 'cordial';
    }
    return 'outros';
}

// Extrai as mensagens que NAO sao comprovante (imagem/PDF so vira mensagem se o tipo nao for aceito la)
function mensagens_extrair(array $p): array
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
                if ($tipo === 'image') {
                    continue; // vai para comprovantes
                }
                if ($tipo === 'document') {
                    $mime = (string) ($m['document']['mime_type'] ?? '');
                    if ($mime === 'application/pdf' || strpos($mime, 'image/') === 0) {
                        continue; // vai para comprovantes
                    }
                }
                $texto = mensagens_texto($m);
                $fone  = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                if ($texto === null || $fone === '' || empty($m['id'])) {
                    continue;
                }
                $achados[] = [
                    'wamid'       => (string) $m['id'],
                    'telefone'    => $fone,
                    'nome_perfil' => $nomes[$fone] ?? '',
                    'tipo'        => (string) $tipo,
                    'texto'       => mb_substr(trim($texto), 0, 1000),
                    'recebido_em' => date('Y-m-d H:i:s', (int) ($m['timestamp'] ?? time())),
                ];
            }
        }
    }
    return $achados;
}

// Nome do cliente no SGP pelo telefone ('' se nao achar; 'Vários clientes' se ambiguo)
function mensagens_cliente_sgp(string $telefone): string
{
    foreach (comprovantes_fone_variantes($telefone) as $var) {
        $res = comprovantes_sgp_por_telefone($var);
        if (!$res['ok']) {
            return '';
        }
        $n = count($res['clientes']);
        if ($n > COMPROVANTE_SGP_MAX_CLIENTES) {
            return '';
        }
        if ($n === 1) {
            return mb_substr((string) $res['clientes'][0]['nome'], 0, 150);
        }
        if ($n > 1) {
            return 'Vários clientes';
        }
    }
    return '';
}

// Roteia e grava as mensagens de um payload. Devolve quantas novas foram gravadas.
function mensagens_processar(PDO $pdo, array $payload): int
{
    $novas = 0;
    foreach (mensagens_extrair($payload) as $m) {
        $chave = mensagens_fone_chave($m['telefone']);

        // O webhook pode repetir a entrega
        $ja = $pdo->prepare("SELECT 1 FROM chatmix_mensagens WHERE wamid = ?");
        $ja->execute([$m['wamid']]);
        if ($ja->fetchColumn()) {
            continue;
        }

        // Foi resposta a um disparo de pos-venda nosso? (o mais recente dentro do prazo)
        $st = $pdo->prepare("SELECT id_posvenda FROM posvenda_envios
                             WHERE fone_chave = ? AND enviado_em > NOW() - INTERVAL " . MENSAGENS_DIAS_POSVENDA . " DAY
                             ORDER BY enviado_em DESC LIMIT 1");
        $st->execute([$chave]);
        $idPv = $st->fetchColumn();

        $destino = $idPv !== false ? 'posvenda' : 'outras';
        $cliente = '';
        if ($destino === 'outras') {
            try {
                $cliente = mensagens_cliente_sgp($m['telefone']);
            } catch (Throwable $e) {
                // identificacao e opcional
            }
        }

        $pdo->prepare("INSERT IGNORE INTO chatmix_mensagens
            (wamid, telefone, fone_chave, nome_perfil, tipo, texto, destino, id_posvenda, etiqueta, cliente_nome, recebido_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $m['wamid'], $m['telefone'], $chave, mb_substr($m['nome_perfil'], 0, 150) ?: null, mb_substr($m['tipo'], 0, 20),
                $m['texto'] !== '' ? $m['texto'] : null, $destino, $idPv !== false ? (int) $idPv : null,
                mensagens_etiqueta($m['texto']), $cliente !== '' ? $cliente : null, $m['recebido_em'],
            ]);
        $novas++;
    }
    return $novas;
}
