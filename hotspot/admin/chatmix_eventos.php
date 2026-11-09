<?php
// Tela temporaria: mostra os ultimos payloads brutos recebidos do webhook do ChatMix.
require_once 'session.php';
admin_require_login();
require_once 'db.php';
require_once 'schema.php';

date_default_timezone_set('America/Sao_Paulo');
$pdo = getDB();
ensure_schema($pdo);
if (admin_perfil($pdo) !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito a administradores.');
}

$linhas = $pdo->query("SELECT id, payload, cabecalhos, ip_origem, created_at FROM chatmix_eventos ORDER BY id DESC LIMIT 20")->fetchAll();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventos ChatMix</title>
<style>
    body { font-family: system-ui, sans-serif; margin: 20px; background: #f5f6f8; color: #222; }
    .ev { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
    .ev small { color: #666; }
    pre { white-space: pre-wrap; word-break: break-all; margin: 8px 0 0; font-size: 12px; }
</style>
</head>
<body>
<h2>Últimos eventos do webhook ChatMix</h2>
<p><small>Mostra os 20 mais recentes (payload bruto). Atualize a página para ver novos.</small></p>
<?php if (!$linhas): ?>
    <p>Nenhum evento recebido ainda.</p>
<?php endif; ?>
<?php foreach ($linhas as $l):
    $j = json_decode($l['payload'], true);
    $txt = $j !== null ? json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $l['payload'];
?>
    <div class="ev">
        <small>#<?= (int) $l['id'] ?> · <?= $h($l['created_at']) ?> · <?= $h($l['ip_origem']) ?></small>
        <pre><?= $h($txt) ?></pre>
        <details><summary><small>headers</small></summary>
            <pre><?= $h($l['cabecalhos']) ?></pre>
        </details>
    </div>
<?php endforeach; ?>
</body>
</html>
