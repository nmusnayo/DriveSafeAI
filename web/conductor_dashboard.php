<?php
include "db.php";
require_login();

$user = current_user();
$idConductor = (int) ($user["id"] ?? 0);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["update_profile"])) {
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

    if (isset($_POST["start_trip"])) {
        $tripId = (int) ($_POST["trip_id"] ?? 0);
        if ($tripId > 0) {
            $stmt = $conexion->prepare("
                UPDATE viajes
                SET estado = 'EN_CURSO', inicio = NOW()
                WHERE id = ? AND id_conductor = ? AND estado = 'PROGRAMADO'
            ");
            $stmt->bind_param("ii", $tripId, $idConductor);
            $stmt->execute();
            $stmt->close();
            header("Location: monitor.php?viaje_id=" . $tripId);
            exit;
        }
    }
}

$totalViajes = (int) (db_value($conexion, "SELECT COUNT(*) FROM viajes WHERE id_conductor = ?", "i", [$idConductor]) ?? 0);
$viajesActivos = (int) (db_value($conexion, "SELECT COUNT(*) FROM viajes WHERE id_conductor = ? AND estado = 'EN_CURSO'", "i", [$idConductor]) ?? 0);
$alertasConductor = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE id_conductor = ?", "i", [$idConductor]) ?? 0);
$fatigaPromedio = round((float) (db_value($conexion, "SELECT AVG(fatiga) FROM alertas WHERE id_conductor = ?", "i", [$idConductor]) ?? 0), 1);
$alertas7d = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE id_conductor = ? AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)", "i", [$idConductor]) ?? 0);
$criticas7d = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE id_conductor = ? AND nivel = 'CRITICO' AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)", "i", [$idConductor]) ?? 0);
$fatiga7d = round((float) (db_value($conexion, "SELECT AVG(fatiga) FROM alertas WHERE id_conductor = ? AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)", "i", [$idConductor]) ?? 0), 1);
$scoreConductor = max(0, min(100, (int) round(100 - $fatiga7d - ($criticas7d * 6))));
$estadoPreventivo = $scoreConductor >= 80 ? "Apto" : ($scoreConductor >= 60 ? "Precaucion" : "Descanso recomendado");

