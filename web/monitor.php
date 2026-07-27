<?php
include "db.php";
require_login();

$user = current_user();
$panelUrl = role_dashboard_url($user);
$orgId = current_org_id($user);
$orgVehicleFilter = $orgId !== null ? "AND id_organizacion = " . (int) $orgId : "";
$vehiculos = $conexion->query("
    SELECT id, placa, marca, modelo
    FROM vehiculos
    WHERE estado = 'ACTIVO'
        $orgVehicleFilter
    ORDER BY placa ASC
");

// Cargar datos de ruta programada si se pasa viaje_id
$viajeId = isset($_GET["viaje_id"]) ? (int) $_GET["viaje_id"] : 0;
$viajeDatos = null;
if ($viajeId > 0) {
    $stmt = $conexion->prepare("
        SELECT v.id, v.origen, v.destino, v.id_vehiculo, vh.placa, vh.marca, vh.modelo
        FROM viajes v
        LEFT JOIN vehiculos vh ON vh.id = v.id_vehiculo
        WHERE v.id = ? AND v.id_conductor = ? AND v.estado = 'EN_CURSO'
    ");
    $stmt->bind_param("ii", $viajeId, $user["id"]);
    $stmt->execute();
    $viajeDatos = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <title>DriveSafe AI - Monitoreo</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body class="monitor-body">
    <main class="monitor-layout">
        <section class="camera-stage">
            <video id="inputVideo" playsinline muted></video>
            <canvas id="outputCanvas"></canvas>
            <div class="monitor-overlay">
                <a href="<?= h($panelUrl) ?>" class="ghost-button" id="panelLink" data-panel-url="<?= h($panelUrl) ?>">Panel</a>
                <span id="connectionState" class="status-pill">Listo</span>
            </div>
        </section>

        <aside class="driver-console">
            <div>
                <p class="eyebrow">Sesion activa</p>
                <h1><?= h($user["nombre"] ?? "Conductor") ?></h1>
            </div>

            <form class="route-form" id="routeForm">
                <input type="hidden" id="viajeId" name="viaje_id" value="<?= $viajeId ?>">
                <label>
                    Origen
                    <input id="routeOrigin" name="origen" placeholder="Ej. Terminal central" required
                           value="<?= h($viajeDatos["origen"] ?? "") ?>"
                           <?= $viajeDatos ? "readonly" : "" ?>>
                </label>
                <label>
                    Destino
                    <input id="routeDestination" name="destino" placeholder="Ej. Zona norte" required
                           value="<?= h($viajeDatos["destino"] ?? "") ?>"
                           <?= $viajeDatos ? "readonly" : "" ?>>
                </label>
                <label>
                    Vehiculo
                    <select id="routeVehicle" name="id_vehiculo" <?= $viajeDatos ? "disabled" : "" ?>>
                        <option value="">Sin asignar</option>
                        <?php while ($vehiculo = $vehiculos->fetch_assoc()): ?>
                            <option value="<?= h((string) $vehiculo["id"]) ?>"
                                    <?= ($viajeDatos && (int) $viajeDatos["id_vehiculo"] === (int) $vehiculo["id"]) ? "selected" : "" ?>>
                                <?= h(trim($vehiculo["placa"] . " " . ($vehiculo["marca"] ?? "") . " " . ($vehiculo["modelo"] ?? ""))) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </label>
            </form>

            <div class="fatigue-meter" aria-live="polite">
                <span id="levelText">BAJO</span>
                <strong id="fatigueValue">0</strong>
                <small id="recommendationText">Conduccion normal</small>
                <div class="meter-track"><div id="meterFill"></div></div>
            </div>

            <div class="compact-grid">
                <div class="mini-stat">
                    <span>Bostezos</span>
                    <strong id="yawnCount">0</strong>
                </div>
                <div class="mini-stat">
                    <span>Microsuenos</span>
                    <strong id="sleepCount">0</strong>
                </div>
                <div class="mini-stat">
                    <span>Tiempo</span>
                    <strong id="sessionTime">00:00</strong>
                </div>
                <div class="mini-stat">
                    <span>Rostro</span>
                    <strong id="faceState">No</strong>
                </div>
                <div class="mini-stat">
                    <span>GPS</span>
                    <strong id="gpsState">No</strong>
                </div>
                <div class="mini-stat">
                    <span>Ruta</span>
                    <strong id="routeState">Lista</strong>
                </div>
            </div>

            <div id="secureWarning" class="alert warning" hidden>
                La camara del celular requiere HTTPS o abrir desde localhost.
            </div>

            <div id="offlineNotice" class="alert warning" hidden>
                Sin conexion. Los eventos se guardan en este dispositivo.
            </div>

            <div class="control-row">
                <button id="startButton" class="primary-button" type="button">Iniciar</button>
                <button id="stopButton" class="secondary-button" type="button" disabled>Detener</button>
            </div>

            <button id="finishButton" class="secondary-button" type="button">Salir al panel</button>
            <button id="incidentButton" class="danger-button" type="button" disabled>Reportar accidente</button>
        </aside>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
    <script src="assets/monitor.js?v=<?= filemtime(__DIR__ . '/assets/monitor.js') ?>"></script>
</body>
</html>
