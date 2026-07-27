<?php
include "db.php";
require_login();

$user = current_user();
$isAdmin = is_admin_role($user);
$panelUrl = role_dashboard_url($user);
$idConductor = (int) ($user["id"] ?? 0);
$orgId = current_org_id($user);

if ($isAdmin && $orgId !== null) {
    $whereConductor = "WHERE v.id_organizacion = ?";
    $types = "i";
    $params = [$orgId];
} elseif ($isAdmin) {
    $whereConductor = "";
    $types = "";
    $params = [];
} else {
    $whereConductor = "WHERE v.id_conductor = ?";
    $types = "i";
    $params = [$idConductor];
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

$totalViajes = (int) (db_value($conexion, "SELECT COUNT(*) FROM viajes v $whereConductor", $types, $params) ?? 0);
if ($isAdmin && $orgId !== null) {

    $viajesFinalizados = (int) (db_value(
        $conexion,
        "SELECT COUNT(*)
         FROM viajes v
         WHERE v.id_organizacion = ?
         AND v.estado = 'FINALIZADO'",
        "i",
        [$orgId]
    ) ?? 0);

} elseif ($isAdmin) {

    $viajesFinalizados = (int) (db_value(
        $conexion,
        "SELECT COUNT(*)
         FROM viajes v
         WHERE v.estado = 'FINALIZADO'"
    ) ?? 0);

} else {

    $viajesFinalizados = (int) (db_value(
        $conexion,
        "SELECT COUNT(*)
         FROM viajes v
         WHERE v.id_conductor = ?
         AND v.estado = 'FINALIZADO'",
        "i",
        [$idConductor]
    ) ?? 0);

}
$totalAlertas = (int) (db_value($conexion, "
    SELECT COUNT(*)
    FROM alertas a
    INNER JOIN viajes v ON v.id = a.id_viaje
    $whereConductor
", $types, $params) ?? 0);
$fatigaMedia = round((float) (db_value($conexion, "
    SELECT AVG(a.fatiga)
    FROM alertas a
    INNER JOIN viajes v ON v.id = a.id_viaje
    $whereConductor
", $types, $params) ?? 0), 1);
$score = max(0, min(100, (int) round(100 - $fatigaMedia)));
$incidentes = (int) (db_value($conexion, "
    SELECT COUNT(*)
    FROM incidentes i
    INNER JOIN viajes v ON v.id = i.id_viaje
    $whereConductor
", $types, $params) ?? 0);

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
        fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_evidencias_alerta (id_alerta),
        INDEX idx_evidencias_viaje (id_viaje),
        INDEX idx_evidencias_fecha (fecha)
    )
");
$totalEvidencias = (int) (db_value($conexion, "
    SELECT COUNT(*)
    FROM evidencias_alertas e
    INNER JOIN viajes v ON v.id = e.id_viaje
    $whereConductor
", $types, $params) ?? 0);

if ($isAdmin) {
    $sesionesSql = "
        SELECT v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre AS conductor,
            vh.placa, vh.marca, vh.modelo,
            COUNT(a.id) AS alertas,
            COALESCE(SUM(a.nivel = 'CRITICO'), 0) AS criticas,
            COALESCE(ROUND(AVG(a.fatiga), 1), 0) AS fatiga_media,
            COUNT(DISTINCT i.id) AS incidentes
        FROM viajes v
        INNER JOIN usuarios u ON u.id = v.id_conductor
        LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
        LEFT JOIN alertas a ON a.id_viaje = v.id
        LEFT JOIN incidentes i ON i.id_viaje = v.id
        $whereConductor
        GROUP BY v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre, vh.placa, vh.marca, vh.modelo
        ORDER BY COALESCE(v.inicio, v.creado_en) DESC
        LIMIT 30
    ";
    if ($orgId !== null) {
        $stmt = $conexion->prepare($sesionesSql);
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $sesiones = $stmt->get_result();
    } else {
        $sesiones = $conexion->query($sesionesSql);
    }

    $evidenciasSql = "
        SELECT e.evento, e.nivel, e.archivo, e.bytes, e.duracion_ms, e.latitud, e.longitud, e.fecha,
            u.nombre AS conductor, v.origen, v.destino
        FROM evidencias_alertas e
        INNER JOIN viajes v ON v.id = e.id_viaje
        INNER JOIN usuarios u ON u.id = e.id_conductor
        $whereConductor
        ORDER BY e.fecha DESC
        LIMIT 20
    ";
    if ($orgId !== null) {
        $stmt = $conexion->prepare($evidenciasSql);
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $evidencias = $stmt->get_result();
    } else {
        $evidencias = $conexion->query($evidenciasSql);
    }
} else {
    $stmt = $conexion->prepare("
        SELECT v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre AS conductor,
            vh.placa, vh.marca, vh.modelo,
            COUNT(a.id) AS alertas,
            COALESCE(SUM(a.nivel = 'CRITICO'), 0) AS criticas,
            COALESCE(ROUND(AVG(a.fatiga), 1), 0) AS fatiga_media,
            COUNT(DISTINCT i.id) AS incidentes
        FROM viajes v
        INNER JOIN usuarios u ON u.id = v.id_conductor
        LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
        LEFT JOIN alertas a ON a.id_viaje = v.id
        LEFT JOIN incidentes i ON i.id_viaje = v.id
        WHERE v.id_conductor = ?
        GROUP BY v.id, v.origen, v.destino, v.estado, v.inicio, v.fin, u.nombre, vh.placa, vh.marca, vh.modelo
        ORDER BY COALESCE(v.inicio, v.creado_en) DESC
        LIMIT 30
    ");
    $stmt->bind_param("i", $idConductor);
    $stmt->execute();
    $sesiones = $stmt->get_result();

    $stmt = $conexion->prepare("
        SELECT e.evento, e.nivel, e.archivo, e.bytes, e.duracion_ms, e.latitud, e.longitud, e.fecha,
            u.nombre AS conductor, v.origen, v.destino
        FROM evidencias_alertas e
        INNER JOIN viajes v ON v.id = e.id_viaje
        INNER JOIN usuarios u ON u.id = e.id_conductor
        WHERE e.id_conductor = ?
        ORDER BY e.fecha DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $idConductor);
    $stmt->execute();
    $evidencias = $stmt->get_result();
}

$niveles = ["BAJO" => 0, "MEDIO" => 0, "ALTO" => 0, "CRITICO" => 0];
$nivelSql = "
    SELECT a.nivel, COUNT(*) AS total
    FROM alertas a
    INNER JOIN viajes v ON v.id = a.id_viaje
    $whereConductor
    GROUP BY a.nivel
";

if ($isAdmin) {

    if ($orgId !== null) {

        $stmt = $conexion->prepare($nivelSql);
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $nivelResult = $stmt->get_result();

    } else {

        $nivelResult = $conexion->query($nivelSql);

    }

} else {

    $stmt = $conexion->prepare($nivelSql);
    $stmt->bind_param("i", $idConductor);
    $stmt->execute();
    $nivelResult = $stmt->get_result();

}

while ($row = $nivelResult->fetch_assoc()) {
    $niveles[$row["nivel"]] = (int) $row["total"];
}
?>
<?php include 'header.php'; ?>

    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= h($panelUrl) ?>">
                <span class="brand-mark">DS</span>
                <span>
                    <strong>DriveSafe AI</strong>
                    <small>Reportes</small>
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
                <a href="routes.php">Rutas</a>
                <a class="active" href="reports.php">Reportes</a>
                <a href="monitor.php">Monitoreo</a>
                <a href="logout.php">Salir</a>
            </nav>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Historial digital de conduccion</p>
                    <h1><?= $isAdmin ? "Reportes de flota" : "Mi reporte DriveSafe" ?></h1>
                </div>
                <a class="primary-button compact" href="monitor.php">Nueva sesion</a>
            </header>

            <section class="kpi-grid">
                <article class="metric-card">
                    <span>Score</span>
                    <strong><?= $score ?></strong>
                    <small>Base para seguridad y aseguradoras</small>
                </article>
                <article class="metric-card">
                    <span>Sesiones</span>
                    <strong><?= $totalViajes ?></strong>
                    <small><?= $viajesFinalizados ?> finalizadas</small>
                </article>
                <article class="metric-card">
                    <span>Alertas</span>
                    <strong><?= $totalAlertas ?></strong>
                    <small>Eventos registrados</small>
                </article>
                <article class="metric-card">
                    <span>Incidentes</span>
                    <strong><?= $incidentes ?></strong>
                    <small><?= $totalEvidencias ?> videos de evidencia</small>
                </article>
            </section>

            <section class="dashboard-grid">
                <article class="panel">
                    <div class="panel-heading">
                        <h2>Distribucion de alertas</h2>
                        <span class="muted">Por nivel de riesgo</span>
                    </div>
                    <div class="risk-bars">
                        <?php foreach ($niveles as $nivel => $total): ?>
                            <?php $width = $totalAlertas > 0 ? max(4, (int) round(($total / $totalAlertas) * 100)) : 4; ?>
                            <div class="risk-row">
                                <span class="level-badge <?= h($nivel) ?>"><?= h($nivel) ?></span>
                                <div class="risk-track">
                                    <div style="width: <?= $width ?>%"></div>
                                </div>
                                <strong><?= $total ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <h2>Privacidad operativa</h2>
                    </div>
                    <div class="health-list">
                        <div><strong>Video</strong><span>No se almacena</span></div>
                        <div><strong>Biometria</strong><span>Solo metricas EAR/MAR</span></div>
                        <div><strong>GPS</strong><span>Vinculado a ruta activa</span></div>
                        <div><strong>Consentimiento</strong><span>Inicio manual de sesion</span></div>
                    </div>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Sesiones de conduccion</h2>
                    <span class="muted">Resumen post-sesion</span>
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
                                <th class="col-number">Fatiga media</th>
                                <th class="col-number">Alertas</th>
                                <th class="col-number">Criticas</th>
                                <th class="col-number">Incidentes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($s = $sesiones->fetch_assoc()): ?>
                                <tr>
                                    <td class="col-name"><?= h($s["conductor"]) ?></td>
                                    <td class="col-vehicle"><?= h($s["placa"] ? trim($s["placa"] . " " . ($s["marca"] ?? "") . " " . ($s["modelo"] ?? "")) : "Sin asignar") ?>
                                    </td>
                                    <td class="col-route"><?= h(($s["origen"] ?: "Origen") . " - " . ($s["destino"] ?: "Destino")) ?></td>
                                    <td class="col-small"><span class="status-pill"><?= h($s["estado"]) ?></span></td>
                                    <td class="col-small"><?= $s["inicio"] ? date("d/m/Y H:i", strtotime($s["inicio"])) : "Sin iniciar" ?>
                                    </td>
                                    <td class="col-small"><?= $s["fin"] ? date("d/m/Y H:i", strtotime($s["fin"])) : "-" ?></td>
                                    <td class="col-number"><?= h((string) ($s["fatiga_media"] ?? 0)) ?></td>
                                    <td class="col-number"><?= h((string) $s["alertas"]) ?></td>
                                    <td class="col-number"><span
                                            class="level-badge <?= ((int) $s["criticas"]) > 0 ? "CRITICO" : "BAJO" ?>"><?= h((string) $s["criticas"]) ?></span>
                                    </td>
                                    <td class="col-number"><?= h((string) $s["incidentes"]) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Evidencias recientes</h2>
                    <span class="muted">Clips guardados en alertas de alto riesgo</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-name">Conductor</th>
                                <th class="col-name">Evento</th>
                                <th class="col-small">Nivel</th>
                                <th class="col-route">Ruta</th>
                                <th class="col-actions">Video</th>
                                <th class="col-actions">Ubicacion</th>
                                <th class="col-small">Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($evidencias->num_rows === 0): ?>
                                <tr>
                                    <td colspan="7" class="muted">Aun no hay clips de evidencia registrados.</td>
                                </tr>
                            <?php endif; ?>
                            <?php while ($ev = $evidencias->fetch_assoc()): ?>
                                <tr>
                                    <td class="col-name"><?= h($ev["conductor"]) ?></td>
                                    <td class="col-name"><?= h($ev["evento"]) ?></td>
                                    <td class="col-small"><span class="level-badge <?= h($ev["nivel"]) ?>"><?= h($ev["nivel"]) ?></span></td>
                                    <td class="col-route"><?= h(($ev["origen"] ?: "Origen") . " - " . ($ev["destino"] ?: "Destino")) ?></td>
                                    <td class="col-actions">
                                        <a class="text-link" target="_blank" href="<?= h($ev["archivo"]) ?>">Ver clip</a>
                                        <small class="muted block"><?= h((string) round(((int) $ev["bytes"]) / 1024, 1)) ?>
                                            KB</small>
                                    </td>
                                    <td class="col-actions">
                                        <?php if ($ev["latitud"] && $ev["longitud"]): ?>
                                            <a class="text-link" target="_blank"
                                                href="https://www.google.com/maps?q=<?= h($ev["latitud"]) ?>,<?= h($ev["longitud"]) ?>">Ver
                                                mapa</a>
                                        <?php else: ?>
                                            Sin GPS
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-small"><?= date("d/m/Y H:i", strtotime($ev["fecha"])) ?></td>
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