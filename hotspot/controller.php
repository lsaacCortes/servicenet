<?php
session_start();

// Trava de Segurança: Bloqueia acesso direto sem passar pelo MikroTik
if (empty($_GET['mac']) && empty($_GET['link-login'])) {
    // Redireciona o curioso para o site principal da empresa
    header("Location: https://servicenetpro.com.br/");
    exit;
}

// ==========================================
// 1. CONFIGURAÇÕES DA API SGP (URA)
// ==========================================
$sgp_url = "http://177.190.223.11:8000/api/ura/consultacliente/";
$sgp_token = "6f2ca57b-9c3e-4538-9e33-2cf80963f828";
$sgp_app = "SGP";

// ==========================================
// 2. INICIALIZAÇÃO DE VARIÁVEIS (Para a View)
// ==========================================
$mac_cliente = $_GET['mac'] ?? '';
$ip_cliente  = $_GET['ip'] ?? '';
$link_login_mikrotik = $_GET['link-login'] ?? 'http://10.5.50.1/login';

$mensagem_erro = $_SESSION['hotspot_erro'] ?? '';
$login_sucesso = $_SESSION['hotspot_sucesso'] ?? false;

unset($_SESSION['hotspot_erro']);
unset($_SESSION['hotspot_sucesso']);

// ==========================================
// 3. PROCESSAMENTO DO FORMULÁRIO (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');

    if (!empty($cpf) && strlen($cpf) === 11) {

        $post_data = [
            'app' => $sgp_app,
            'token' => $sgp_token,
            'cpfcnpj' => $cpf,
            'status' => '1' // Parâmetro da API para buscar apenas normais
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $sgp_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data 
        ]);

        $resposta_sgp = curl_exec($curl);
        $http_status  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $dados = json_decode($resposta_sgp, true);

        // ==========================================
        // 4. VALIDAÇÃO ESTRITA COM AS CHAVES CORRETAS
        // ==========================================
        $cliente_ativo = false;

        if ($http_status == 200 && !empty($dados['contratos'])) {
            foreach ($dados['contratos'] as $contrato) {
                // Usando os nomes exatos das chaves reveladas no raio-X do SGP
                $status_codigo = (string)($contrato['contratoStatus'] ?? '');
                $status_texto  = strtolower(trim($contrato['contratoStatusDisplay'] ?? ''));

                if ($status_codigo === '1' || $status_texto === 'ativo') {
                    $cliente_ativo = true;
                    break; // Achou um ativo, não precisa checar os outros
                }
            }
        }

        if ($cliente_ativo) {
            $_SESSION['hotspot_sucesso'] = true;
        } else {
            $_SESSION['hotspot_erro'] = "CPF não localizado ou contrato possui pendências financeiras.";
        }

    } else {
        $_SESSION['hotspot_erro'] = "Por favor, digite um CPF válido com 11 dígitos.";
    }

    // Redireciona via GET para limpar o POST (Evita popup de reenvio no F5)
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    $qs = http_build_query(['mac' => $mac_cliente, 'ip' => $ip_cliente, 'link-login' => $link_login_mikrotik]);
    header("Location: {$redirect}" . ($qs ? "?{$qs}" : ''));
    exit; // Mata o script aqui para o redirecionamento funcionar imediatamente
}
?>