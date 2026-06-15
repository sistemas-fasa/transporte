<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$tipo = $_GET['tipo'] ?? 'combustible';
$formato = $_GET['formato'] ?? 'pdf';
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$anio = $_GET['anio'] ?? date('Y');
$id_chofer = $_GET['id_chofer'] ?? '';
$id_camion = $_GET['id_camion'] ?? '';

function generarCSV($data, $headers, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
    fputcsv($output, $headers);

    foreach ($data as $row) {
        $vals = [];
        foreach ($headers as $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $key = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $key);
            $vals[] = $row[$key] ?? $row[str_replace(' ', '_', $h)] ?? '';
        }
        fputcsv($output, $vals);
    }
    fclose($output);
    exit;
}

function generarHTML($data, $headers, $title) {
    global $desde, $hasta;
    $html = '<html><head><meta charset="utf-8"><style>body{font-family:sans-serif;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ccc;padding:8px;text-align:left;}th{background:#091426;color:white;}</style></head><body>';
    $html .= '<h1>' . $title . '</h1><p>Periodo: ' . $desde . ' a ' . $hasta . '</p>';
    $html .= '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($headers as $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $html .= '<td>' . htmlspecialchars($row[$key] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></body></html>';
    return $html;
}

switch ($tipo) {
    case 'combustible':
        $sql = "SELECT DATE(co.fecha) as fecha, CONCAT(ch.apellido,', ',ch.nombre) as chofer, c.patente as camion, co.estacion_servicio, co.litros, co.precio_litro, co.importe_total FROM combustible co JOIN choferes ch ON co.id_chofer = ch.id_chofer JOIN camiones c ON co.id_camion = c.id_camion WHERE DATE(co.fecha) BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_chofer) { $sql .= " AND co.id_chofer = ?"; $params[] = $id_chofer; }
        if ($id_camion) { $sql .= " AND co.id_camion = ?"; $params[] = $id_camion; }
        $sql .= " ORDER BY co.fecha";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $headers = ['Fecha', 'Chofer', 'Camion', 'Estacion', 'Litros', 'Precio Litro', 'Importe Total'];
        $title = 'Reporte de Combustible';
        break;

    case 'mantenimiento':
        $sql = "SELECT m.fecha, c.patente as camion, m.tipo, m.descripcion, m.proveedor, m.costo, m.kilometraje FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE m.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_camion) { $sql .= " AND m.id_camion = ?"; $params[] = $id_camion; }
        $sql .= " ORDER BY m.fecha";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $headers = ['Fecha', 'Camion', 'Tipo', 'Descripcion', 'Proveedor', 'Costo', 'Kilometraje'];
        $title = 'Reporte de Mantenimiento';
        break;

    default:
        $data = [];
        $headers = ['Info'];
        $title = 'Reporte';
}

if ($formato === 'excel') {
    generarCSV($data, $headers, "reporte_$tipo");
} else {
    // PDF: try dompdf if installed, otherwise output HTML for browser printing
    $dompdfPath = __DIR__ . '/../vendor/dompdf/dompdf/src/autoload.inc.php';
    if (file_exists($dompdfPath)) {
        require_once $dompdfPath;
        $dompdf = new Dompdf\Dompdf();
        $html = generarHTML($data, $headers, $title);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream("reporte_$tipo.pdf", ['Attachment' => true]);
    } else {
        // Fallback: output HTML for browser printing
        $html = generarHTML($data, $headers, $title);
        $html = str_replace('</body>', '<script>window.onload = function() { window.print(); }</script></body>', $html);
        echo $html;
    }
}