$viajeActivo = null;
$stmt = $conexion->prepare("
    SELECT id, origen, destino, inicio
    FROM viajes
    WHERE id_conductor = ? AND estado = 'EN_CURSO'
    ORDER BY inicio DESC
    LIMIT 1
");
$stmt->bind_param("i", $idConductor);
$stmt->execute();
$viajeActivo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$viajes = $conexion->prepare("
    SELECT v.id, v.origen, v.destino, v.estado, v.inicio, v.fin,
        (SELECT COUNT(*) FROM posiciones_ruta p WHERE p.id_viaje = v.id) AS puntos,
        (SELECT COUNT(*) FROM incidentes i WHERE i.id_viaje = v.id) AS incidentes
    FROM viajes v
    WHERE v.id_conductor = ?
    ORDER BY COALESCE(v.inicio, v.creado_en) DESC
    LIMIT 8
");
$viajes->bind_param("i", $idConductor);
$viajes->execute();
$viajesResult = $viajes->get_result();

$rutasProgramadas = $conexion->prepare("
    SELECT v.id, v.origen, v.destino, vh.placa, vh.marca, vh.modelo
    FROM viajes v
    LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
    WHERE v.id_conductor = ? AND v.estado = 'PROGRAMADO'
    ORDER BY v.creado_en ASC
");
$rutasProgramadas->bind_param("i", $idConductor);
$rutasProgramadas->execute();
$rutasProgramadasResult = $rutasProgramadas->get_result();

$alertas = $conexion->prepare("
    SELECT evento, nivel, fatiga, recomendacion, fecha, latitud, longitud
    FROM alertas
    WHERE id_conductor = ?
    ORDER BY fecha DESC
    LIMIT 8
");
$alertas->bind_param("i", $idConductor);
$alertas->execute();
$alertasResult = $alertas->get_result();
?>
<?php include 'header.php'; ?>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="conductor_dashboard.php">
            <span class="brand-mark">DS</span>
            <span>
                <strong>DriveSafe AI</strong>
                <small>Panel conductor</small>
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
            <a class="active" href="conductor_dashboard.php">Panel</a>
            <a href="routes.php">Rutas</a>
            <a href="reports.php">Reportes</a>
            <a href="monitor.php">Monitoreo</a>
            <a href="logout.php">Salir</a>
        </nav>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Bienvenido</p>
                <h1><?= h($user["nombre"] ?? "Conductor") ?></h1>
            </div>
            <a class="primary-button compact" href="monitor.php">Iniciar ruta</a>
        </header>

        <?php if ($viajeActivo): ?>
            <section class="route-banner">
                <div>
                    <span class="status-pill online" color="white">Ruta en curso</span>
                    <h2 style="color:#2563eb;font-weight:700;">
                        🚚 <?= h($viajeActivo["origen"] ?: "Origen no definido") ?>
                        <span style="color:#94a3b8;">→</span>
                        <?= h($viajeActivo["destino"] ?: "Destino no definido") ?>
                    </h2>
                    <p class="muted">Inicio: <?= date("d/m/Y H:i", strtotime($viajeActivo["inicio"])) ?></p>
                </div>
                <a class="secondary-button" href="monitor.php">Continuar seguimiento</a>
            </section>
        <?php endif; ?>

        <?php if ($rutasProgramadasResult->num_rows > 0): ?>
            <section class="panel">
                <div class="panel-heading">
                    <h2>Rutas programadas</h2>
                    <span class="muted"><?= $rutasProgramadasResult->num_rows ?> pendientes</span>
                </div>
                <div class="event-list">
                    <?php while ($r = $rutasProgramadasResult->fetch_assoc()): ?>
                        <div class="event-item">
                            <span class="status-pill">PROGRAMADO</span>
                            <div>
                                <strong><?= h($r["origen"] ?: "Origen") ?> a <?= h($r["destino"] ?: "Destino") ?></strong>
                                <?php if ($r["placa"]): ?>
                                    <small><?= h($r["placa"]) ?> - <?= h($r["marca"] ?? "") ?>             <?= h($r["modelo"] ?? "") ?></small>
                                <?php else: ?>
                                    <small>Sin vehículo asignado</small>
                                <?php endif; ?>
                            </div>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="start_trip" value="1">
                                <input type="hidden" name="trip_id" value="<?= h((string) $r["id"]) ?>">
                                <button class="primary-button compact" type="submit">Iniciar ruta</button>
                            </form>
                        </div>
                    <?php endwhile; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="kpi-grid">
            <article class="metric-card">
                <span>Score DriveSafe</span>
                <strong><?= $scoreConductor ?></strong>
                <small><?= h($estadoPreventivo) ?></small>
            </article>
            <article class="metric-card">
                <span>En curso</span>
                <strong><?= $viajesActivos ?></strong>
                <small>Seguimiento GPS activo</small>
            </article>
            <article class="metric-card">
                <span>Alertas 7 dias</span>
                <strong><?= $alertas7d ?></strong>
                <small><?= $alertasConductor ?> eventos en historial</small>
            </article>
            <article class="metric-card">
                <span>Fatiga media</span>
                <strong><?= $fatiga7d ?: $fatigaPromedio ?></strong>
                <small>Escala biometrica 0 - 100</small>
            </article>
        </section>

        <section class="route-banner">
            <div>
                <span class="status-pill <?= $scoreConductor >= 80 ? "online" : "" ?>">Prevencion activa</span>
                <h2 class="prevention-title">
                    <?= h($estadoPreventivo) ?>
                </h2>
                <p class="muted">
                    <?= $criticas7d > 0
                        ? "Se detectaron alertas criticas recientes. Prioriza una pausa antes de rutas largas."
                        : "Mantener descansos programados mejora el score y reduce riesgo de microsuenos." ?>
                </p>
            </div>
            <a class="secondary-button" href="reports.php">Ver reporte</a>
        </section>

        <section class="dashboard-grid">
            <article class="panel">
                <div class="panel-heading">
                    <h2>Mis rutas recientes</h2>
                    <span class="muted"><?= $totalViajes ?> viajes</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-route">Ruta</th>
                                <th class="col-small">Estado</th>
                                <th class="col-small">Inicio</th>
                                <th class="col-number">Puntos GPS</th>
                                <th class="col-number">Incidentes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($v = $viajesResult->fetch_assoc()): ?>
                                <tr>
                                    <td class="col-route">
                                        <?= h(($v["origen"] ?: "Origen") . " - " . ($v["destino"] ?: "Destino")) ?>
                                    </td>
                                    <td class="col-small"><span class="status-pill"><?= h($v["estado"]) ?></span></td>
                                    <td class="col-small">
                                        <?= $v["inicio"] ? date("d/m/Y H:i", strtotime($v["inicio"])) : "Sin iniciar" ?>
                                    </td>
                                    <td class="col-number"><?= h((string) $v["puntos"]) ?></td>
                                    <td class="col-number"><?= h((string) $v["incidentes"]) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="panel">
                <div class="panel-heading">
                    <h2>Checklist rapido</h2>
                </div>
                <div class="check-list">
                    <label><input type="checkbox"> Camara frontal limpia</label>
                    <label><input type="checkbox"> GPS autorizado</label>
                    <label><input type="checkbox"> Descanso previo confirmado</label>
                    <label><input type="checkbox"> Ruta y destino definidos</label>
                </div>
            </article>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <h2>Mis alertas recientes</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="col-name">Evento</th>
                            <th class="col-small">Nivel</th>
                            <th class="col-number">Fatiga</th>
                            <th class="col-name">Recomendacion</th>
                            <th class="col-actions">Ubicacion</th>
                            <th class="col-small">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($a = $alertasResult->fetch_assoc()): ?>
                            <tr>
                                <td class="col-name"><?= h($a["evento"]) ?></td>
                                <td class="col-small"><span
                                        class="level-badge <?= h($a["nivel"]) ?>"><?= h($a["nivel"]) ?></span>
                                </td>
                                <td class="col-number"><?= h((string) $a["fatiga"]) ?></td>
                                <td class="col-name"><?= h($a["recomendacion"]) ?></td>
                                <td class="col-actions">
                                    <?php if ($a["latitud"] && $a["longitud"]): ?>
                                        <a class="text-link" target="_blank"
                                            href="https://www.google.com/maps?q=<?= h($a["latitud"]) ?>,<?= h($a["longitud"]) ?>">Ver
                                            mapa</a>
                                    <?php else: ?>
                                        Sin GPS
                                    <?php endif; ?>
                                </td>
                                <td class="col-small"><?= date("d/m/Y H:i", strtotime($a["fecha"])) ?></td>
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