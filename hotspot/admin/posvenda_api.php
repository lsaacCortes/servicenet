<?php
require_once 'session.php';
require_once 'db.php';
require_once 'schema.php';
require_once 'posvenda_lib.php';
require_once 'mensagens_lib.php';

admin_require_login(true);
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
ensure_schema($pdo);

$action = $_REQUEST['action'] ?? '';
$ehPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($ehPost) {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        out(['success' => false, 'message' => 'Sessão inválida. Recarregue a página.'], 403);
    }
}

// Expressoes reutilizadas nas consultas
const PV_DIAS    = 'DATEDIFF(CURDATE(), DATE(ativado_em))';
const PV_ABERTO  = "status <> 'concluido'";
// Cliente respondeu ao disparo e ninguem tratou a mensagem ainda
const PV_RESPONDEU = "(status <> 'concluido' AND EXISTS (SELECT 1 FROM chatmix_mensagens m WHERE m.id_posvenda = posvenda.id AND m.status = 'novo'))";

function pv_filtro_sql(string $filtro): string
{
    switch ($filtro) {
        case 'respondeu':    return PV_RESPONDEU;
        case 'prioridade':   return PV_ABERTO . ' AND ' . PV_DIAS . ' >= ' . POSVENDA_DIAS_ALVO;
        case 'aguardando':   return "status = 'pendente' AND " . PV_DIAS . ' < ' . POSVENDA_DIAS_ALVO;
        case 'em_contato':   return "status = 'em_contato'";
        case 'sem_resposta': return "status = 'sem_resposta'";
        case 'concluidos':   return "status = 'concluido'";
        default:             return PV_ABERTO; // em aberto
    }
}

function pv_linha(array $r): array
{
    $dias = (int) $r['dias'];
    return [
        'id'              => (int) $r['id'],
        'nome'            => $r['nome'],
        'telefone'        => $r['telefone'],
        'bairro'          => $r['bairro'],
        'cidade'          => $r['cidade'],
        'status_contrato' => $r['status_contrato'],
        'ativado_em'      => $r['ativado_em'],
        'dias'            => $dias,
        'prioridade'      => $r['status'] !== 'concluido' && $dias >= POSVENDA_DIAS_ALVO,
        'status'          => $r['status'],
        'resultado'       => $r['resultado'],
        'anotacoes'       => $r['anotacoes'],
        'ultimo_contato_em'  => $r['ultimo_contato_em'],
        'ultimo_contato_por' => $r['ultimo_contato_por'],
        'concluido_em'    => $r['concluido_em'],
        'respondeu'       => !empty($r['msg_nova']),
        'ultima_msg'      => $r['ultima_msg'] ?? null,
        'ultima_msg_em'   => $r['ultima_msg_em'] ?? null,
    ];
}

$adminNome = $_SESSION['admin_nome'] ?? ($_SESSION['admin_user'] ?? 'Admin');

