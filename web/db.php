<?php
declare(strict_types=1);

$conexion = new mysqli("localhost", "root", "", "drivesafe_ai");

if ($conexion->connect_error) {
    http_response_code(500);
    die("Error DB: " . $conexion->connect_error);
}

$conexion->set_charset("utf8mb4");

function column_exists(mysqli $conexion, string $table, string $column): bool
{
    $exists = db_value(
        $conexion,
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        "ss",
        [$table, $column]
    );

    return (int) ($exists ?? 0) > 0;
}

function ensure_account_schema(mysqli $conexion): void
{
    $conexion->query("
        CREATE TABLE IF NOT EXISTS organizaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(160) NOT NULL,
            tipo ENUM('PARTICULAR', 'EMPRESA') NOT NULL DEFAULT 'PARTICULAR',
            estado ENUM('ACTIVA', 'INACTIVA') NOT NULL DEFAULT 'ACTIVA',
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    if (!column_exists($conexion, "usuarios", "id_organizacion")) {
        $conexion->query("ALTER TABLE usuarios ADD COLUMN id_organizacion INT NULL AFTER id");
    }

    if (!column_exists($conexion, "vehiculos", "id_organizacion")) {
        $conexion->query("ALTER TABLE vehiculos ADD COLUMN id_organizacion INT NULL AFTER id");
    }

    if (!column_exists($conexion, "viajes", "id_organizacion")) {
        $conexion->query("ALTER TABLE viajes ADD COLUMN id_organizacion INT NULL AFTER id");
    }
}

function db_value(mysqli $conexion, string $sql, string $types = "", array $params = [])
{
    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        die("Error prepare: " . $conexion->error);
    }

    if ($types !== "" && $params) {

        

        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_row() : null;

    $stmt->close();

    return $row ? $row[0] : null;
}

ensure_account_schema($conexion);

function require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION["user"])) {
        header("Location: login.php");
        exit;
    }
}

function current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return $_SESSION["user"] ?? null;
}

function is_admin_role(?array $user = null): bool
{
    $user = $user ?? current_user();
    return in_array(($user["rol"] ?? ""), ["ADMIN", "SUPERVISOR"], true);
}

function current_org_id(?array $user = null): ?int
{
    $user = $user ?? current_user();
    $id = $user["id_organizacion"] ?? null;
    return $id !== null && $id !== "" ? (int) $id : null;
}

function role_dashboard_url(?array $user = null): string
{
    return is_admin_role($user) ? "admin_dashboard.php" : "conductor_dashboard.php";
}

function redirect_to_role_dashboard(?array $user = null): void
{
    header("Location: " . role_dashboard_url($user));
    exit;
}

function require_roles(array $roles): void
{
    require_login();
    $user = current_user();

    if (!in_array(($user["rol"] ?? ""), $roles, true)) {
        http_response_code(403);
        die("No autorizado");
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>