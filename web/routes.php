<?php
include "db.php";
require_login();

$user = current_user();
$isAdmin = in_array(($user["rol"] ?? ""), ["ADMIN", "SUPERVISOR"], true);
$panelUrl = role_dashboard_url($user);
$idConductor = (int) ($user["id"] ?? 0);
$orgId = current_org_id($user);

if ($isAdmin) {
    $sqlViajes = "
        SELECT v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre AS conductor,
            vh.placa, vh.marca, vh.modelo,
            p.latitud, p.longitud, p.fecha AS ultima_posicion,
            (SELECT COUNT(*) FROM incidentes i WHERE i.id_viaje = v.id) AS incidentes
        FROM viajes v
        INNER JOIN usuarios u ON u.id = v.id_conductor
        LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
        LEFT JOIN posiciones_ruta p ON p.id = (
            SELECT p2.id FROM posiciones_ruta p2 WHERE p2.id_viaje = v.id ORDER BY p2.fecha DESC LIMIT 1
        )
        " . ($orgId !== null ? "WHERE v.id_organizacion = ?" : "") . "
        ORDER BY COALESCE(v.inicio, v.creado_en) DESC
        LIMIT 40
    ";
    if ($orgId !== null) {
        $stmt = $conexion->prepare($sqlViajes);
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $viajes = $stmt->get_result();
    } else {
        $viajes = $conexion->query($sqlViajes);
    }
} else {
    $stmt = $conexion->prepare("
        SELECT v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre AS conductor,
            vh.placa, vh.marca, vh.modelo,
            p.latitud, p.longitud, p.fecha AS ultima_posicion,
            (SELECT COUNT(*) FROM incidentes i WHERE i.id_viaje = v.id) AS incidentes
        FROM viajes v
        INNER JOIN usuarios u ON u.id = v.id_conductor
        LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
        LEFT JOIN posiciones_ruta p ON p.id = (
            SELECT p2.id FROM posiciones_ruta p2 WHERE p2.id_viaje = v.id ORDER BY p2.fecha DESC LIMIT 1
        )
        WHERE v.id_conductor = ?
        ORDER BY COALESCE(v.inicio, v.creado_en) DESC
        LIMIT 40
    ");
    $stmt->bind_param("i", $idConductor);
    $stmt->execute();
    $viajes = $stmt->get_result();
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

<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= h($panelUrl) ?>">
                <span class="brand-mark">DS</span>
                <span>
                    <strong>DriveSafe AI</strong>
                    <small>Seguimiento</small>
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
                <?php if ($isAdmin): ?>
                    <a href="management.php">Gestion</a>
                <?php endif; ?>
                <a class="active" href="routes.php">Rutas</a>
                <a href="reports.php">Reportes</a>
                <a href="monitor.php">Monitoreo</a>
                <a href="logout.php">Salir</a>
            </nav>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Trazabilidad GPS</p>
                    <h1><?= $isAdmin ? "Seguimiento de rutas" : "Mis rutas" ?></h1>
                </div>
                <a class="primary-button compact" href="monitor.php">Nueva ruta</a>
            </header>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Historial de viajes</h2>
                    <span class="muted">Ultima posicion registrada</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-name">Conductor</th>
                                <th class="col-vehicle">Vehiculo</th>
                                <th class="col-route">Ruta</th>
                                <th class="col-small">Estado</th>
                                <th class="col-small">Inicio</th>
                                <th class="col-small">Fin</th>
                                <th class="col-number">Incidentes</th>
                                <th class="col-actions">Mapa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($v = $viajes->fetch_assoc()): ?>
                                <tr>
                                    <td class="col-name"><?= h($v["conductor"]) ?></td>
                                    <td class="col-vehicle"><?= h($v["placa"] ? trim($v["placa"] . " " . ($v["marca"] ?? "") . " " . ($v["modelo"] ?? "")) : "Sin asignar") ?>
                                    </td>
                                    <td class="col-route"><?= h(($v["origen"] ?: "Origen") . " - " . ($v["destino"] ?: "Destino")) ?></td>
                                    <td class="col-small"><span class="status-pill"><?= h($v["estado"]) ?></span></td>
                                    <td class="col-small"><?= $v["inicio"] ? date("d/m/Y H:i", strtotime($v["inicio"])) : "Sin iniciar" ?>
                                    </td>
                                    <td class="col-small"><?= $v["fin"] ? date("d/m/Y H:i", strtotime($v["fin"])) : "-" ?></td>
                                    <td class="col-number"><?= h((string) $v["incidentes"]) ?></td>
                                    <td class="col-actions">
                                        <?php if ($v["latitud"] && $v["longitud"]): ?>
                                            <a class="text-link" target="_blank"
                                                href="https://www.google.com/maps?q=<?= h($v["latitud"]) ?>,<?= h($v["longitud"]) ?>">Ver
                                                ubicacion</a>
                                        <?php else: ?>
                                            Sin GPS
                                        <?php endif; ?>
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