<?php
include "db.php";
require_roles(["ADMIN", "SUPERVISOR"]);

$user = current_user();
$panelUrl = role_dashboard_url($user);
$orgId = current_org_id($user);
$orgFilterUsuarios = $orgId !== null ? "AND u.id_organizacion = " . (int) $orgId : "";
$orgFilterUsuariosWhere = $orgId !== null ? "WHERE u.id_organizacion = " . (int) $orgId : "";
$orgFilterVehiculosWhere = $orgId !== null ? "WHERE vh.id_organizacion = " . (int) $orgId : "";
$orgFilterVehiculosSimpleWhere = $orgId !== null ? "WHERE id_organizacion = " . (int) $orgId : "";
$orgFilterViajes = $orgId !== null ? "WHERE v.id_organizacion = " . (int) $orgId : "";
$message = "";
$error = "";

function post_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? ""));
}

function allowed_control_role(string $rol): string
{
    return in_array($rol, ["ADMIN", "SUPERVISOR"], true) ? $rol : "SUPERVISOR";
}

function allowed_trip_status(string $estado): string
{
    return in_array($estado, ["PROGRAMADO", "EN_CURSO", "FINALIZADO", "CANCELADO"], true) ? $estado : "PROGRAMADO";
}

function icon(string $name): string
{
    $paths = [
        "save" => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        "ban" => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        "x" => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    ];

    return '<svg aria-hidden="true" viewBox="0 0 24 24">' . ($paths[$name] ?? $paths["save"]) . '</svg>';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "create_user") {
            $nombre = post_value("nombre");
            $correo = post_value("correo");
            $password = $_POST["password"] ?? "";
            $rol = allowed_control_role(post_value("rol"));
            $estado = post_value("estado") === "INACTIVO" ? "INACTIVO" : "ACTIVO";

            if ($nombre === "" || $correo === "" || $password === "") {
                throw new RuntimeException("Nombre, correo y password son obligatorios.");
            }

            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Ingrese un correo valido.");
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conexion->prepare("
                INSERT INTO usuarios (id_organizacion, nombre, correo, password, rol, estado)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssss", $orgId, $nombre, $correo, $hash, $rol, $estado);
            $stmt->execute();
            $stmt->close();
            $message = "Usuario creado correctamente.";
        }

        if ($action === "update_user") {
            $id = (int) ($_POST["id"] ?? 0);
            $nombre = post_value("nombre");
            $correo = post_value("correo");
            $rol = allowed_control_role(post_value("rol"));
            $estado = post_value("estado") === "INACTIVO" ? "INACTIVO" : "ACTIVO";
            $password = $_POST["password"] ?? "";

            if ($id <= 0 || $nombre === "" || $correo === "") {
                throw new RuntimeException("Datos incompletos para editar usuario.");
            }

            if ($id === (int) ($user["id"] ?? 0) && $estado === "INACTIVO") {
                throw new RuntimeException("No puede desactivar su propia cuenta activa.");
            }

            if ($password !== "") {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conexion->prepare("UPDATE usuarios SET nombre = ?, correo = ?, password = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $nombre, $correo, $hash, $rol, $estado, $id);
            } else {
                $stmt = $conexion->prepare("UPDATE usuarios SET nombre = ?, correo = ?, rol = ?, estado = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $nombre, $correo, $rol, $estado, $id);
            }
            $stmt->execute();
            $stmt->close();
            $message = "Usuario actualizado.";
        }

        if ($action === "deactivate_user") {
            $id = (int) ($_POST["id"] ?? 0);
            if ($id === (int) ($user["id"] ?? 0)) {
                throw new RuntimeException("No puede desactivar su propia cuenta.");
            }

            $stmt = $conexion->prepare("UPDATE usuarios SET estado = 'INACTIVO' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $message = "Usuario desactivado.";
        }

        if ($action === "create_driver") {
            $nombre = post_value("nombre");
            $correo = post_value("correo");
            $password = $_POST["password"] ?? "";
            $telefono = post_value("telefono");
            $licencia = post_value("licencia");

            if ($nombre === "" || $correo === "" || $password === "") {
                throw new RuntimeException("Nombre, correo y password son obligatorios.");
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $conexion->begin_transaction();

            $stmt = $conexion->prepare("
                INSERT INTO usuarios (id_organizacion, nombre, correo, password, rol, estado)
                VALUES (?, ?, ?, ?, 'CONDUCTOR', 'ACTIVO')
            ");
            $stmt->bind_param("isss", $orgId, $nombre, $correo, $hash);
            $stmt->execute();
            $idUsuario = $conexion->insert_id;
            $stmt->close();

            $stmt = $conexion->prepare("INSERT INTO conductores (id_usuario, licencia, telefono) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $idUsuario, $licencia, $telefono);
            $stmt->execute();
            $stmt->close();

            $conexion->commit();
            $message = "Conductor creado correctamente.";
        }

        if ($action === "update_driver") {
            $id = (int) ($_POST["id"] ?? 0);
            $nombre = post_value("nombre");
            $correo = post_value("correo");
            $estado = post_value("estado") === "INACTIVO" ? "INACTIVO" : "ACTIVO";
            $telefono = post_value("telefono");
            $licencia = post_value("licencia");
            $password = $_POST["password"] ?? "";

            if ($id <= 0 || $nombre === "" || $correo === "") {
                throw new RuntimeException("Datos incompletos para editar conductor.");
            }

            $conexion->begin_transaction();

            if ($password !== "") {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conexion->prepare("UPDATE usuarios SET nombre = ?, correo = ?, password = ?, estado = ? WHERE id = ? AND rol = 'CONDUCTOR'");
                $stmt->bind_param("ssssi", $nombre, $correo, $hash, $estado, $id);
            } else {
                $stmt = $conexion->prepare("UPDATE usuarios SET nombre = ?, correo = ?, estado = ? WHERE id = ? AND rol = 'CONDUCTOR'");
                $stmt->bind_param("sssi", $nombre, $correo, $estado, $id);
            }
            $stmt->execute();
            $stmt->close();

            $perfil = (int) (db_value($conexion, "SELECT COUNT(*) FROM conductores WHERE id_usuario = ?", "i", [$id]) ?? 0);
            if ($perfil > 0) {
                $stmt = $conexion->prepare("UPDATE conductores SET licencia = ?, telefono = ? WHERE id_usuario = ?");
                $stmt->bind_param("ssi", $licencia, $telefono, $id);
            } else {
                $stmt = $conexion->prepare("INSERT INTO conductores (id_usuario, licencia, telefono) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $id, $licencia, $telefono);
            }
            $stmt->execute();
            $stmt->close();

            $conexion->commit();
            $message = "Conductor actualizado.";
        }

        if ($action === "deactivate_driver") {
            $id = (int) ($_POST["id"] ?? 0);
            $stmt = $conexion->prepare("UPDATE usuarios SET estado = 'INACTIVO' WHERE id = ? AND rol = 'CONDUCTOR'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $message = "Conductor desactivado.";
        }

        if ($action === "create_vehicle") {
            $placa = strtoupper(post_value("placa"));
            $marca = post_value("marca");
            $modelo = post_value("modelo");
            $estado = in_array(post_value("estado"), ["ACTIVO", "MANTENIMIENTO", "INACTIVO"], true) ? post_value("estado") : "ACTIVO";

            if ($placa === "") {
                throw new RuntimeException("La placa es obligatoria.");
            }

            $stmt = $conexion->prepare("INSERT INTO vehiculos (id_organizacion, placa, marca, modelo, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $orgId, $placa, $marca, $modelo, $estado);
            $stmt->execute();
            $stmt->close();
            $message = "Vehiculo creado correctamente.";
        }

        if ($action === "update_vehicle") {
            $id = (int) ($_POST["id"] ?? 0);
            $placa = strtoupper(post_value("placa"));
            $marca = post_value("marca");
            $modelo = post_value("modelo");
            $estado = in_array(post_value("estado"), ["ACTIVO", "MANTENIMIENTO", "INACTIVO"], true) ? post_value("estado") : "ACTIVO";

            if ($id <= 0 || $placa === "") {
                throw new RuntimeException("Datos incompletos para editar vehiculo.");
            }

            $stmt = $conexion->prepare("UPDATE vehiculos SET placa = ?, marca = ?, modelo = ?, estado = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $placa, $marca, $modelo, $estado, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Vehiculo actualizado.";
        }

        if ($action === "deactivate_vehicle") {
            $id = (int) ($_POST["id"] ?? 0);
            $stmt = $conexion->prepare("UPDATE vehiculos SET estado = 'INACTIVO' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $message = "Vehiculo desactivado.";
        }

        if ($action === "create_trip") {
            $idConductor = (int) ($_POST["id_conductor"] ?? 0);
            $idVehiculo = (int) ($_POST["id_vehiculo"] ?? 0);
            $idVehiculoParam = $idVehiculo > 0 ? $idVehiculo : null;
            $origen = post_value("origen");
            $destino = post_value("destino");
            $estado = allowed_trip_status(post_value("estado"));

            if ($idConductor <= 0 || $origen === "" || $destino === "") {
                throw new RuntimeException("Conductor, origen y destino son obligatorios para crear una ruta.");
            }

            $inicioSql = $estado === "EN_CURSO" ? "NOW()" : "NULL";
            $stmt = $conexion->prepare("
                INSERT INTO viajes (id_organizacion, id_conductor, id_vehiculo, origen, destino, estado, inicio)
                VALUES (?, ?, ?, ?, ?, ?, $inicioSql)
            ");
            $stmt->bind_param("iiisss", $orgId, $idConductor, $idVehiculoParam, $origen, $destino, $estado);
            $stmt->execute();
            $stmt->close();
            $message = "Ruta creada correctamente.";
        }

        if ($action === "update_trip") {
            $id = (int) ($_POST["id"] ?? 0);
            $idConductor = (int) ($_POST["id_conductor"] ?? 0);
            $idVehiculo = (int) ($_POST["id_vehiculo"] ?? 0);
            $idVehiculoParam = $idVehiculo > 0 ? $idVehiculo : null;
            $origen = post_value("origen");
            $destino = post_value("destino");
            $estado = allowed_trip_status(post_value("estado"));

            if ($id <= 0 || $idConductor <= 0 || $origen === "" || $destino === "") {
                throw new RuntimeException("Datos incompletos para editar ruta.");
            }

            $stmt = $conexion->prepare("
                UPDATE viajes
                SET id_conductor = ?, id_vehiculo = ?, origen = ?, destino = ?, estado = ?,
                    inicio = CASE WHEN ? = 'EN_CURSO' AND inicio IS NULL THEN NOW() ELSE inicio END,
                    fin = CASE WHEN ? IN ('FINALIZADO', 'CANCELADO') AND fin IS NULL THEN NOW() ELSE fin END
                WHERE id = ?
            ");
            $stmt->bind_param("iisssssi", $idConductor, $idVehiculoParam, $origen, $destino, $estado, $estado, $estado, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Ruta actualizada.";
        }

        if ($action === "cancel_trip") {
            $id = (int) ($_POST["id"] ?? 0);
            $stmt = $conexion->prepare("UPDATE viajes SET estado = 'CANCELADO', fin = COALESCE(fin, NOW()) WHERE id = ? AND estado <> 'FINALIZADO'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $message = "Ruta cancelada.";
        }
    } catch (Throwable $e) {
        try {
            $conexion->rollback();
        } catch (Throwable $rollbackError) {
        }
        $error = $e instanceof mysqli_sql_exception ? "No se pudo guardar. Revise duplicados de correo o placa." : $e->getMessage();
    }
}

$conductores = $conexion->query("
    SELECT u.id, u.nombre, u.correo, u.estado, c.licencia, c.telefono,
        COUNT(DISTINCT v.id) AS viajes,
        COUNT(DISTINCT a.id) AS alertas
    FROM usuarios u
    LEFT JOIN conductores c ON c.id_usuario = u.id
    LEFT JOIN viajes v ON v.id_conductor = u.id
    LEFT JOIN alertas a ON a.id_conductor = u.id
    WHERE u.rol = 'CONDUCTOR'
        $orgFilterUsuarios
    GROUP BY u.id, u.nombre, u.correo, u.estado, c.licencia, c.telefono
    ORDER BY u.nombre ASC
");

$usuariosControl = $conexion->query("
    SELECT id, nombre, correo, rol, estado, creado_en
    FROM usuarios
    WHERE rol IN ('ADMIN', 'SUPERVISOR')
        " . ($orgId !== null ? "AND id_organizacion = " . (int) $orgId : "") . "
    ORDER BY rol ASC, nombre ASC
");

$vehiculos = $conexion->query("
    SELECT vh.id, vh.placa, vh.marca, vh.modelo, vh.estado,
        COUNT(v.id) AS viajes
    FROM vehiculos vh
    LEFT JOIN viajes v ON v.id_vehiculo = vh.id
    $orgFilterVehiculosWhere
    GROUP BY vh.id, vh.placa, vh.marca, vh.modelo, vh.estado
    ORDER BY vh.placa ASC
");

$conductoresSelect = $conexion->query("
    SELECT id, nombre, estado
    FROM usuarios
    WHERE rol = 'CONDUCTOR'
        " . ($orgId !== null ? "AND id_organizacion = " . (int) $orgId : "") . "
    ORDER BY estado ASC, nombre ASC
");

$vehiculosSelect = $conexion->query("
    SELECT id, placa, marca, modelo, estado
    FROM vehiculos
    $orgFilterVehiculosSimpleWhere
    ORDER BY estado ASC, placa ASC
");

$viajes = $conexion->query("
    SELECT v.id, v.id_conductor, v.id_vehiculo, v.origen, v.destino, v.estado, v.inicio, v.fin,
        u.nombre AS conductor, vh.placa,
        COUNT(DISTINCT p.id) AS puntos,
        COUNT(DISTINCT a.id) AS alertas,
        COUNT(DISTINCT i.id) AS incidentes
    FROM viajes v
    INNER JOIN usuarios u ON u.id = v.id_conductor
    LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
    LEFT JOIN posiciones_ruta p ON p.id_viaje = v.id
    LEFT JOIN alertas a ON a.id_viaje = v.id
    LEFT JOIN incidentes i ON i.id_viaje = v.id
    $orgFilterViajes
    GROUP BY v.id, v.id_conductor, v.id_vehiculo, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre, vh.placa
    ORDER BY COALESCE(v.inicio, v.creado_en) DESC
    LIMIT 40
");

$conductoresOptions = [];
while ($option = $conductoresSelect->fetch_assoc()) {
    $conductoresOptions[] = $option;
}

$vehiculosOptions = [];
while ($option = $vehiculosSelect->fetch_assoc()) {
    $vehiculosOptions[] = $option;
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_profile"])) {

    $nombre = trim($_POST["nombre"]);
    $correo = trim($_POST["correo"]);

    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if (!empty($password)) {

        if ($password !== $confirmPassword) {
            die("Las contraseñas no coinciden.");
        }

        $hash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $stmt = $conexion->prepare("
            UPDATE usuarios
            SET nombre = ?, correo = ?, password = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "sssi",
            $nombre,
            $correo,
            $hash,
            $idConductor
        );

    } else {

        $stmt = $conexion->prepare("
            UPDATE usuarios
            SET nombre = ?, correo = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssi",
            $nombre,
            $correo,
            $idConductor
        );
    }

    $stmt->execute();
    $stmt->close();

    header("Location: conductor_dashboard.php");
    exit;
}
?>
<?php include 'header.php'; ?>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="conductor_dashboard.php">
                <span class="brand-mark">DS</span>
                <span>
                    <strong>DriveSafe AI</strong>
                    <small>Panel de gestión</small>
                </span>
            </a>

            <!-- Información del usuario -->
            <button type="button" class="user-card" onclick="openProfileModal()">

                <div class="user-avatar">
                    <?= strtoupper(substr($user["nombre"], 0, 1)) ?>
                </div>

                <div class="user-data">
                    <strong><?= h($user["nombre"]) ?></strong>
                    <small><?= h($user["rol"]) ?></small>
                    <small><?= h($user["correo"]) ?></small>
                </div>

            </button> <br><br>

            <nav class="nav-list">
                <a href="<?= h($panelUrl) ?>">Panel</a>
                <a class="active" href="management.php">Gestion</a>
                <a href="routes.php">Rutas</a>
                <a href="reports.php">Reportes</a>
                <a href="monitor.php">Monitoreo</a>
                <a href="logout.php">Salir</a>
            </nav>
        </aside>
        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Administracion del sistema</p>
                    <h1>Gestion operativa</h1>
                </div><a class="primary-button compact" href="monitor.php">Monitoreo</a>
            </header>
            <?php if ($message): ?>
                <div class="alert success"><?= h($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <section class="dashboard-grid management-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Nuevo usuario</h2>
                    </div>
                    <form method="POST" class="form-card two-column"><input type="hidden" name="action"
                            value="create_user"><label>Nombre<input name="nombre" required></label><label>Correo<input
                                name="correo" type="email" required></label><label>Rol <select name="rol">
                                <option value="SUPERVISOR">SUPERVISOR</option>
                                <option value="ADMIN">ADMIN</option>
                            </select></label><label>Estado <select name="estado">
                                <option value="ACTIVO">ACTIVO</option>
                                <option value="INACTIVO">INACTIVO</option>
                            </select></label><label class="form-wide">Password<input name="password" type="password"
                                required minlength="6"></label><button class="primary-button form-wide"
                            type="submit">Crear usuario</button></form>
                </article>
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Nuevo conductor</h2>
                    </div>
                    <form method="POST" class="form-card two-column"><input type="hidden" name="action"
                            value="create_driver"><label>Nombre<input name="nombre" required></label><label>Correo<input
                                name="correo" type="email" required></label><label>Telefono<input
                                name="telefono"></label><label>Licencia<input name="licencia"></label><label
                            class="form-wide">Password<input name="password" type="password" required
                                minlength="6"></label><button class="primary-button form-wide" type="submit">Crear
                            conductor</button></form>
                </article>
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Nuevo vehiculo</h2>
                    </div>
                    <form method="POST" class="form-card two-column"><input type="hidden" name="action"
                            value="create_vehicle"><label>Placa<input name="placa" required></label><label>Estado
                            <select name="estado">
                                <option value="ACTIVO">ACTIVO</option>
                                <option value="MANTENIMIENTO">MANTENIMIENTO</option>
                                <option value="INACTIVO">INACTIVO</option>
                            </select></label><label>Marca<input name="marca"></label><label>Modelo<input
                                name="modelo"></label><button class="primary-button form-wide" type="submit">Crear
                            vehiculo</button></form>
                </article>
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Programar ruta</h2>
                    </div>
                    <form method="POST" class="form-card two-column"><input type="hidden" name="action"
                            value="create_trip"><label>Conductor <select name="id_conductor" required>
                                <option value="">Seleccione</option>
                                <?php foreach ($conductoresOptions as $option): ?>
                                    <option value="<?= h((string) $option["id"]) ?>">
                                        <?= h($option["nombre"] . " (" . $option["estado"] . ")") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></label><label>Vehiculo <select name="id_vehiculo">
                                <option value="">Sin asignar</option>
                                <?php foreach ($vehiculosOptions as $option): ?>
                                    <option value="<?= h((string) $option["id"]) ?>">
                                        <?= h(trim($option["placa"] . " " . ($option["marca"] ?? "") . " " . ($option["modelo"] ?? "") . " (" . $option["estado"] . ")")) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></label><label>Origen<input name="origen" required></label><label>Destino<input
                                name="destino" required></label><label class="form-wide">Estado <select name="estado">
                                <option value="PROGRAMADO">PROGRAMADO</option>
                                <option value="EN_CURSO">EN_CURSO</option>
                            </select></label><button class="primary-button form-wide" type="submit">Crear ruta</button>
                    </form>
                </article>
            </section>
            <section class="panel">
                <div class="panel-heading">
                    <h2>Usuarios de control</h2><span class="muted">Administradores y supervisores</span>
                </div>
                <div class="table-wrap">
                    <table class="editable-table">
                        <thead>
                            <tr>
                                <th class="col-name">Nombre</th>
                                <th class="col-email">Correo</th>
                                <th class="col-small">Rol</th>
                                <th class="col-small">Estado</th>
                                <th class="col-password">Password</th>
                                <th class="col-actions">Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($u = $usuariosControl->fetch_assoc()): ?>
                                <?php $formId = "user-form-" . (int) $u["id"]; ?>
                                <tr>
                                    <td>
                                        <form id="<?= h($formId) ?>" method="POST"></form><input form="<?= h($formId) ?>"
                                            type="hidden" name="action" value="update_user"><input form="<?= h($formId) ?>"
                                            type="hidden" name="id" value="<?= h((string) $u["id"]) ?>"><input
                                            form="<?= h($formId) ?>" name="nombre" value="<?= h($u["nombre"]) ?>" required>
                                    </td>
                                    <td><input form="<?= h($formId) ?>" name="correo" type="email"
                                            value="<?= h($u["correo"]) ?>" required></td>
                                    <td><select form="<?= h($formId) ?>" name="rol">
                                            <option value="SUPERVISOR" <?= $u["rol"] === "SUPERVISOR" ? "selected" : "" ?>>
                                                SUPERVISOR</option>
                                            <option value="ADMIN" <?= $u["rol"] === "ADMIN" ? "selected" : "" ?>>ADMIN</option>
                                        </select></td>
                                    <td><select form="<?= h($formId) ?>" name="estado">
                                            <option value="ACTIVO" <?= $u["estado"] === "ACTIVO" ? "selected" : "" ?>>ACTIVO
                                            </option>
                                            <option value="INACTIVO" <?= $u["estado"] === "INACTIVO" ? "selected" : "" ?>>
                                                INACTIVO</option>
                                        </select></td>
                                    <td><input form="<?= h($formId) ?>" name="password" type="password"
                                            placeholder="Nuevo password"></td>
                                    <td class="action-cell"><button form="<?= h($formId) ?>" class="icon-button save"
                                            type="submit" title="Guardar cambios"
                                            aria-label="Guardar cambios"><?= icon("save") ?></button>
                                        <form method="POST" class="delete-form"
                                            onsubmit="return confirm('Desactivar este usuario?');"><input type="hidden"
                                                name="action" value="deactivate_user"><input type="hidden" name="id"
                                                value="<?= h((string) $u["id"]) ?>"><button class="icon-button danger"
                                                type="submit" title="Desactivar usuario"
                                                aria-label="Desactivar usuario"><?= icon("ban") ?></button></form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="panel">
                <div class="panel-heading">
                    <h2>Conductores registrados</h2><span class="muted">Editar datos,
                        licencia y estado</span>
                </div>
                <div class="table-wrap">
                    <table class="editable-table">
                        <thead>
                            <tr>
                                <th class="col-name">Nombre</th>
                                <th class="col-email">Correo</th>
                                <th class="col-small">Telefono</th>
                                <th class="col-small">Licencia</th>
                                <th class="col-small">Estado</th>
                                <th class="col-password">Contraseña</th>
                                <th class="col-small">Uso</th>
                                <th class="col-actions">Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($c = $conductores->fetch_assoc()): ?>
                                <?php $formId = "driver-form-" . (int) $c["id"]; ?>
                                <tr>
                                    <td>
                                        <form id="<?= h($formId) ?>" method="POST"></form><input form="<?= h($formId) ?>"
                                            type="hidden" name="action" value="update_driver"><input
                                            form="<?= h($formId) ?>" type="hidden" name="id"
                                            value="<?= h((string) $c["id"]) ?>"><input form="<?= h($formId) ?>"
                                            name="nombre" value="<?= h($c["nombre"]) ?>" required>
                                    </td>
                                    <td><input form="<?= h($formId) ?>" name="correo" type="email"
                                            value="<?= h($c["correo"]) ?>" required></td>
                                    <td><input form="<?= h($formId) ?>" name="telefono"
                                            value="<?= h($c["telefono"] ?? "") ?>"></td>
                                    <td><input form="<?= h($formId) ?>" name="licencia"
                                            value="<?= h($c["licencia"] ?? "") ?>"></td>
                                    <td><select form="<?= h($formId) ?>" name="estado">
                                            <option value="ACTIVO" <?= $c["estado"] === "ACTIVO" ? "selected" : "" ?>>ACTIVO
                                            </option>
                                            <option value="INACTIVO" <?= $c["estado"] === "INACTIVO" ? "selected" : "" ?>>
                                                INACTIVO</option>
                                        </select></td>
                                    <td><input form="<?= h($formId) ?>" class="compact-input" name="password"
                                            type="password" placeholder="Nuevo password">
                                    <td class="col-small"><span class="muted block"><?= h((string) $c["viajes"]) ?> viajes</span><span
                                            class="muted block"><?= h((string) $c["alertas"]) ?> alertas</span></td>
                                    <td class="action-cell"><button form="<?= h($formId) ?>" class="icon-button save"
                                            type="submit" title="Guardar cambios"
                                            aria-label="Guardar cambios"><?= icon("save") ?></button>
                                        <form method="POST" class="delete-form"
                                            onsubmit="return confirm('Desactivar este conductor?');"><input type="hidden"
                                                name="action" value="deactivate_driver"><input type="hidden" name="id"
                                                value="<?= h((string) $c["id"]) ?>"><button class="icon-button danger"
                                                type="submit" title="Desactivar conductor"
                                                aria-label="Desactivar conductor"><?= icon("ban") ?></button></form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="panel">
                <div class="panel-heading">
                    <h2>Vehiculos</h2><span class="muted">Unidades disponibles para asignar a rutas</span>
                </div>
                <div class="table-wrap">
                    <table class="editable-table">
                        <thead>
                            <tr>
                                <th class="col-small">Placa</th>
                                <th class="col-small">Marca</th>
                                <th class="col-small">Modelo</th>
                                <th class="col-small">Estado</th>
                                <th class="col-small">Viajes</th>
                                <th class="col-actions">Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($v = $vehiculos->fetch_assoc()): ?>
                                <?php $formId = "vehicle-form-" . (int) $v["id"]; ?>
                                <tr>
                                    <td>
                                        <form id="<?= h($formId) ?>" method="POST"></form><input form="<?= h($formId) ?>"
                                            type="hidden" name="action" value="update_vehicle"><input
                                            form="<?= h($formId) ?>" type="hidden" name="id"
                                            value="<?= h((string) $v["id"]) ?>"><input form="<?= h($formId) ?>" name="placa"
                                            value="<?= h($v["placa"]) ?>" required>
                                    </td>
                                    <td><input form="<?= h($formId) ?>" name="marca" value="<?= h($v["marca"] ?? "") ?>">
                                    </td>
                                    <td><input form="<?= h($formId) ?>" name="modelo" value="<?= h($v["modelo"] ?? "") ?>">
                                    </td>
                                    <td><select form="<?= h($formId) ?>" name="estado">
                                            <option value="ACTIVO" <?= $v["estado"] === "ACTIVO" ? "selected" : "" ?>>ACTIVO
                                            </option>
                                            <option value="MANTENIMIENTO" <?= $v["estado"] === "MANTENIMIENTO" ? "selected" : "" ?>>MANTENIMIENTO</option>
                                            <option value="INACTIVO" <?= $v["estado"] === "INACTIVO" ? "selected" : "" ?>>
                                                INACTIVO</option>
                                        </select></td>
                                    <td class="col-small"><span class="muted"><?= h((string) $v["viajes"]) ?> viajes</span></td>
                                    <td class="action-cell"><button form="<?= h($formId) ?>" class="icon-button save"
                                            type="submit" title="Guardar cambios"
                                            aria-label="Guardar cambios"><?= icon("save") ?></button>
                                        <form method="POST" class="delete-form"
                                            onsubmit="return confirm('Desactivar este vehiculo?');"><input type="hidden"
                                                name="action" value="deactivate_vehicle"><input type="hidden" name="id"
                                                value="<?= h((string) $v["id"]) ?>"><button class="icon-button danger"
                                                type="submit" title="Desactivar vehiculo"
                                                aria-label="Desactivar vehiculo"><?= icon("ban") ?></button></form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="panel">
                <div class="panel-heading">
                    <h2>Rutas y viajes</h2><span class="muted">Editar asignacion,
                        estado y trazabilidad registrada</span>
                </div>
                <div class="table-wrap">
                    <table class="editable-table">
                        <thead>
                            <tr>
                                <th class="col-name">Conductor</th>
                                <th class="col-vehicle">Vehiculo</th>
                                <th class="col-route">Origen</th>
                                <th class="col-route">Destino</th>
                                <th class="col-small">Estado</th>
                                <th class="col-small">Control</th>
                                <th class="col-actions">Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $viajes->fetch_assoc()): ?>
                                <?php $formId = "trip-form-" . (int) $r["id"]; ?>
                                <tr>
                                    <td>
                                        <form id="<?= h($formId) ?>" method="POST"></form><input form="<?= h($formId) ?>"
                                            type="hidden" name="action" value="update_trip"><input form="<?= h($formId) ?>"
                                            type="hidden" name="id" value="<?= h((string) $r["id"]) ?>"><select
                                            form="<?= h($formId) ?>" name="id_conductor" required>
                                            <?php foreach ($conductoresOptions as $option): ?>
                                                <option value="<?= h((string) $option["id"]) ?>" <?= (int) $r["id_conductor"] === (int) $option["id"] ? "selected" : "" ?>>
                                                    <?= h($option["nombre"] . " (" . $option["estado"] . ")") ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><select form="<?= h($formId) ?>" name="id_vehiculo">
                                            <option value="">Sin asignar</option>
                                            <?php foreach ($vehiculosOptions as $option): ?>
                                                <option value="<?= h((string) $option["id"]) ?>" <?= (int) ($r["id_vehiculo"] ?? 0) === (int) $option["id"] ? "selected" : "" ?>>
                                                    <?= h(trim($option["placa"] . " " . ($option["marca"] ?? "") . " " . ($option["modelo"] ?? "") . " (" . $option["estado"] . ")")) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select></td>
                                    <td><input form="<?= h($formId) ?>" name="origen" value="<?= h($r["origen"] ?? "") ?>"
                                            required></td>
                                    <td><input form="<?= h($formId) ?>" name="destino" value="<?= h($r["destino"] ?? "") ?>"
                                            required></td>
                                    <td><select form="<?= h($formId) ?>" name="estado">
                                            <?php foreach (["PROGRAMADO", "EN_CURSO", "FINALIZADO", "CANCELADO"] as $estado): ?>
                                                <option value="<?= h($estado) ?>" <?= $r["estado"] === $estado ? "selected" : "" ?>><?= h($estado) ?></option>
                                            <?php endforeach; ?>
                                        </select></td>
                                    <td class="col-small"><span class="muted block"><?= h((string) $r["puntos"]) ?> GPS</span><span
                                            class="muted block"><?= h((string) $r["alertas"]) ?> alertas</span><span
                                            class="muted block"><?= h((string) $r["incidentes"]) ?> incidentes</span></td>
                                    <td class="action-cell"><button form="<?= h($formId) ?>" class="icon-button save"
                                            type="submit" title="Guardar cambios"
                                            aria-label="Guardar cambios"><?= icon("save") ?></button>
                                        <form method="POST" class="delete-form"
                                            onsubmit="return confirm('Cancelar esta ruta?');"><input type="hidden"
                                                name="action" value="cancel_trip"><input type="hidden" name="id"
                                                value="<?= h((string) $r["id"]) ?>"><button class="icon-button danger"
                                                type="submit" title="Cancelar ruta" aria-label="Cancelar ruta"
                                                <?= $r["estado"] === "FINALIZADO" || $r["estado"] === "CANCELADO" ? "disabled" : "" ?>><?= icon("x") ?></button></form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div id="profileModal" class="modal">

        <div class="modal-content">

            <div class="modal-header">
                <h2>Mi Perfil</h2>

                <button type="button" class="close-btn danger-button" onclick="closeProfileModal()">
                    ×
                </button>
            </div>

            <form method="POST" class="profile-form">

                <input type="hidden" name="update_profile" value="1">

                <div class="form-group">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" value="<?= h($user["nombre"]) ?>" required>
                </div>

                <div class="form-row">

                    <div class="form-group">
                        <label for="correo">Correo</label>
                        <input type="email" id="correo" name="correo" value="<?= h($user["correo"]) ?>" required>
                    </div>


                    <div class="form-group">
                        <label for="rol">Rol</label>
                        <input type="text" id="rol" value="<?= h($user["rol"]) ?>" readonly>
                    </div>

                </div>


                <div class="form-row">

                    <div class="form-group">
                        <label for="password">Nueva contraseña</label>
                        <input type="password" id="password" name="password"
                            placeholder="Dejar en blanco para mantener el actual">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirmar contraseña</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                            placeholder="Repita la contraseña">
                    </div>

                </div>

                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeProfileModal()">
                        Cancelar
                    </button>

                    <button type="submit" class="primary-button">
                        Guardar cambios
                    </button>
                </div>

            </form>

        </div>

    </div>

    <?php include 'footer.php'; ?>