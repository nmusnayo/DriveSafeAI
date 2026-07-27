<?php
session_start();
include "db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $correo = trim($_POST["correo"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $conexion->prepare("SELECT * FROM usuarios WHERE correo = ? AND estado = 'ACTIVO' LIMIT 1");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($user = $res->fetch_assoc()) {
        if (password_verify($password, $user["password"])) {
            session_regenerate_id(true);
            $_SESSION["user"] = $user;
            redirect_to_role_dashboard($user);
        }
    }

    $error = "Credenciales incorrectas o usuario inactivo.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>DriveSafe AI - Acceso</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
    <style>
        .auth-panel {
            position: relative;
            overflow: hidden;
        }
        
        .auth-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(8, 145, 178, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
            pointer-events: none;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .auth-content {
            position: relative;
            z-index: 1;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 8px;
        }
        
        .input-group input:focus + .input-icon,
        .input-group input:not(:placeholder-shown) + .input-icon {
            color: var(--primary);
        }
        
        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            transition: color 0.3s ease;
            pointer-events: none;
        }
        
        .input-group input {
            padding-right: 48px;
        }
        
        .forgot-password {
            text-align: right;
            margin-top: -8px;
            margin-bottom: 16px;
        }
        
        .forgot-password a {
            color: var(--primary);
            font-size: 13px;
            font-weight: 600;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: var(--muted);
            font-size: 13px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--line);
        }
        
        .divider span {
            padding: 0 16px;
        }
        
        .social-login {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .social-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            border: 2px solid var(--line);
            border-radius: 12px;
            background: white;
            color: var(--ink);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .social-button:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .social-button svg {
            width: 20px;
            height: 20px;
        }
    </style>
</head>
<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-panel">
            <div class="auth-content">
                <div style="text-align: center; margin-bottom: 24px;">
                    <div class="brand-mark" style="margin: 0 auto 16px;">DS</div>
                    <p class="eyebrow">Sistema inteligente de monitoreo</p>
                    <h1>DriveSafe AI</h1>
                    <p class="muted">Control de fatiga, alertas preventivas y seguimiento de conductores en tiempo real.</p>
                </div>

                <form method="POST" class="form-card" autocomplete="on">
                    <?php if ($error): ?>
                        <div class="alert error"><?= h($error) ?></div>
                    <?php endif; ?>

                    <div class="input-group">
                        <label>
                            Correo electrónico
                            <input name="correo" type="email" placeholder="admin@drivesafe.ai" required>
                        </label>
                        <span class="input-icon">✉</span>
                    </div>

                    <div class="input-group">
                        <label>
                            Contraseña
                            <input name="password" type="password" placeholder="••••••••" required>
                        </label>
                        <span class="input-icon">🔒</span>
                    </div>

                    <div class="forgot-password">
                        <a href="#">¿Olvidaste tu contraseña?</a>
                    </div>

                    <button class="primary-button" type="submit" style="width: 100%;">
                        Ingresar al sistema
                    </button>
                </form>

                <div class="divider">
                    <span>o continúa con</span>
                </div>

                <div class="social-login">
                    <button class="social-button">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Google
                    </button>
                    <button class="social-button">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                        </svg>
                        GitHub
                    </button>
                </div>

                <p style="text-align: center; margin-top: 24px; color: var(--muted); font-size: 14px;">
                    ¿No tienes cuenta? 
                    <a href="register.php" style="color: var(--primary); font-weight: 700;">Crear cuenta</a>
                </p>
            </div>
        </section>
    </main>
</body>
</html>
