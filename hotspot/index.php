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
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
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
        <div class="p-8 pb-4 text-center">
            <div class="flex justify-center mb-6">
                <img src="assets/img/logo.PNG" alt="Logo ServiceNet" class="h-28 w-auto object-contain drop-shadow-md" onerror="this.outerHTML='<div class=\'text-3xl font-extrabold text-primary\'>ServiceNet</div>'">
            </div>
            
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
                        <svg class="animate-spin h-12 w-12 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-primary mb-2">Autenticação aprovada!</h2>
                    <p class="text-gray-600 mb-8">Liberando sua internet, aguarde...</p>
                    
                    <!-- Formulário invisível que faz o login no MikroTik -->
                    <form id="formMikrotik" action="<?= htmlspecialchars($link_login_mikrotik) ?>" method="post" class="hidden">
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
                            id="cpf" 
                            name="cpf" 
                            type="tel" 
                            placeholder="000.000.000-00" 
                            required
                            maxlength="14"
                            oninput="mascaraCPF(this)"
                        >
                    </div>

                    <div class="space-y-4">
                        <button class="bg-primary hover:bg-[#0f1d35] text-white font-bold py-3 px-4 rounded-xl w-full transition duration-300 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 flex justify-center items-center gap-2" type="submit">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z" clip-rule="evenodd" />
                            </svg>
                            Conectar à Internet
                        </button>
                        
                        <!-- Divisor -->
                        <div class="relative flex py-2 items-center">
                            <div class="flex-grow border-t border-gray-200"></div>
                            <span class="flex-shrink-0 mx-4 text-gray-400 text-xs font-semibold uppercase">OU</span>
                            <div class="flex-grow border-t border-gray-200"></div>
                        </div>

                        <!-- Botão WhatsApp -->
                        <a href="https://wa.me/5573999547814?text=Olá!%20Gostaria%20de%20ser%20cliente%20da%20ServiceNet%20Telecom." 
                           target="_blank"
                           class="bg-accent hover:bg-[#e63e26] text-white font-bold py-3 px-4 rounded-xl w-full transition duration-300 shadow-md hover:shadow-lg flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
                            </svg>
                            Quero ser cliente
                        </a>
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
</body>
</html>