<?php
// Copie este arquivo para config.php e preencha com os dados reais.
// O config.php NAO vai para o git (ver .gitignore).
define('DB_HOST', 'localhost');
define('DB_USER', 'sn_hotspot');
define('DB_PASS', 'TROQUE_ESTA_SENHA');
define('DB_NAME', 'servicenet_hotspot');
define('DB_CHARSET', 'utf8mb4');

// Roteador MikroTik (API). Use um usuario proprio do painel, com permissoes minimas
// (read, write, api) e acesso liberado so para este servidor / VPN.
define('ROUTER_HOST', '');          // IP do roteador (ideal: IP interno da VPN)
define('ROUTER_PORT', 8728);        // 8728 = api | 8729 = api-ssl
define('ROUTER_USER', '');
define('ROUTER_PASS', '');
define('ROUTER_SSL',  false);
define('ROUTER_MOCK', false);       // true = dados simulados, para testar a tela

// Alertas (opcional): aviso tambem no Telegram. Crie um bot com o @BotFather e
// descubra o chat id mandando uma mensagem ao bot e abrindo /getUpdates.
define('ALERT_TELEGRAM_TOKEN', '');
define('ALERT_TELEGRAM_CHAT',  '');
// Opcional: permite agendar alertas_cron.php por URL (?token=...). Deixe vazio para so aceitar linha de comando.
define('ALERT_CRON_TOKEN', '');

// Opcional: senha inicial do usuario 'admin' criado pelo setup.php.
// Se ficar vazia, o setup gera uma senha aleatoria e a exibe uma unica vez.
define('ADMIN_INITIAL_PASSWORD', '');

// Pos-venda: API do SGP (mesmos dados da URA usada em controller.php).
define('SGP_BASE_URL', 'http://IP_DO_SGP:8000');
define('SGP_APP',      'SGP');
define('SGP_TOKEN',    'TROQUE_PELO_TOKEN');

// Pos-venda: API de disparos do ChatMix (menu "Chaves para Acesso").
define('CHATMIX_URL',   'https://envios.bulkv2.chatmix.com.br/api');
define('CHATMIX_KEY',   'TROQUE_PELA_KEY');
define('CHATMIX_TOKEN', 'TROQUE_PELO_TOKEN');
// Codigo do template aprovado na Meta (vazio = envia texto livre).
define('CHATMIX_TEMPLATE', '');
// Webhook de canal do ChatMix (comprovantes recebidos): segredo que vai na URL cadastrada
// no ChatMix:  https://SEU_DOMINIO/hotspot/admin/chatmix_webhook.php?s=SEGREDO
// Gere um valor longo e aleatorio. Vazio = webhook desativado.
define('CHATMIX_WEBHOOK_SECRET', '');
// Opcional: token de acesso da Meta (WhatsApp Business) para baixar a imagem/PDF dos comprovantes.
// Sem ele a pagina de comprovantes lista quem enviou e quando, mas nao mostra o arquivo.
define('META_WHATSAPP_TOKEN', '');
// ID do numero de WhatsApp na Meta (aparece como metadata.phone_number_id no payload do webhook).
// Usado para ENVIAR mensagens de texto pela API da Meta.
define('META_PHONE_NUMBER_ID', '');

// IA de atendimento (Google Gemini). Chave criada no Google AI Studio.
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL',   'gemini-2.5-flash');
// '' = desligada | 'sombra' = analisa e registra o que responderia, SEM enviar nada ao cliente.
define('IA_MODO', '');
// Para onde a IA encaminha o que nao consegue resolver (so digitos, DDD + numero).
define('IA_ATENDIMENTO_FONE', '73999547814');