switch ($action) {
    case 'list': {
        $filtro = $_GET['filtro'] ?? 'abertos';
        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $por    = 12;

        $where  = pv_filtro_sql($filtro);
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $dig  = preg_replace('/\D/', '', $q);
            $where .= ' AND (nome LIKE ? OR bairro LIKE ? OR telefone LIKE ?' . ($dig !== '' ? ' OR cpfcnpj LIKE ?' : '') . ')';
            array_push($params, $like, $like, $like);
            if ($dig !== '') {
                $params[] = '%' . $dig . '%';
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM posvenda WHERE $where");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $paginas = max(1, (int) ceil($total / $por));
        $page    = min($page, $paginas);
        $offset  = ($page - 1) * $por;

        // Prioridades primeiro (as mais atrasadas no topo); depois os mais recentes. Concluidos: ultimos concluidos.
        $ordem = $filtro === 'concluidos'
            ? 'concluido_em DESC'
            : 'msg_nova DESC, (status <> \'concluido\' AND ' . PV_DIAS . ' >= ' . POSVENDA_DIAS_ALVO . ') DESC,
               CASE WHEN ' . PV_DIAS . ' >= ' . POSVENDA_DIAS_ALVO . ' THEN ativado_em END ASC,
               ativado_em DESC';

        $stmt = $pdo->prepare("SELECT *, " . PV_DIAS . " AS dias, " . PV_RESPONDEU . " AS msg_nova,
                (SELECT m.texto FROM chatmix_mensagens m WHERE m.id_posvenda = posvenda.id ORDER BY m.recebido_em DESC, m.id DESC LIMIT 1) AS ultima_msg,
                (SELECT m.recebido_em FROM chatmix_mensagens m WHERE m.id_posvenda = posvenda.id ORDER BY m.recebido_em DESC, m.id DESC LIMIT 1) AS ultima_msg_em
            FROM posvenda WHERE $where ORDER BY $ordem LIMIT $por OFFSET $offset");
        $stmt->execute($params);
        $linhas = array_map('pv_linha', $stmt->fetchAll());

        $c = $pdo->query("SELECT
                SUM(status <> 'concluido') AS abertos,
                SUM(" . PV_RESPONDEU . ") AS respondeu,
                SUM(status <> 'concluido' AND " . PV_DIAS . " >= " . POSVENDA_DIAS_ALVO . ") AS prioridade,
                SUM(status = 'pendente' AND " . PV_DIAS . " < " . POSVENDA_DIAS_ALVO . ") AS aguardando,
                SUM(status = 'em_contato') AS em_contato,
                SUM(status = 'sem_resposta') AS sem_resposta,
                SUM(status = 'concluido') AS concluidos
            FROM posvenda")->fetch();

        out([
            'success'  => true,
            'rows'     => $linhas,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $paginas,
            'counts'   => array_map('intval', array_map(fn($v) => $v ?? 0, $c)),
            'inicio'   => posvenda_inicio($pdo),
            'ultima_sync' => (int) posvenda_cfg_get($pdo, 'posvenda_ultima_sync'),
        ]);
    }

    case 'sync':
        if (!$ehPost) out(['success' => false, 'message' => 'Método inválido.'], 405);
        $res = posvenda_sync($pdo, !empty($_POST['auto']));
        if (empty($res['skipped'])) {
            admin_log($pdo, 'posvenda_sync', '', $res['success'] ? ($res['novos'] . ' novo(s)') : $res['message']);
        }
        out($res, $res['success'] ? 200 : 502);

    case 'update': {
        if (!$ehPost) out(['success' => false, 'message' => 'Método inválido.'], 405);
        $id        = (int) ($_POST['id'] ?? 0);
        $status    = $_POST['status'] ?? '';
        $resultado = $_POST['resultado'] ?? '';
        $anotacoes = trim($_POST['anotacoes'] ?? '');

        if (!in_array($status, ['pendente', 'em_contato', 'sem_resposta', 'concluido'], true)) {
            out(['success' => false, 'message' => 'Status inválido.'], 422);
        }
        if ($status === 'concluido') {
            if (!in_array($resultado, ['satisfeito', 'problema_resolvido', 'precisa_visita', 'outro'], true)) {
                out(['success' => false, 'message' => 'Informe o resultado do atendimento para concluir.'], 422);
            }
        } else {
            $resultado = null;
        }

        $stmt = $pdo->prepare("SELECT status, nome FROM posvenda WHERE id = ?");
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if (!$atual) out(['success' => false, 'message' => 'Registro não encontrado.'], 404);

        // Registrar contato (qualquer status exceto voltar a pendente) atualiza o ultimo contato
        $contatou = $status !== 'pendente';
        // concluido_em vem antes de status: o MySQL avalia o SET da esquerda para a direita
        $pdo->prepare("UPDATE posvenda SET
                concluido_em = IF(? = 'concluido', IF(status = 'concluido', concluido_em, NOW()), NULL),
                status = ?, resultado = ?, anotacoes = ?,
                ultimo_contato_em  = IF(?, NOW(), ultimo_contato_em),
                ultimo_contato_por = IF(?, ?, ultimo_contato_por)
            WHERE id = ?")
            ->execute([$status, $status, $resultado, $anotacoes ?: null, (int) $contatou, (int) $contatou, $adminNome, $id]);

        // Registrar o atendimento (fora "pendente") trata as respostas do cliente que estavam novas
        if ($contatou) {
            $pdo->prepare("UPDATE chatmix_mensagens SET status = 'tratado', tratado_por = ?, tratado_em = NOW()
                           WHERE id_posvenda = ? AND status = 'novo'")
                ->execute([$adminNome, $id]);
        }

        admin_log($pdo, 'posvenda_atualizar', $atual['nome'], $atual['status'] . ' -> ' . $status . ($resultado ? " ($resultado)" : ''));
        out(['success' => true, 'message' => $status === 'concluido' ? 'Atendimento concluído.' : 'Atendimento atualizado.']);
    }

    // Envio avulso (teste ou mensagem manual) para qualquer numero, sem tocar na lista
    case 'enviar_avulso': {
        if (!$ehPost) out(['success' => false, 'message' => 'Método inválido.'], 405);
        if (admin_perfil($pdo) !== 'admin') {
            out(['success' => false, 'message' => 'Seu perfil é somente de visualização.'], 403);
        }
        $nome  = trim($_POST['nome'] ?? '');
        $fone  = trim($_POST['telefone'] ?? '');
        if ($nome === '' || mb_strlen($nome) > 60) out(['success' => false, 'message' => 'Informe o nome (até 60 caracteres).'], 422);
        $intl = posvenda_fone_intl($fone);
        if ($intl === '') out(['success' => false, 'message' => 'Telefone inválido. Use DDD + número.'], 422);

        $atendente = trim(explode(' ', trim($adminNome))[0]);
        $res = posvenda_chatmix_enviar($nome, $fone, $atendente);
        admin_log($pdo, $res['ok'] ? 'posvenda_envio_avulso' : 'posvenda_envio_falha', $nome,
            'template -> ' . substr($intl, 0, -4) . '****' . ($res['ok'] ? '' : ' | ' . $res['message']));
        out(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 502);
    }

    // Envia a mensagem de pos-venda pelo ChatMix e marca como "em contato"
    case 'enviar': {
        if (!$ehPost) out(['success' => false, 'message' => 'Método inválido.'], 405);
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT nome, telefone, status FROM posvenda WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) out(['success' => false, 'message' => 'Registro não encontrado.'], 404);
        if ($r['status'] === 'concluido') out(['success' => false, 'message' => 'Atendimento já concluído.'], 422);

        $atendente = trim(explode(' ', trim($adminNome))[0]);
        $res = posvenda_chatmix_enviar($r['nome'], (string) $r['telefone'], $atendente);
        if (!$res['ok']) {
            admin_log($pdo, 'posvenda_envio_falha', $r['nome'], $res['message']);
            out(['success' => false, 'message' => $res['message']], 502);
        }
        $pdo->prepare("UPDATE posvenda SET status = IF(status = 'pendente', 'em_contato', status),
                       ultimo_contato_em = NOW(), ultimo_contato_por = ? WHERE id = ?")
            ->execute([$adminNome, $id]);
        // A partir daqui, respostas desse numero (por alguns dias) vao para o pos-venda
        mensagens_registrar_envio($pdo, $id, (string) $r['telefone'], $adminNome);
        admin_log($pdo, 'posvenda_envio', $r['nome'], 'mensagem enviada via ChatMix');
        out(['success' => true, 'message' => 'Mensagem enviada para ' . explode(' ', trim($r['nome']))[0] . '.']);
    }

    case 'config': {
        if (!$ehPost) out(['success' => false, 'message' => 'Método inválido.'], 405);
        if (admin_perfil($pdo) !== 'admin') {
            out(['success' => false, 'message' => 'Seu perfil é somente de visualização.'], 403);
        }
        $inicio = $_POST['inicio'] ?? '';
        $d = DateTime::createFromFormat('Y-m-d', $inicio);
        if (!$d || $d->format('Y-m-d') !== $inicio || $inicio > date('Y-m-d')) {
            out(['success' => false, 'message' => 'Informe uma data válida (não futura).'], 422);
        }
        posvenda_cfg_set($pdo, 'posvenda_inicio', $inicio);
        admin_log($pdo, 'posvenda_config', '', 'inicio ' . $inicio);
        out(['success' => true, 'message' => 'Data de início atualizada.']);
    }

    default:
        out(['success' => false, 'message' => 'Ação inválida.'], 400);
}
