<?php
require_once 'session.php';
admin_session_start();
admin_no_cache();
require_once 'db.php';

$error = '';
if (!empty($_SESSION['sessao_expirada'])) {
    $error = 'Sua sessao expirou. Faca login novamente.';
    unset($_SESSION['sessao_expirada']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Preencha usuario e senha.';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['login_time']    = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['admin_id']   = $user['id'];
                $_SESSION['admin_nome'] = $user['nome'];
                $_SESSION['admin_user'] = $user['username'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Usuario ou senha incorretos.';
            }
        } catch (Exception $e) {
            $error = 'Erro de conexao. Verifique se o banco foi configurado via setup.php.';
        }
    }
}

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — ServiceNet Admin</title>
    <link rel="icon" href="../assets/img/logo.PNG" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
    <script src="assets/theme.js?v=<?= filemtime(__DIR__ . '/assets/theme.js') ?>"></script>
    <style>
        * { font-family: 'Raleway', sans-serif; }
        :root { --primary: #172a4c; --accent: #ff4c30; }

        body {
            background: linear-gradient(135deg, #0a1222 0%, #172a4c 50%, #1a1f35 100%);
            min-height: 100vh;
            overflow: hidden;
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: floatOrb 8s ease-in-out infinite;
            pointer-events: none;
        }
        .orb-1 { width: 500px; height: 500px; background: var(--accent); top: -100px; right: -100px; animation-delay: 0s; }
        .orb-2 { width: 400px; height: 400px; background: #3b82f6; bottom: -100px; left: -100px; animation-delay: 3s; }
        @keyframes floatOrb {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.05); }
        }

        .glass-card {
            background: var(--w07);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid var(--w15);
            box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 1px 0 var(--w10);
        }

        .logo-bordered {
        border: 3px solid rgba(255, 255, 255, 0.85);
        border-radius: 50%;
        padding: 8px;
        background: #fff;
        object-fit: contain;
        box-shadow: 0 0 0 1px rgba(255,255,255,0.15), 0 0 30px rgba(255,76,48,0.3);
        filter: drop-shadow(0 0 18px rgba(255,76,48,0.35));
        }

        .glass-input {
            background: var(--w08);
            border: 1px solid var(--w20);
            color: var(--text);
            backdrop-filter: blur(8px);
            transition: all 0.3s ease;
        }
        .glass-input::placeholder { color: var(--w40); }
        .glass-input:focus {
            outline: none;
            background: var(--w12);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255,76,48,0.25);
        }

        .btn-accent {
            background: linear-gradient(135deg, var(--accent), #e63825);
            box-shadow: 0 4px 20px rgba(255,76,48,0.4);
            transition: all 0.3s ease;
        }
        .btn-accent:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(255,76,48,0.6); }
        .btn-accent:active { transform: translateY(0); }

        .label-text { color: var(--w75); }

        input:-webkit-autofill,
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px var(--autofill) inset !important;
            -webkit-text-fill-color: var(--text) !important;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up { animation: fadeInUp 0.6s ease forwards; }

        .toggle-pass-btn { background: none; border: none; cursor: pointer; padding: 4px; }
        .toggle-pass-btn:hover { opacity: 1 !important; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <div class="glass-card rounded-3xl w-full max-w-md relative animate-fade-in-up">
        <div class="h-1 rounded-t-3xl" style="background: linear-gradient(90deg, #ff4c30, #ff8c6b, #ff4c30);"></div>

        <div class="p-10">
            <div class="text-center mb-8">
                <div class="flex justify-center mb-5">
                    <img src="../assets/img/logo.PNG"
                         alt="ServiceNet"
                         class="h-28 w-auto logo-bordered"
                         onerror="this.outerHTML='<div class=\'text-2xl font-extrabold text-white border-2 border-white rounded-xl px-4 py-2\'>ServiceNet</div>'">
                </div>
                <h1 class="text-2xl font-bold text-white mb-1">Painel de Gestao</h1>
                <p class="text-sm" style="color: var(--w50);">
                    <i class="fa-solid fa-shield-halved mr-1"></i>
                    Acesso restrito — ServiceNet Telecom
                </p>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-semibold text-white"
                 style="background: rgba(255,76,48,0.2); border: 1px solid rgba(255,76,48,0.4);">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5">
                <div>
                    <label class="block label-text text-sm font-semibold mb-2" for="username">
                        <i class="fa-solid fa-user mr-1.5 opacity-70"></i>Usuario
                    </label>
                    <input class="glass-input w-full py-3 px-4 rounded-xl text-sm"
                           type="text" id="username" name="username"
                           placeholder="admin" required autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div>
                    <label class="block label-text text-sm font-semibold mb-2" for="password">
                        <i class="fa-solid fa-lock mr-1.5 opacity-70"></i>Senha
                    </label>
                    <div class="relative">
                        <input class="glass-input w-full py-3 px-4 pr-12 rounded-xl text-sm"
                               type="password" id="password" name="password"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" onclick="togglePass()"
                                class="toggle-pass-btn absolute right-3 top-1/2 -translate-y-1/2 opacity-50 hover:opacity-100 transition-opacity">
                            <i id="eyeIcon" class="fa-solid fa-eye text-white text-base"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-accent w-full py-3 px-4 rounded-xl text-white font-bold text-sm mt-2 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Entrar no Painel
                </button>
            </form>
        </div>

        <div class="px-10 pb-6 text-center">
            <p class="text-xs" style="color: var(--w30);">
                <i class="fa-solid fa-code mr-1"></i>
                Desenvolvido por CortesWeb &copy; <?= date('Y') ?>
            </p>
        </div>
    </div>

    <script>
        function togglePass() {
            const input = document.getElementById('password');
            const icon  = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-solid fa-eye-slash text-white text-base';
            } else {
                input.type = 'password';
                icon.className = 'fa-solid fa-eye text-white text-base';
            }
        }
    </script>
</body>
</html>