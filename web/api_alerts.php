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

$user = $_SESSION["user"] ?? null;
$idConductor = (int) ($user["id"] ?? 0);
$idViaje = isset($payload["id_viaje"]) && $payload["id_viaje"] !== null ? (int) $payload["id_viaje"] : null;
$evento = strtoupper(trim((string) ($payload["evento"] ?? "MONITOREO")));
$nivel = strtoupper(trim((string) ($payload["nivel"] ?? "BAJO")));

// Calcular fatiga: preferir fatiga enviada por el cliente; si no existe, usar el predictor Python si se reciben features
$fatiga = null;
if (isset($payload["fatiga"]) && $payload["fatiga"] !== null) {
    $fatiga = max(0, min(100, (int) round((float) $payload["fatiga"])));
} elseif (isset($payload["ojos"]) && isset($payload["bostezos"]) && isset($payload["tiempo"])) {
    $ojos = (float) $payload["ojos"];
    $bostezos = (float) $payload["bostezos"];
    $tiempo = (float) $payload["tiempo"];

    // Intentar llamar al microservicio FastAPI en localhost:8000/predict
    $predict_ok = false;
    $pred_value = 0.0;

    if (function_exists('curl_init')) {
        $ch = curl_init('http://127.0.0.1:8000/predict');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        $body = json_encode(["ojos" => $ojos, "bostezos" => $bostezos, "tiempo" => $tiempo]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($res !== false && !$err) {
            $decoded = json_decode($res, true);
            if (is_array($decoded) && isset($decoded['fatiga'])) {
                $pred_value = (float) $decoded['fatiga'];
                $predict_ok = true;
            }
        }
    }

    // Fallback: si el servicio no responde, usar el predictor.py por shell_exec
    if (!$predict_ok) {
        $py = 'python';
        $cmd = $py . ' ' . escapeshellarg(__DIR__ . '/../predictor.py') . ' ' . escapeshellarg((string)$ojos) . ' ' . escapeshellarg((string)$bostezos) . ' ' . escapeshellarg((string)$tiempo);
        $out = null;
        try {
            $out = shell_exec($cmd);
            $pred_value = is_string($out) ? floatval(trim($out)) : 0.0;
        } catch (Exception $e) {
            $pred_value = 0.0;
        }
    }

    $fatiga = max(0, min(100, (int) round($pred_value)));
} else {
    $fatiga = 0;
}

// Si el cliente no envió un nivel explícito, recalcular nivel a partir del valor de fatiga
if (!isset($payload["nivel"])) {
    if ($fatiga >= 72) {
        $nivel = "CRITICO";
    } elseif ($fatiga >= 48) {
        $nivel = "ALTO";
    } elseif ($fatiga >= 24) {
        $nivel = "MEDIO";
    } else {
        $nivel = "BAJO";
    }
}

$recomendacion = trim((string) ($payload["recomendacion"] ?? "Monitoreo preventivo"));
$latitud = isset($payload["latitud"]) && is_numeric($payload["latitud"]) ? (float) $payload["latitud"] : null;
$longitud = isset($payload["longitud"]) && is_numeric($payload["longitud"]) ? (float) $payload["longitud"] : null;
$fechaEvento = isset($payload["fecha_evento"]) ? trim((string) $payload["fecha_evento"]) : "";
$fechaSql = $fechaEvento !== "" ? date("Y-m-d H:i:s", strtotime($fechaEvento)) : null;

$eventosPermitidos = ["BOSTEZO", "MICROSUENO", "FATIGA_ALTA", "FATIGA_CRITICA", "MONITOREO"];
$nivelesPermitidos = ["BAJO", "MEDIO", "ALTO", "CRITICO"];

if (!in_array($evento, $eventosPermitidos, true)) {
    $evento = "MONITOREO";
}

if (!in_array($nivel, $nivelesPermitidos, true)) {
    $nivel = "BAJO";
}

$stmt = $conexion->prepare("
    INSERT INTO alertas (id_conductor, id_viaje, evento, nivel, fatiga, recomendacion, latitud, longitud, fecha)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo preparar el registro"]);
    exit;
}

$stmt->bind_param("iissisdds", $idConductor, $idViaje, $evento, $nivel, $fatiga, $recomendacion, $latitud, $longitud, $fechaSql);
$ok = $stmt->execute();
$idAlerta = $conexion->insert_id;
$stmt->close();

// Guardar muestra para ML si el cliente envió features (ojos/bostezos/tiempo)
if (isset($payload["ojos"]) || isset($payload["bostezos"]) || isset($payload["tiempo"])) {
    // Asegurar la tabla existe
    $conexion->query("CREATE TABLE IF NOT EXISTS ml_samples (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_alerta INT NULL,
        id_conductor INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ojos DOUBLE NULL,
        bostezos INT NULL,
        tiempo DOUBLE NULL,
        fatiga INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ojosVal = isset($payload["ojos"]) ? (float) $payload["ojos"] : null;
    $bostVal = isset($payload["bostezos"]) ? (int) $payload["bostezos"] : null;
    $tiempoVal = isset($payload["tiempo"]) ? (float) $payload["tiempo"] : null;
    $fatigaVal = $fatiga !== null ? (int) $fatiga : null;

    $ins = $conexion->prepare("INSERT INTO ml_samples (id_alerta, id_conductor, ojos, bostezos, tiempo, fatiga) VALUES (?, ?, ?, ?, ?, ?)");
    if ($ins) {
        // tipos: id_alerta (i), id_conductor (i), ojos (d), bostezos (i), tiempo (d), fatiga (i)
        $ins->bind_param("iididi", $idAlerta, $idConductor, $ojosVal, $bostVal, $tiempoVal, $fatigaVal);
        $ins->execute();
        $ins->close();
    }
}

echo json_encode([
    "ok" => $ok,
    "id_alerta" => $idAlerta,
    "fatiga" => $fatiga,
    "nivel" => $nivel,
    "evento" => $evento
]);
?>
