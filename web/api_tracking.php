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

$payload = json_decode(file_get_contents("php://input"), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "JSON invalido"]);
    exit;
}

$action = $payload["action"] ?? "";
$user = $_SESSION["user"];
$idConductor = (int) ($user["id"] ?? 0);
$idOrganizacion = current_org_id($user);

function as_float($value): ?float
{
    if ($value === null || $value === "") {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

if ($action === "start") {
    $origen = trim((string) ($payload["origen"] ?? "Origen no definido"));
    $destino = trim((string) ($payload["destino"] ?? "Destino no definido"));
    $idVehiculo = isset($payload["id_vehiculo"]) && $payload["id_vehiculo"] !== null && $payload["id_vehiculo"] !== ""
        ? (int) $payload["id_vehiculo"]
        : null;

    if ($idVehiculo !== null) {
        if ($idOrganizacion !== null) {
            $vehiculoActivo = (int) (db_value($conexion, "SELECT COUNT(*) FROM vehiculos WHERE id = ? AND estado = 'ACTIVO' AND id_organizacion = ?", "ii", [$idVehiculo, $idOrganizacion]) ?? 0);
        } else {
            $vehiculoActivo = (int) (db_value($conexion, "SELECT COUNT(*) FROM vehiculos WHERE id = ? AND estado = 'ACTIVO'", "i", [$idVehiculo]) ?? 0);
        }
        if ($vehiculoActivo === 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Vehiculo no disponible"]);
            exit;
        }
    }

    $stmt = $conexion->prepare("
        INSERT INTO viajes (id_organizacion, id_conductor, id_vehiculo, origen, destino, estado, inicio)
        VALUES (?, ?, ?, ?, ?, 'EN_CURSO', NOW())
    ");
    $stmt->bind_param("iiiss", $idOrganizacion, $idConductor, $idVehiculo, $origen, $destino);
    $ok = $stmt->execute();
    $idViaje = $conexion->insert_id;
    $stmt->close();

    echo json_encode(["ok" => $ok, "id_viaje" => $idViaje]);
    exit;
}

if ($action === "end") {
    $idViaje = (int) ($payload["id_viaje"] ?? 0);
    $stmt = $conexion->prepare("
        UPDATE viajes
        SET estado = 'FINALIZADO', fin = NOW()
        WHERE id = ? AND id_conductor = ? AND estado = 'EN_CURSO'
    ");
    $stmt->bind_param("ii", $idViaje, $idConductor);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => $ok]);
    exit;
}

if ($action === "position") {
    $idViaje = (int) ($payload["id_viaje"] ?? 0);
    $latitud = as_float($payload["latitud"] ?? null);
    $longitud = as_float($payload["longitud"] ?? null);
    $precisionGps = as_float($payload["precision_gps"] ?? null);
    $velocidad = as_float($payload["velocidad"] ?? null);

    if (!$idViaje || $latitud === null || $longitud === null) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Datos GPS incompletos"]);
        exit;
    }

    $stmt = $conexion->prepare("
        INSERT INTO posiciones_ruta (id_viaje, id_conductor, latitud, longitud, precision_gps, velocidad)
        SELECT ?, ?, ?, ?, ?, ?
        FROM viajes
        WHERE id = ? AND id_conductor = ? AND estado = 'EN_CURSO'
    ");
    $stmt->bind_param("iiddddii", $idViaje, $idConductor, $latitud, $longitud, $precisionGps, $velocidad, $idViaje, $idConductor);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => $ok]);
    exit;
}

if ($action === "incident") {
    $idViaje = (int) ($payload["id_viaje"] ?? 0);
    $tipo = strtoupper(trim((string) ($payload["tipo"] ?? "INCIDENTE")));
    $descripcion = trim((string) ($payload["descripcion"] ?? "Incidente reportado"));
    $latitud = as_float($payload["latitud"] ?? null);
    $longitud = as_float($payload["longitud"] ?? null);

    if (!$idViaje) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Viaje requerido"]);
        exit;
    }

    $stmt = $conexion->prepare("
        INSERT INTO incidentes (id_viaje, id_conductor, tipo, descripcion, latitud, longitud)
        SELECT ?, ?, ?, ?, ?, ?
        FROM viajes
        WHERE id = ? AND id_conductor = ?
    ");
    $stmt->bind_param("iissddii", $idViaje, $idConductor, $tipo, $descripcion, $latitud, $longitud, $idViaje, $idConductor);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(["ok" => false, "error" => "Accion no reconocida"]);
?>
