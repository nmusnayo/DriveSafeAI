<?php
session_start();
include "db.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tipoCuenta = ($_POST["tipo_cuenta"] ?? "PARTICULAR") === "EMPRESA" ? "EMPRESA" : "PARTICULAR";
    $empresa = trim($_POST["empresa"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $correo = trim($_POST["correo"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $licencia = trim($_POST["licencia"] ?? "");
    $password = $_POST["password"] ?? "";
    $passwordConfirm = $_POST["password_confirm"] ?? "";

    if ($nombre === "" || $correo === "" || $password === "") {
        $error = "Complete los campos obligatorios.";
    } elseif ($tipoCuenta === "EMPRESA" && $empresa === "") {
        $error = "Ingrese el nombre de la empresa.";
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = "Ingrese un correo valido.";
    } elseif (strlen($password) < 6) {
        $error = "El password debe tener al menos 6 caracteres.";
    } elseif ($password !== $passwordConfirm) {
        $error = "Los passwords no coinciden.";
    } else {
        $existe = (int) (db_value($conexion, "SELECT COUNT(*) FROM usuarios WHERE correo = ?", "s", [$correo]) ?? 0);

        if ($existe > 0) {
            $error = "Ya existe una cuenta con ese correo.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $conexion->begin_transaction();

            try {
                $nombreOrganizacion = $tipoCuenta === "EMPRESA" ? $empresa : "Cuenta particular - " . $nombre;
                $stmt = $conexion->prepare("
                    INSERT INTO organizaciones (nombre, tipo)
                    VALUES (?, ?)
                ");
                $stmt->bind_param("ss", $nombreOrganizacion, $tipoCuenta);
                $stmt->execute();
                $idOrganizacion = $conexion->insert_id;
                $stmt->close();

                $rol = "ADMIN";
                $stmt = $conexion->prepare("
                    INSERT INTO usuarios (id_organizacion, nombre, correo, password, rol, estado)
                    VALUES (?, ?, ?, ?, ?, 'ACTIVO')
                ");
                $stmt->bind_param("issss", $idOrganizacion, $nombre, $correo, $hash, $rol);
                $stmt->execute();
                $idUsuario = $conexion->insert_id;
                $stmt->close();

                if ($tipoCuenta === "PARTICULAR") {
                    $stmt = $conexion->prepare("
                        INSERT INTO conductores (id_usuario, licencia, telefono)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->bind_param("iss", $idUsuario, $licencia, $telefono);
                    $stmt->execute();
                    $stmt->close();
                }

                $conexion->commit();
                $success = $tipoCuenta === "EMPRESA"
                    ? "Empresa creada correctamente. Ya puede iniciar sesion como administrador."
                    : "Cuenta particular creada correctamente. Ya puede iniciar sesion.";
            } catch (Throwable $e) {
                $conexion->rollback();
                $error = "No se pudo crear la cuenta. Intente nuevamente.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <title>DriveSafe AI - Registro</title>
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
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .auth-content {
            position: relative;
            z-index: 1;
        }

        .input-group {
            position: relative;
            margin-bottom: 8px;
        }

        .input-group input:focus+.input-icon,
        .input-group input:not(:placeholder-shown)+.input-icon {
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

        .account-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .account-type-option {
            padding: 16px;
            border: 2px solid var(--line);
            border-radius: 12px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .account-type-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .account-type-option.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(8, 145, 178, 0.1) 0%, rgba(99, 102, 241, 0.1) 100%);
        }

        .account-type-option strong {
            display: block;
            margin-bottom: 4px;
            color: var(--ink);
        }

        .account-type-option small {
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .account-type-selector {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="auth-body">
    <main class="auth-shell wide">
        <section class="auth-panel">
            <div class="auth-content">
                <div style="text-align: center; margin-bottom: 24px;">
                    <div class="brand-mark" style="margin: 0 auto 16px;">DS</div>
                    <p class="eyebrow">Registro de cuenta</p>
                    <h1>Crear cuenta</h1>
                    <p class="muted">Use DriveSafe AI como particular o administre conductores de una empresa de
                        transporte.</p>
                </div>

                <form method="POST" class="form-card two-column" autocomplete="on">
                    <?php if ($error): ?>
                        <div class="alert error form-wide"><?= h($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert success form-wide"><?= h($success) ?></div>
                    <?php endif; ?>

                    <label class="form-wide">
                        Tipo de cuenta
                        <div class="account-type-selector">
                            <div class="account-type-option <?= ($_POST["tipo_cuenta"] ?? "") !== "EMPRESA" ? "selected" : "" ?>"
                                onclick="selectAccountType('PARTICULAR')">
                                <strong>👤 Particular</strong>
                                <small>Uso personal</small>
                            </div>
                            <div class="account-type-option <?= ($_POST["tipo_cuenta"] ?? "") === "EMPRESA" ? "selected" : "" ?>"
                                onclick="selectAccountType('EMPRESA')">
                                <strong>🏢 Empresa</strong>
                                <small>Gestionar flota</small>
                            </div>
                        </div>
                        <input type="hidden" name="tipo_cuenta" id="accountType"
                            value="<?= h($_POST["tipo_cuenta"] ?? "PARTICULAR") ?>">
                    </label>

                    <label id="companyField" class="form-wide" <?= ($_POST["tipo_cuenta"] ?? "") === "EMPRESA" ? "" : 'style="display: none;"' ?>>
                        Nombre de la empresa
                        <input name="empresa" value="<?= h($_POST["empresa"] ?? "") ?>"
                            placeholder="Ej. Transporte Central">
                    </label>

                    <label>
                        Nombre completo del administrador
                        <input name="nombre" value="<?= h($_POST["nombre"] ?? "") ?>" required>
                    </label>

                    <label>
                        Correo electronico
                        <input name="correo" type="email" value="<?= h($_POST["correo"] ?? "") ?>" required>
                    </label>

                    <label>
                        Telefono
                        <input name="telefono" value="<?= h($_POST["telefono"] ?? "") ?>" placeholder="Ej. 70123456">
                    </label>

                    <label>
                        Licencia
                        <input name="licencia" value="<?= h($_POST["licencia"] ?? "") ?>"
                            placeholder="Categoria / numero">
                    </label>

                    <label>
                        Password
                        <input name="password" type="password" required minlength="6">
                    </label>

                    <label>
                        Confirmar password
                        <input name="password_confirm" type="password" required minlength="6">
                    </label>

                    <button class="primary-button form-wide" type="submit" style="width: 100%;">Registrarme</button>
                    <a class="secondary-button form-wide" href="login.php" style="width: 100%;">Volver al acceso</a>
                </form>
            </div>
        </section>
    </main>
    <script>
        function selectAccountType(type) {
            document.getElementById('accountType').value = type;
            document.querySelectorAll('.account-type-option').forEach(option => {
                option.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');

            const companyField = document.getElementById('companyField');
            if (type === 'EMPRESA') {
                companyField.style.display = 'grid';
            } else {
                companyField.style.display = 'none';
            }
        }
    </script>
</body>

</html>