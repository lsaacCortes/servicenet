<?php
// =============================================================
// Campanha de aniversario: upgrade 50 -> 300 Mega (3 meses pelo preco do 50, 4o mes volta ao normal)
//  - cliques nos botoes do template ("quero o upgrade!" / "nao quero o upgrade!") chegam pelo webhook
//  - a troca de plano no SGP e feita a mao; aqui so se registra o status e as datas
// =============================================================
require_once __DIR__ . '/mensagens_lib.php';

const UPGRADE_MESES_PROMO   = 3;  // meses pelo preco do plano antigo; no seguinte volta ao valor normal
const UPGRADE_DIAS_AVISO    = 15; // lembrar o cliente quantos dias antes do fim da promocao

const UPGRADE_STATUS = [
    'alvo'        => 'Aguardando resposta',
    'quero'       => 'Quer o upgrade',
    'nao_quero'   => 'Não quer',
    'confirmado'  => 'Confirmado',
    'em_promocao' => 'Em promoção',
    'efetivado'   => 'Permaneceu no 300',
    'desistiu'    => 'Desistiu / voltou ao 50',
];

function upgrade_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    return trim(preg_replace('/[^a-z0-9 ]/', '', $s));
}

// 'quero' | 'nao_quero' | null para o texto de uma mensagem
function upgrade_resposta(string $texto): ?string
{
    $t = upgrade_norm($texto);
    if ($t === 'nao quero o upgrade') {
        return 'nao_quero';
    }
    if ($t === 'quero o upgrade') {
        return 'quero';
    }
    return null;
}

// Lista de telefones ("(75) 98319-8309 / ...") -> ",chave1,chave2,"
function upgrade_chaves(string $telefones): string
{
    $chaves = [];
    foreach (preg_split('/[\/;,]+/', $telefones) as $f) {
        $k = mensagens_fone_chave($f);
        if (strlen($k) >= 10) {
            $chaves[$k] = true;
        }
    }
    return $chaves ? ',' . implode(',', array_keys($chaves)) . ',' : ',';
}

function upgrade_hist(PDO $pdo, int $id, string $evento, ?string $por): void
{
    $pdo->prepare("INSERT INTO upgrade_historico (id_cliente, evento, por) VALUES (?, ?, ?)")
        ->execute([$id, mb_substr($evento, 0, 255), $por !== null ? mb_substr($por, 0, 100) : null]);
}

// Data em que o preco volta ao normal (primeiro dia do periodo cobrado cheio)
function upgrade_data_volta(string $inicio): string
{
    return (new DateTimeImmutable($inicio))->modify('+' . UPGRADE_MESES_PROMO . ' months')->format('Y-m-d');
}

// Trata os cliques de botao de um payload do webhook. Devolve quantos foram registrados.
function upgrade_processar(PDO $pdo, array $payload): int
{
    $n = 0;
    foreach (mensagens_extrair($payload) as $m) {
        $resp = upgrade_resposta($m['texto']);
        if ($resp === null) {
            continue;
        }
        // O webhook pode repetir a entrega: o wamid evita contar duas vezes
        $ja = $pdo->prepare("SELECT 1 FROM chatmix_mensagens WHERE wamid = ?");
        $ja->execute([$m['wamid']]);
        if ($ja->fetchColumn()) {
            continue;
        }
        $chave = mensagens_fone_chave($m['telefone']);
        if (strlen($chave) < 10) {
            continue;
        }

        $st = $pdo->prepare("SELECT id, status FROM upgrade_clientes WHERE fones_chave LIKE ? ORDER BY (status='alvo') DESC, id LIMIT 1");
        $st->execute(['%,' . $chave . ',%']);
        $cli = $st->fetch(PDO::FETCH_ASSOC);

        if (!$cli) {
            // Quem nao esta na lista (outro numero, cliente novo...) entra mesmo assim para nao perder a resposta
            $pdo->prepare("INSERT INTO upgrade_clientes (nome, telefones, fones_chave, origem, status, respondeu_em)
                           VALUES (?, ?, ?, 'resposta', ?, ?)")
                ->execute([$m['nome_perfil'] !== '' ? mb_substr($m['nome_perfil'], 0, 150) : $m['telefone'], $m['telefone'], ',' . $chave . ',', $resp, $m['recebido_em']]);
            $id = (int) $pdo->lastInsertId();
            upgrade_hist($pdo, $id, 'Clicou "' . ($resp === 'quero' ? 'quero o upgrade!' : 'não quero o upgrade!') . '" (número fora da lista)', 'WhatsApp');
        } else {
            $id = (int) $cli['id'];
            // Depois que a equipe confirmou/iniciou, um clique novo nao muda o status: so fica no historico
            $andamento = in_array($cli['status'], ['confirmado', 'em_promocao', 'efetivado'], true);
            if (!$andamento) {
                $pdo->prepare("UPDATE upgrade_clientes SET status = ?, respondeu_em = ? WHERE id = ?")
                    ->execute([$resp, $m['recebido_em'], $id]);
            }
            upgrade_hist($pdo, $id, 'Clicou "' . ($resp === 'quero' ? 'quero o upgrade!' : 'não quero o upgrade!') . '"' . ($andamento ? ' (status mantido)' : ''), 'WhatsApp');
        }
        // Registra o wamid para nao reprocessar (mesma tabela das respostas do pos-venda)
        $pdo->prepare("INSERT IGNORE INTO chatmix_mensagens (wamid, telefone, fone_chave, nome_perfil, tipo, texto, destino, recebido_em, status)
                       VALUES (?, ?, ?, ?, ?, ?, 'outras', ?, 'tratado')")
            ->execute([$m['wamid'], $m['telefone'], $chave, $m['nome_perfil'] !== '' ? mb_substr($m['nome_perfil'], 0, 150) : null,
                       mb_substr($m['tipo'], 0, 20), $m['texto'], $m['recebido_em']]);
        $n++;
    }
    return $n;
}
