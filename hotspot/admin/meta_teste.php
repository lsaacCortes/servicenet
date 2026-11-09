<?php
// Pagina TEMPORARIA de teste: envia uma mensagem de texto pela API da Meta (WhatsApp Cloud API)
// para confirmar que o token tem permissao de envio. Remover depois do teste.
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';

$pdo = getDB();
ensure_schema($pdo);
if (admin_perfil($pdo) !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito a administradores.');
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$resultado = null;
$fone  = preg_replace('/\D/', '', $_POST['fone'] ?? '73998441458');
$texto = trim($_POST['texto'] ?? 'Teste de envio da ServiceNet. Pode ignorar esta mensagem.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $resultado = 'Sessão inválida. Recarregue a página.';
    } elseif (!defined('META_WHATSAPP_TOKEN') || trim(META_WHATSAPP_TOKEN) === '') {
        $resultado = 'META_WHATSAPP_TOKEN não configurado no config.php.';
    } elseif (!defined('META_PHONE_NUMBER_ID') || trim(META_PHONE_NUMBER_ID) === '') {
        $resultado = "META_PHONE_NUMBER_ID não configurado no config.php (use o valor de metadata.phone_number_id do webhook).";
    } elseif (strlen($fone) < 10 || mb_strlen($texto) < 1) {
        $resultado = 'Informe um telefone com DDD e um texto.';
    } else {
        $destino = strpos($fone, '55') === 0 && strlen($fone) >= 12 ? $fone : '55' . $fone;
        $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(trim(META_PHONE_NUMBER_ID)) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . trim(META_WHATSAPP_TOKEN), 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'messaging_product' => 'whatsapp',
                'to'   => $destino,
                'type' => 'text',
                'text' => ['body' => mb_substr($texto, 0, 1000)],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        admin_log($pdo, 'meta_teste_envio', substr($destino, 0, -4) . '****', 'HTTP ' . $http);
        $resultado = $resp === false
            ? 'Falha de conexão: ' . $err
            : 'HTTP ' . $http . "\n" . json_encode(json_decode($resp, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teste de envio Meta</title>
<style>
    body { font-family: system-ui, sans-serif; margin: 20px; background: #f5f6f8; color: #222; max-width: 640px; }
    label { display: block; margin: 12px 0 4px; font-weight: 600; }
    input, textarea { width: 100%; padding: 8px; box-sizing: border-box; }
    button { margin-top: 14px; padding: 10px 18px; font-weight: 700; cursor: pointer; }
    pre { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; white-space: pre-wrap; word-break: break-all; }
</style>
</head>
<body>
<h2>Teste de envio pela API da Meta</h2>
<p>Texto livre só chega se o destinatário tiver escrito para o número nas <b>últimas 24 horas</b>.
   Mande uma mensagem para o número de disparos antes de testar.</p>
<form method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <label for="fone">Telefone (com DDD)</label>
    <input id="fone" name="fone" value="<?= $h($fone) ?>">
    <label for="texto">Mensagem</label>
    <textarea id="texto" name="texto" rows="3" maxlength="1000"><?= $h($texto) ?></textarea>
    <button>Enviar teste</button>
</form>
<?php if ($resultado !== null): ?>
    <h3>Resultado</h3>
    <pre><?= $h($resultado) ?></pre>
<?php endif; ?>
</body>
</html>
