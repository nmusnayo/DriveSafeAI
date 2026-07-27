<?php
session_start();
include "db.php";

header("Content-Type: application/json; charset=utf-8");

$niveles = ["BAJO", "MEDIO", "ALTO", "CRITICO"];
$datosNivel = [];

foreach ($niveles as $nivel) {
    $datosNivel[] = (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE nivel = ?", "s", [$nivel]) ?? 0);
}

echo json_encode([
    "fatiga" => round((float) (db_value($conexion, "SELECT AVG(fatiga) FROM alertas") ?? 0), 1),
    "alertas" => (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas") ?? 0),
    "bostezos" => (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE evento = 'BOSTEZO'") ?? 0),
    "microsuenos" => (int) (db_value($conexion, "SELECT COUNT(*) FROM alertas WHERE evento = 'MICROSUENO'") ?? 0),
    "niveles" => $datosNivel
]);
?>
