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

// Opcional: senha inicial do usuario 'admin' criado pelo setup.php.
// Se ficar vazia, o setup gera uma senha aleatoria e a exibe uma unica vez.
define('ADMIN_INITIAL_PASSWORD', '');
