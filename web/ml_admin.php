<?php
require_once __DIR__ . '/db.php';
require_roles(["ADMIN", "SUPERVISOR"]);
$user = current_user();

$message = null;
$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'train') {
    // exporta dataset a CSV y corre train_model.py
    $outFile = __DIR__ . '/../dataset.csv';

    $rows = [];
    $res = $conexion->query("SELECT ojos, bostezos, tiempo, fatiga FROM ml_samples WHERE ojos IS NOT NULL AND bostezos IS NOT NULL AND tiempo IS NOT NULL AND fatiga IS NOT NULL");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->close();
    }

    if (count($rows) === 0) {
        $error = "No hay muestras ML completas para exportar. Recolecta más eventos con features (ojos, bostezos, tiempo).";
    } else {
        // escribe un CSV con las muestras
        $fp = fopen($outFile, 'w');
        if ($fp === false) {
            $error = "No se pudo crear el archivo dataset.csv en la raíz del proyecto (permisos).";
        } else {
            fputcsv($fp, ['ojos','bostezos','tiempo','fatiga']);
            foreach ($rows as $r) {
                fputcsv($fp, [$r['ojos'], $r['bostezos'], $r['tiempo'], $r['fatiga']]);
            }
            fclose($fp);

            // existe modelo previo? hacer backup
            $modelPath = __DIR__ . '/../modelo_fatiga.pkl';
            if (file_exists($modelPath)) {
                $bak = __DIR__ . '/../modelo_fatiga_' . date('Ymd_His') . '.pkl';
                copy($modelPath, $bak);
            }

            // corre train_model.py
            $cmd = 'cd ' . escapeshellarg(__DIR__ . '/..') . ' && python ' . escapeshellarg('train_model.py') . ' 2>&1';
            $out = shell_exec($cmd);

            // modelo entrenado, guardar versión
            $verFile = __DIR__ . '/../model_version.txt';
            $verContent = "trained_at=" . date('c') . "\noutput=\n" . ($out ?? '') . "\n";
            file_put_contents($verFile, $verContent);

            $message = "Entrenamiento ejecutado. Salida:\n" . ($out ?? '(sin salida)');
        }
    }
}

// Estados de dataset y modelo
$totalSamples = (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples", "", []);
$totalWithFeatures = (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples WHERE ojos IS NOT NULL AND bostezos IS NOT NULL AND tiempo IS NOT NULL AND fatiga IS NOT NULL", "", []);

// Métricas adicionales del dataset
$avgFatiga = db_value($conexion, "SELECT AVG(fatiga) FROM ml_samples WHERE fatiga IS NOT NULL", "", []);
$maxFatiga = db_value($conexion, "SELECT MAX(fatiga) FROM ml_samples WHERE fatiga IS NOT NULL", "", []);
$minFatiga = db_value($conexion, "SELECT MIN(fatiga) FROM ml_samples WHERE fatiga IS NOT NULL", "", []);
$avgOjos = db_value($conexion, "SELECT AVG(ojos) FROM ml_samples WHERE ojos IS NOT NULL", "", []);
$avgBostezos = db_value($conexion, "SELECT AVG(bostezos) FROM ml_samples WHERE bostezos IS NOT NULL", "", []);
$avgTiempo = db_value($conexion, "SELECT AVG(tiempo) FROM ml_samples WHERE tiempo IS NOT NULL", "", []);

// Distribución de niveles de fatiga
$distribucion = [
    'BAJO' => (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples WHERE fatiga < 24", "", []),
    'MEDIO' => (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples WHERE fatiga >= 24 AND fatiga < 48", "", []),
    'ALTO' => (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples WHERE fatiga >= 48 AND fatiga < 72", "", []),
    'CRITICO' => (int) db_value($conexion, "SELECT COUNT(*) FROM ml_samples WHERE fatiga >= 72", "", [])
];

$modelVersion = file_exists(__DIR__ . '/../model_version.txt') ? file_get_contents(__DIR__ . '/../model_version.txt') : null;
$modelExists = file_exists(__DIR__ . '/../modelo_fatiga.pkl');

include 'header.php';
?>

<main class="content">
    <header class="topbar">
        <div>
            <p class="eyebrow">Machine Learning</p>
            <h1>Administración ML</h1>
        </div>
    </header>

    <section class="panel">
        <div class="panel-heading">
            <h2>Estado de muestras</h2>
        </div>
        <div class="panel-body">
            <p>Total muestras guardadas: <strong><?= h((string) $totalSamples) ?></strong></p>
            <p>Total muestras completas (ojos,bostezos,tiempo,fatiga): <strong><?= h((string) $totalWithFeatures) ?></strong></p>
            <p>Estado del modelo: <strong><?= $modelExists ? '✓ Modelo existe' : '✗ Modelo no encontrado' ?></strong></p>

            <?php if ($totalWithFeatures > 0): ?>
                <h3 style="margin-top:20px;">Métricas del Dataset</h3>
                <table style="width:100%;border-collapse:collapse;margin-top:10px;">
                    <tr style="background:#f5f5f5;">
                        <th style="padding:8px;text-align:left;border:1px solid #ddd;">Métrica</th>
                        <th style="padding:8px;text-align:left;border:1px solid #ddd;">Valor</th>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Fatiga Promedio</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$avgFatiga, 2) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Fatiga Máxima</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$maxFatiga, 2) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Fatiga Mínima</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$minFatiga, 2) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Apertura Ojos Promedio</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$avgOjos, 4) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Bostezos Promedio</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$avgBostezos, 2) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #ddd;">Tiempo Conducción Promedio (min)</td>
                        <td style="padding:8px;border:1px solid #ddd;"><?= number_format((float)$avgTiempo, 2) ?></td>
                    </tr>
                </table>

                <h3 style="margin-top:20px;">Distribución de Niveles de Fatiga</h3>
                <table style="width:100%;border-collapse:collapse;margin-top:10px;">
                    <tr style="background:#f5f5f5;">
                        <th style="padding:8px;text-align:left;border:1px solid #ddd;">Nivel</th>
                        <th style="padding:8px;text-align:left;border:1px solid #ddd;">Cantidad</th>
                        <th style="padding:8px;text-align:left;border:1px solid #ddd;">Porcentaje</th>
                    </tr>
                    <?php foreach ($distribucion as $nivel => $cantidad): ?>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;"><?= h($nivel) ?></td>
                            <td style="padding:8px;border:1px solid #ddd;"><?= $cantidad ?></td>
                            <td style="padding:8px;border:1px solid #ddd;"><?= $totalWithFeatures > 0 ? number_format(($cantidad / $totalWithFeatures) * 100, 1) : 0 ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ($modelVersion): ?>
                <h3 style="margin-top:20px;">Versión del Modelo</h3>
                <pre style="white-space:pre-wrap;border:1px solid #eee;padding:12px;background:#fafafa;"><?= h($modelVersion) ?></pre>
            <?php else: ?>
                <p>No se ha registrado una versión de modelo todavía.</p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert success" style="white-space:pre-wrap;"><?= h($message) ?></div>
            <?php endif; ?>

            <form method="POST" style="margin-top:20px;">
                <input type="hidden" name="action" value="train">
                <button class="primary-button" type="submit" <?= $totalWithFeatures < 10 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                    Exportar dataset y entrenar modelo
                </button>
                <?php if ($totalWithFeatures < 10): ?>
                    <p style="color:#666;font-size:0.9em;margin-top:5px;">Se requieren al menos 10 muestras completas para entrenar</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

</main>

<?php include 'footer.php'; ?>
