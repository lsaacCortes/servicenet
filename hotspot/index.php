<?php require_once 'controller.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wi-Fi Livre - ServiceNet Telecom</title>

    <!-- Favicon -->
    <link rel="icon" href="assets/img/logo.PNG" type="image/png">

    <!-- Google Fonts: Raleway -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Tailwind Configuration -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#172a4c', /* Azul Escuro */
                        accent: '#ff4c30',  /* Laranja/Vermelho */
                        white: '#ffffff'
                    },
                    fontFamily: {
                        sans: ['Raleway', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="bg-gradient-to-br from-primary to-[#0a1222] flex items-center justify-center min-h-screen p-4">

    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden relative">

        <!-- Detalhe decorativo no topo -->
        <div class="h-2 w-full bg-accent"></div>

        <!-- Cabeçalho com Logo -->
        <div class="bg-primary py-8 flex justify-center">
            <img src="assets/img/logo.PNG" alt="Logo ServiceNet" class="logo-bordered h-28 w-auto object-contain"
                onerror="this.outerHTML='<div class=\'text-3xl font-extrabold text-white border-4 border-white rounded-2xl px-4 py-2\'>ServiceNet</div>'">
        </div>

        <div class="p-8 pb-4 text-center">
            <?php if (!$login_sucesso): ?>
                <h1 class="text-2xl font-bold text-primary">Wi-Fi Livre</h1>
                <p class="text-gray-500 mt-2 text-sm font-medium">Acesso exclusivo para clientes da ServiceNet Telecom</p>
            <?php endif; ?>
        </div>

        <!-- Conteúdo Principal -->
        <div class="px-8 pb-8">

            <?php if ($login_sucesso): ?>
                <!-- ========================================== -->
                <!-- TELA DE SUCESSO (Aparece após aprovação)   -->
                <!-- ========================================== -->
                <div class="text-center">
                    <div class="flex justify-center mb-6">
                        <svg class="animate-spin h-12 w-12 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-primary mb-2">Autenticação aprovada!</h2>
                    <p class="text-gray-600 mb-8">Liberando sua internet, aguarde...</p>

                    <!-- Formulário invisível que faz o login no MikroTik -->
                    <form id="formMikrotik" action="<?= htmlspecialchars($link_login_mikrotik) ?>" method="post"
                        class="hidden">
                        <input type="hidden" name="username" value="visitante_sgp">
                        <input type="hidden" name="password" value="senha_padrao_123">

                        <!-- MÁGICA DO REDIRECIONAMENTO AQUI -->
                        <input type="hidden" name="dst" value="https://servicenetpro.com.br/site/Home.html">
                    </form>

                    <script>
                        // Aguarda 1,5s para o usuário ler a mensagem e depois envia os dados para a antena
                        setTimeout(() => { document.getElementById('formMikrotik').submit(); }, 1500);
                    </script>
                </div>

            <?php else: ?>
                <!-- ========================================== -->
                <!-- FORMULÁRIO PADRÃO DE LOGIN                 -->
                <!-- ========================================== -->
                <form method="POST" action="">

                    <?php if ($mensagem_erro): ?>
                        <div class="bg-red-50 border-l-4 border-accent text-red-700 p-4 mb-6 rounded-md shadow-sm" role="alert">
                            <p class="font-semibold text-sm"><?= htmlspecialchars($mensagem_erro) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="mb-6">
                        <label class="block text-primary text-sm font-bold mb-2" for="cpf">
                            Digite seu CPF
                        </label>
                        <input
                            class="appearance-none border-2 border-gray-200 rounded-xl w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:border-accent focus:ring-1 focus:ring-accent transition-colors"
                            id="cpf" name="cpf" type="tel" placeholder="000.000.000-00" required maxlength="14"
                            oninput="mascaraCPF(this)">
                    </div>

                    <div class="space-y-4">
                        <button
                            class="bg-primary hover:bg-[#0f1d35] text-white font-bold py-3 px-4 rounded-xl w-full transition duration-300 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 flex justify-center items-center gap-2"
                            type="submit">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z"
                                    clip-rule="evenodd" />
                            </svg>
                            Conectar à Internet
                        </button>

                        <!-- Divisor -->
                        <div class="relative flex py-2 items-center">
                            <div class="flex-grow border-t border-gray-200"></div>
                            <span class="flex-shrink-0 mx-4 text-gray-400 text-xs font-semibold uppercase">OU</span>
                            <div class="flex-grow border-t border-gray-200"></div>
                        </div>

                        <!-- Botão que abre o modal -->
                        <button type="button" class="btn-lead" onclick="abrirModalLead()">
                            Quero ser cliente
                        </button>

                        <!-- Modal de Captação de Lead -->
                        <div id="modalLead" class="modal-overlay" style="display: none;">
                            <div class="modal-content">
                                <span class="modal-close" onclick="fecharModalLead()">&times;</span>
                                <h3>Seja Cliente ServiceNet</h3>
                                <p>Deixe os seus dados e a nossa equipa entrará em contacto com as melhores ofertas para a
                                    sua região.</p>

                                <form id="formLead" onsubmit="enviarLead(event)">
                                    <div class="form-group">
                                        <label for="lead_nome">Nome Completo *</label>
                                        <input type="text" id="lead_nome" name="nome" required placeholder="Seu nome">
                                    </div>

                                    <div class="form-group">
                                        <label for="lead_telefone">WhatsApp / Telefone *</label>
                                        <input type="tel" id="lead_telefone" name="telefone" required
                                            placeholder="(00) 00000-0000">
                                    </div>

                                    <div class="form-group">
                                        <label for="lead_bairro">Bairro / Endereço</label>
                                        <input type="text" id="lead_bairro" name="bairro" placeholder="Seu bairro">
                                    </div>

                                    <button type="submit" id="btnSubmitLead" class="btn-submit">Enviar
                                        Solicitação</button>
                                    <div id="msgLead" class="msg-status"></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Rodapé -->
        <div class="bg-gray-50 text-center py-4 border-t border-gray-100">
            <p class="text-xs text-gray-500">Desenvolvido por CortesWeb &copy; <?= date('Y') ?></p>
        </div>
    </div>

    <!-- Script de Máscara -->
    <script src="assets/js/main.js"></script>
    <script>
        function abrirModalLead() {
            document.getElementById('modalLead').style.display = 'flex';
        }

        function fecharModalLead() {
            document.getElementById('modalLead').style.display = 'none';
            document.getElementById('msgLead').innerText = '';
            document.getElementById('formLead').reset();
        }

        function enviarLead(event) {
            event.preventDefault();

            var btn = document.getElementById('btnSubmitLead');
            var msg = document.getElementById('msgLead');
            btn.disabled = true;
            btn.innerText = 'A enviar...';

            var formData = new FormData(document.getElementById('formLead'));

            fetch('salvar_lead.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        msg.style.color = 'green';
                        msg.innerText = data.message;
                        document.getElementById('formLead').reset();
                        setTimeout(fecharModalLead, 3000);
                    } else {
                        msg.style.color = 'red';
                        msg.innerText = data.message;
                    }
                })
                .catch(error => {
                    msg.style.color = 'red';
                    msg.innerText = 'Erro ao conectar ao servidor.';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerText = 'Enviar Solicitação';
                });
        }
    </script>
</body>

</html>