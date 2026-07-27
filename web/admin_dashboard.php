<?php
include "db.php";
require_login();

$user = current_user();
$orgId = current_org_id($user);
$orgUsuarios = $orgId !== null ? "AND id_organizacion = " . (int) $orgId : "";
$orgViajesWhere = $orgId !== null ? "WHERE id_organizacion = " . (int) $orgId : "";
$orgViajesAnd = $orgId !== null ? "AND v.id_organizacion = " . (int) $orgId : "";

if (!in_array(($user["rol"] ?? ""), ["ADMIN", "SUPERVISOR"], true)) {
    http_response_code(403);
    die("No autorizado");
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
$totalConductores = (int) (db_value($conexion, "SELECT COUNT(*) FROM usuarios WHERE rol = 'CONDUCTOR' $orgUsuarios") ?? 0);
$rutasActivas = (int) (db_value($conexion, "SELECT COUNT(*) FROM viajes WHERE estado = 'EN_CURSO' " . ($orgId !== null ? "AND id_organizacion = " . (int) $orgId : "")) ?? 0);
$incidentesHoy = (int) (db_value($conexion, "SELECT COUNT(*) FROM incidentes i INNER JOIN viajes v ON v.id = i.id_viaje WHERE DATE(i.fecha) = CURDATE() $orgViajesAnd") ?? 0);
$alertasCriticas = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas a LEFT JOIN viajes v ON v.id = a.id_viaje WHERE a.nivel = 'CRITICO' $orgViajesAnd") ?? 0);
$alertasHoy = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas a LEFT JOIN viajes v ON v.id = a.id_viaje WHERE DATE(a.fecha) = CURDATE() $orgViajesAnd") ?? 0);
$fatigaFlota = round((float) (db_value($conexion, "SELECT AVG(a.fatiga) FROM alertas a LEFT JOIN viajes v ON v.id = a.id_viaje WHERE a.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY) $orgViajesAnd") ?? 0), 1);
$scoreFlota = max(0, min(100, (int) round(100 - $fatigaFlota)));

$rutas = $conexion->query("
    SELECT v.id, v.origen, v.destino, v.inicio, u.nombre AS conductor,
        p.latitud, p.longitud, p.velocidad, p.fecha AS ultima_posicion
    FROM viajes v
    INNER JOIN usuarios u ON u.id = v.id_conductor
    LEFT JOIN posiciones_ruta p ON p.id = (
        SELECT p2.id
        FROM posiciones_ruta p2
        WHERE p2.id_viaje = v.id
        ORDER BY p2.fecha DESC
        LIMIT 1
    )
    WHERE v.estado = 'EN_CURSO'
        $orgViajesAnd
    ORDER BY v.inicio DESC
    LIMIT 10
");

$incidentes = $conexion->query("
    SELECT i.tipo, i.descripcion, i.latitud, i.longitud, i.fecha, u.nombre AS conductor, v.origen, v.destino
    FROM incidentes i
    INNER JOIN viajes v ON v.id = i.id_viaje
    INNER JOIN usuarios u ON u.id = i.id_conductor
    " . ($orgId !== null ? "WHERE v.id_organizacion = " . (int) $orgId : "") . "
    ORDER BY i.fecha DESC
    LIMIT 3
");

$conductores = $conexion->query("
    SELECT u.id, u.nombre, u.correo, u.estado,
        (SELECT COUNT(*) FROM viajes v WHERE v.id_conductor = u.id AND v.estado = 'EN_CURSO') AS ruta_activa,
        (SELECT COUNT(*) FROM alertas a WHERE a.id_conductor = u.id AND a.fecha >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS alertas_24h
    FROM usuarios u
    WHERE u.rol = 'CONDUCTOR'
        $orgUsuarios
    ORDER BY u.nombre ASC
");

$conductoresRiesgo = $conexion->query("
    SELECT u.nombre, u.correo,
        COUNT(a.id) AS alertas,
        SUM(a.nivel = 'CRITICO') AS criticas,
        ROUND(AVG(a.fatiga), 1) AS fatiga_media
    FROM usuarios u
    INNER JOIN alertas a ON a.id_conductor = u.id
    WHERE u.rol = 'CONDUCTOR'
        $orgUsuarios
        AND a.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY u.id, u.nombre, u.correo
    ORDER BY criticas DESC, fatiga_media DESC, alertas DESC
    LIMIT 5
");

$rutasCriticas = $conexion->query("
    SELECT COALESCE(v.origen, 'Origen') AS origen, COALESCE(v.destino, 'Destino') AS destino,
        COUNT(a.id) AS alertas, SUM(a.nivel = 'CRITICO') AS criticas
    FROM viajes v
    INNER JOIN alertas a ON a.id_viaje = v.id
    WHERE a.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        $orgViajesAnd
    GROUP BY v.origen, v.destino
    ORDER BY criticas DESC, alertas DESC
    LIMIT 5
");

$conexion->query("
    CREATE TABLE IF NOT EXISTS evidencias_alertas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_alerta INT NULL,
        id_viaje INT NOT NULL,
        id_conductor INT NOT NULL,
        evento VARCHAR(40) NOT NULL,
        nivel VARCHAR(20) NOT NULL,
        archivo VARCHAR(255) NOT NULL,
        mime VARCHAR(80) NOT NULL,
        bytes INT NOT NULL DEFAULT 0,
        duracion_ms INT NOT NULL DEFAULT 0,
        latitud DECIMAL(10, 7) NULL,
        longitud DECIMAL(10, 7) NULL,
        fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");
$evidencias = $conexion->query("
    SELECT e.evento, e.nivel, e.archivo, e.fecha, u.nombre AS conductor
    FROM evidencias_alertas e
    INNER JOIN viajes v ON v.id = e.id_viaje
    INNER JOIN usuarios u ON u.id = e.id_conductor
    " . ($orgId !== null ? "WHERE v.id_organizacion = " . (int) $orgId : "") . "
    ORDER BY e.fecha DESC
    LIMIT 6
");
?>
<?php include 'header.php'; ?>

<body>
<button class="menu-toggle" onclick="toggleSidebar()">
    ☰
</button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand" href="admin_dashboard.php">
                <span class="brand-mark">DS</span>
                <span>
                    <strong>DriveSafe AI</strong>
                    <small>Control central</small>
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
                <a class="active" href="admin_dashboard.php">Panel</a>
                <a href="management.php">Gestion</a>
                <a href="routes.php">Rutas</a>
                <a href="reports.php">Reportes</a>
                <a href="monitor.php">Monitoreo</a>
                <a href="logout.php">Salir</a>
            </nav>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Operacion y seguridad</p>
                    <h1>Dashboard administrador</h1>
                </div>
                <a class="primary-button compact" href="monitor.php">Iniciar monitoreo</a>
            </header>

            <section class="kpi-grid">
                <article class="metric-card">
                    <span>Score flota</span>
                    <strong><?= $scoreFlota ?></strong>
                    <small>Promedio preventivo 7 dias</small>
                </article>
                <article class="metric-card">
                    <span>Rutas activas</span>
                    <strong><?= $rutasActivas ?></strong>
                    <small>Con GPS en curso</small>
                </article>
                <article class="metric-card">
                    <span>Alertas hoy</span>
                    <strong><?= $alertasHoy ?></strong>
                    <small>Eventos biometrico-faciales</small>
                </article>
                <article class="metric-card">
                    <span>Incidentes hoy</span>
                    <strong><?= $incidentesHoy ?></strong>
                    <small><?= $alertasCriticas ?> alertas criticas historicas</small>
                </article>
            </section>

            <section class="dashboard-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Rutas activas</h2>
                        <span class="status-pill online">GPS</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th class="col-name">Conductor</th>
                                    <th class="col-route">Ruta</th>
                                    <th class="col-small">Ultima posicion</th>
                                    <th class="col-actions">Mapa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $rutas->fetch_assoc()): ?>
                                    <tr>
                                        <td class="col-name"><?= h($r["conductor"]) ?></td>
                                        <td class="col-route"><?= h(($r["origen"] ?: "Origen") . " - " . ($r["destino"] ?: "Destino")) ?></td>
                                        <td class="col-small">
                                            <?= $r["ultima_posicion"] ? date("d/m/Y H:i", strtotime($r["ultima_posicion"])) : "Sin datos" ?>
                                            <?php if ($r["velocidad"] !== null): ?>
                                                <small class="muted block"><?= h((string) round((float) $r["velocidad"], 1)) ?>
                                                    km/h</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-actions">
                                            <?php if ($r["latitud"] && $r["longitud"]): ?>
                                                <a class="text-link" target="_blank"
                                                    href="https://www.google.com/maps?q=<?= h($r["latitud"]) ?>,<?= h($r["longitud"]) ?>">Abrir
                                                    mapa</a>
                                            <?php else: ?>
                                                Pendiente
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <h2>Incidentes recientes</h2>
                    </div>
                    <div class="event-list">
                        <?php while ($i = $incidentes->fetch_assoc()): ?>
                            <div class="event-item">
                                <span class="level-badge CRITICO"><?= h($i["tipo"]) ?></span>
                                <div>
                                    <strong><?= h($i["conductor"]) ?></strong>
                                    <small><?= h($i["descripcion"]) ?> -
                                        <?= date("d/m/Y H:i", strtotime($i["fecha"])) ?></small>
                                    <?php if ($i["latitud"] && $i["longitud"]): ?>
                                        <a class="text-link" target="_blank"
                                            href="https://www.google.com/maps?q=<?= h($i["latitud"]) ?>,<?= h($i["longitud"]) ?>">Ubicacion</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Evidencias recientes</h2>
                    <span class="muted">Videos capturados durante alertas criticas</span>
                </div>
                <div class="event-list">
                    <?php if ($evidencias->num_rows === 0): ?>
                        <p class="muted">Aun no hay videos de evidencia registrados.</p>
                    <?php endif; ?>
                    <?php while ($ev = $evidencias->fetch_assoc()): ?>
                        <div class="event-item">
                            <span class="level-badge <?= h($ev["nivel"]) ?>"><?= h($ev["evento"]) ?></span>
                            <div>
                                <strong><?= h($ev["conductor"]) ?></strong>
                                <small><?= date("d/m/Y H:i", strtotime($ev["fecha"])) ?></small>
                                <a class="text-link" target="_blank" href="<?= h($ev["archivo"]) ?>">Ver grabacion</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </section><br>

            <section class="dashboard-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Conductores con mayor riesgo</h2>
                        <span class="muted">Ultimos 7 dias</span>
                    </div>
                    <div class="event-list">
                        <?php if ($conductoresRiesgo->num_rows === 0): ?>
                            <p class="muted">Sin alertas recientes registradas.</p>
                        <?php endif; ?>
                        <?php while ($r = $conductoresRiesgo->fetch_assoc()): ?>
                            <div class="event-item">
                                <span
                                    class="level-badge <?= ((int) $r["criticas"]) > 0 ? "CRITICO" : "ALTO" ?>"><?= h((string) $r["fatiga_media"]) ?></span>
                                <div>
                                    <strong><?= h($r["nombre"]) ?></strong>
                                    <small><?= h($r["correo"]) ?> - <?= h((string) $r["alertas"]) ?> alertas,
                                        <?= h((string) $r["criticas"]) ?> criticas</small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <h2>Rutas criticas</h2>
                        <span class="muted">Ultimos 30 dias</span>
                    </div>
                    <div class="event-list">
                        <?php if ($rutasCriticas->num_rows === 0): ?>
                            <p class="muted">Aun no hay rutas con alertas vinculadas.</p>
                        <?php endif; ?>
                        <?php while ($rc = $rutasCriticas->fetch_assoc()): ?>
                            <div class="event-item">
                                <span class="level-badge CRITICO"><?= h((string) $rc["criticas"]) ?></span>
                                <div>
                                    <strong><?= h($rc["origen"] . " - " . $rc["destino"]) ?></strong>
                                    <small><?= h((string) $rc["alertas"]) ?> alertas registradas</small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Conductores</h2>
                    <span class="muted"><?= $totalConductores ?> registrados - alertas de las ultimas 24 horas</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                            <th class="col-name">Nombre</th>
                            <th class="col-email">Correo</th>
                            <th class="col-small">Estado</th>
                            <th class="col-small">Ruta activa</th>
                            <th class="col-number">Alertas 24h</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($c = $conductores->fetch_assoc()): ?>
                                <tr>
                                    <td class="col-name"><?= h($c["nombre"]) ?></td>
                                    <td class="col-email"><?= h($c["correo"]) ?></td>
                                    <td class="col-small"><span class="status-pill online"><?= h($c["estado"]) ?></span></td>
                                    <td class="col-small"><?= ((int) $c["ruta_activa"]) > 0 ? "Si" : "No" ?></td>
                                    <td class="col-number"><?= h((string) $c["alertas_24h"]) ?></td>
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