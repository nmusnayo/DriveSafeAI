<?php
session_start();
include "db.php";

header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["user"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Metodo no permitido"]);
    exit;
}

$user = $_SESSION["user"];
$idConductor = (int) ($user["id"] ?? 0);
$idViaje = (int) ($_POST["id_viaje"] ?? 0);
$idAlerta = (int) ($_POST["id_alerta"] ?? 0);
$evento = strtoupper(trim((string) ($_POST["evento"] ?? "MONITOREO")));
$nivel = strtoupper(trim((string) ($_POST["nivel"] ?? "BAJO")));
$latitud = isset($_POST["latitud"]) && is_numeric($_POST["latitud"]) ? (float) $_POST["latitud"] : null;
$longitud = isset($_POST["longitud"]) && is_numeric($_POST["longitud"]) ? (float) $_POST["longitud"] : null;
$durationMs = max(0, (int) ($_POST["duration_ms"] ?? 0));
$fechaEvento = trim((string) ($_POST["fecha_evento"] ?? ""));
$fechaSql = $fechaEvento !== "" ? date("Y-m-d H:i:s", strtotime($fechaEvento)) : null;

if ($idViaje <= 0 || empty($_FILES["video"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Video o viaje requerido"]);
    exit;
}

$viajePermitido = (int) (db_value(
    $conexion,
    "SELECT COUNT(*) FROM viajes WHERE id = ? AND id_conductor = ?",
    "ii",
    [$idViaje, $idConductor]
) ?? 0);

if ($viajePermitido === 0) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Viaje no autorizado"]);
    exit;
}

$file = $_FILES["video"];
$maxBytes = 100 * 1024 * 1024;

if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file["size"] ?? 0) <= 0 || $file["size"] > $maxBytes) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Archivo invalido o demasiado grande"]);
    exit;
}

$mime = mime_content_type($file["tmp_name"]) ?: "video/webm";
if (!in_array($mime, ["video/webm", "video/mp4", "application/octet-stream"], true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Formato de video no permitido"]);
    exit;
}

$evidenceDir = __DIR__ . DIRECTORY_SEPARATOR . "evidence";
if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo crear carpeta de evidencia"]);
    exit;
}

$safeEvento = preg_replace("/[^A-Z0-9_]/", "", $evento) ?: "EVENTO";
$extension = "webm";

if (str_contains($mime, "mp4")) {
    $extension = "mp4";
}

$filename = sprintf(
    "viaje_%d_alerta_%d_%s_%s.%s",
    $idViaje,
    $idAlerta,
    $safeEvento,
    date("Ymd_His"),
    $extension
);
$relativePath = "evidence/" . $filename;
$target = $evidenceDir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file["tmp_name"], $target)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo guardar el video"]);
    exit;
}

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

$stmt = $conexion->prepare("
    INSERT INTO evidencias_alertas
        (id_alerta, id_viaje, id_conductor, evento, nivel, archivo, mime, bytes, duracion_ms, latitud, longitud, fecha)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
");
$bytes = (int) $file["size"];
$idAlertaParam = $idAlerta > 0 ? $idAlerta : null;
$stmt->bind_param(
    "iiissssiidds",
    $idAlertaParam,
    $idViaje,
    $idConductor,
    $evento,
    $nivel,
    $relativePath,
    $mime,
    $bytes,
    $durationMs,
    $latitud,
    $longitud,
    $fechaSql
);
$ok = $stmt->execute();
$idEvidencia = $conexion->insert_id;
$stmt->close();

echo json_encode([
    "ok" => $ok,
    "id_evidencia" => $idEvidencia,
    "archivo" => $relativePath
]);
?>
