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
$id_empresa = $_GET['id_empresa'] ?? '';
$vista = $_GET['vista'] ?? 'todos';
$buscar = $_GET['buscar'] ?? '';
$orden = strtoupper($_GET['orden'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

function isNumericHeader($h) {
    $numeric = ['KM Salida', 'KM Llegada', 'KM Recorridos', 'HS Salida', 'HS Llegada', 'HS Recorridas', 'Litros', 'Precio Litro', 'Importe Total', 'Costo', 'Kilometraje'];
    return in_array($h, $numeric);
}

function generarHTML($data, $headers, $title, $totales = null) {
    global $desde, $hasta;
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        @page { size: landscape; margin: 7mm 6mm; }
        body { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; font-size: 8pt; color: #1a1a1a; margin: 0; padding: 0; }
        .report-header { text-align: center; margin-bottom: 10px; border-bottom: 3px solid #091426; padding-bottom: 6px; }
        .report-header h1 { font-size: 14pt; margin: 0; color: #091426; letter-spacing: 0.5px; }
        .report-header .sub { font-size: 7.5pt; color: #666; margin: 2px 0 0; }
        table { width: 100%; border-collapse: collapse; font-size: 7pt; }
        thead th { background: #091426; color: #fff; padding: 4px 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; font-size: 6pt; border: 1px solid #0a1e3d; }
        tbody td { padding: 2px 4px; border: 1px solid #d0d5dd; }
        tbody tr:nth-child(even) td { background: #f4f6f9; }
        tfoot td { padding: 3px 4px; border: 1px solid #0a1e3d; font-weight: bold; background: #e8ecf1; }
        .right { text-align: right; }
        .center { text-align: center; }
        .report-footer { margin-top: 8px; font-size: 6pt; color: #999; text-align: center; border-top: 1px solid #d0d5dd; padding-top: 4px; }
    </style></head><body>';
    $html .= '<div class="report-header"><h1>' . htmlspecialchars($title) . '</h1><div class="sub">Periodo: ' . $desde . ' a ' . $hasta . '</div></div>';
    $html .= '<table><thead><tr>';
    foreach ($headers as $h) {
        $cls = isNumericHeader($h) ? ' class="right"' : '';
        $html .= '<th' . $cls . '>' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($headers as $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $cls = isNumericHeader($h) ? ' class="right"' : '';
            $html .= '<td' . $cls . '>' . htmlspecialchars($row[$key] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>';
    if ($totales) {
        $html .= '<tfoot><tr>';
        foreach ($headers as $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $cls = isNumericHeader($h) ? ' class="right"' : '';
            if ($key === 'fecha') {
                $html .= '<td class="right" colspan="1"><strong>TOTALES</strong></td>';
            } elseif (isset($totales[$key])) {
                $html .= '<td' . $cls . '><strong>' . htmlspecialchars($totales[$key]) . '</strong></td>';
            } else {
                $html .= '<td' . $cls . '></td>';
            }
        }
        $html .= '</tr></tfoot>';
    }
    $html .= '</table>';
    $html .= '<div class="report-footer">Sistema de Control de Combustible y Kilometraje</div>';
    $html .= '</body></html>';
    return $html;
}

function generarExcel($data, $headers, $title, $totales = null) {
    global $desde, $hasta;

    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', $title) . '.xls"');
        echo '<html><body><table>';
        echo '<tr>' . implode('', array_map(function($h) { return '<th>' . htmlspecialchars($h) . '</th>'; }, $headers)) . '</tr>';
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($headers as $h) {
                $key = strtolower(str_replace(' ', '_', $h));
                echo '<td>' . htmlspecialchars($row[$key] ?? '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', $title) . '.xlsx"');
    header('Cache-Control: max-age=0');

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);

    $sharedStrings = [];
    $ssIndex = 0;
    function addString(&$ss, &$idx, $v) {
        $k = array_search((string)$v, $ss, true);
        if ($k === false) { $ss[] = (string)$v; $k = $idx++; }
        return $k;
    }

    function colLetter($i) {
        $l = '';
        while ($i >= 0) { $l = chr(65 + ($i % 26)) . $l; $i = intdiv($i, 26) - 1; }
        return $l;
    }
    // Header row + data rows
    $rowsXml = '';
    $colLetters = [];
    for ($c = 0; $c < count($headers); $c++) {
        $colLetters[$c] = colLetter($c);
        $rowsXml .= '<c r="' . $colLetters[$c] . '1" t="s" s="1"><v>' . addString($sharedStrings, $ssIndex, $headers[$c]) . '</v></c>';
    }
    $r = 2;
    foreach ($data as $row) {
        $rowsXml .= '<row r="' . $r . '">';
        foreach ($headers as $ci => $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $val = $row[$key] ?? '';
            $isNum = is_numeric($val) && $val !== '';
            if ($isNum) {
                $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" s="2"><v>' . $val . '</v></c>';
            } else {
                $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" t="s" s="0"><v>' . addString($sharedStrings, $ssIndex, $val) . '</v></c>';
            }
        }
        $rowsXml .= '</row>';
        $r++;
    }
    if ($totales) {
        $rowsXml .= '<row r="' . $r . '">';
        foreach ($headers as $ci => $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            if ($key === 'fecha') {
                $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" t="s" s="3"><v>' . addString($sharedStrings, $ssIndex, 'TOTALES') . '</v></c>';
            } elseif (isset($totales[$key])) {
                $val = $totales[$key];
                $isNum = is_numeric($val) && $val !== '';
                if ($isNum) {
                    $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" s="3"><v>' . $val . '</v></c>';
                } else {
                    $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" t="s" s="3"><v>' . addString($sharedStrings, $ssIndex, $val) . '</v></c>';
                }
            } else {
                $rowsXml .= '<c r="' . $colLetters[$ci] . $r . '" s="3"/>';
            }
        }
        $rowsXml .= '</row>';
    }

    // Shared strings XML
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
    foreach ($sharedStrings as $s) {
        $ssXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $ssXml .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $ssXml);

    // Worksheet
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
<cols>';
    for ($c = 0; $c < count($headers); $c++) {
        $sheetXml .= '<col min="' . ($c+1) . '" max="' . ($c+1) . '" width="18" customWidth="1"/>';
    }
    $sheetXml .= '</cols><sheetData>' . $rowsXml . '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    // Styles
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="3">
<font><sz val="10"/><name val="Segoe UI"/></font>
<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>
<font><b/><sz val="10"/><name val="Segoe UI"/></font>
</fonts>
<fills count="3">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF091426"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="4">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>
<xf numFmtId="2" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>
<xf numFmtId="0" fontId="2" fillId="0" borderId="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>
</cellXfs>
</styleSheet>';
    $zip->addFromString('xl/styles.xml', $stylesXml);

    // Workbook
    $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $wbXml);

    // Workbook rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // [Content_Types].xml
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    // _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    $zip->close();
    readfile($tmp);
    unlink($tmp);
    exit;
}

$totales = null;

switch ($tipo) {
    case 'combustible':
        $sql = "SELECT DATE(co.fecha) as fecha, CONCAT(ch.apellido,', ',ch.nombre) as chofer, c.patente as camion, co.estacion_servicio, co.litros, co.precio_litro, (co.litros * co.precio_litro) as importe_total FROM combustible co JOIN choferes ch ON co.id_chofer = ch.id_chofer JOIN camiones c ON co.id_camion = c.id_camion WHERE DATE(co.fecha) BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_chofer) { $sql .= " AND co.id_chofer = ?"; $params[] = $id_chofer; }
        if ($id_camion) { $sql .= " AND co.id_camion = ?"; $params[] = $id_camion; }
        if ($id_empresa) { $sql .= " AND ch.empresa_id = ?"; $params[] = $id_empresa; }
        $sql .= " ORDER BY co.fecha";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $headers = ['Fecha', 'Chofer', 'Camion', 'Estacion', 'Litros', 'Precio Litro', 'Importe Total'];
        $title = 'Reporte de Combustible';
        $totales = [];
        $tLitros = 0; $tImporte = 0;
        foreach ($data as $r) { $tLitros += (float)($r['litros'] ?? 0); $tImporte += (float)($r['importe_total'] ?? 0); }
        $totales['litros'] = number_format($tLitros, 2);
        $totales['importe_total'] = number_format($tImporte, 2);
        break;

    case 'mantenimiento':
        $sql = "SELECT m.fecha, c.patente as camion, m.tipo, m.descripcion, m.proveedor, m.costo, m.kilometraje FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE m.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_camion) { $sql .= " AND m.id_camion = ?"; $params[] = $id_camion; }
        if ($id_empresa) { $sql .= " AND c.empresa_id = ?"; $params[] = $id_empresa; }
        $sql .= " ORDER BY m.fecha";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $headers = ['Fecha', 'Camion', 'Tipo', 'Descripcion', 'Proveedor', 'Costo', 'Kilometraje'];
        $title = 'Reporte de Mantenimiento';
        $totales = [];
        $tCosto = 0;
        foreach ($data as $r) { $tCosto += (float)($r['costo'] ?? 0); }
        $totales['costo'] = number_format($tCosto, 2);
        break;

    case 'viajes':
        if ($vista === 'por_chofer' && (int)$id_chofer > 0) {
            $sql = "SELECT h.fecha as fecha, CONCAT(ch.apellido,', ',ch.nombre) as chofer, c.patente as patente, h.origen as origen, h.destino as destino, h.km_salida, h.km_llegada, h.km_recorridos, h.hs_salida, h.hs_llegada, h.hs_recorridas, c.por_hora, h.estado as estado, h.nro_hoja_ruta as nro_hoja_ruta, CASE WHEN h.id_chofer = ? THEN 'Chofer' ELSE 'Ayudante' END as rol FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion JOIN choferes ch ON h.id_chofer = ch.id_chofer WHERE (h.id_chofer = ? OR h.ayudante_id = ?) AND h.fecha >= ? AND h.fecha <= ?";
            $params = [(int)$id_chofer, (int)$id_chofer, (int)$id_chofer, $desde, $hasta];
            if ($id_empresa) {
                $sql .= " AND ch.id_empresa = ?";
                $params[] = $id_empresa;
            }
            $sql .= " ORDER BY h.fecha $orden, h.id_hoja $orden";
        } else {
            $sql = "SELECT h.fecha as fecha, CONCAT(ch.apellido,', ',ch.nombre) as chofer, c.patente as patente, h.origen as origen, h.destino as destino, h.km_salida, h.km_llegada, h.km_recorridos, h.hs_salida, h.hs_llegada, h.hs_recorridas, c.por_hora, h.estado as estado, h.nro_hoja_ruta as nro_hoja_ruta, 'Chofer' as rol FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion JOIN choferes ch ON h.id_chofer = ch.id_chofer WHERE 1=1";
            $params = [];
            if ($buscar) {
                $sql .= " AND (c.patente LIKE ? OR ch.nombre LIKE ? OR ch.apellido LIKE ? OR h.origen LIKE ? OR h.destino LIKE ?)";
                $params = array_merge($params, ["%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%"]);
            }
            $sql .= " AND h.fecha >= ? AND h.fecha <= ?";
            $params[] = $desde; $params[] = $hasta;
            $sql .= " ORDER BY h.fecha $orden, h.id_hoja $orden";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $total_km = 0; $total_hs = 0; $has_km = false; $has_hs = false;
        foreach ($data as $r) {
            if ($r['por_hora']) {
                $total_hs += (float)($r['hs_recorridas'] ?? 0);
                $has_hs = true;
            } else {
                $total_km += (float)($r['km_recorridos'] ?? 0);
                $has_km = true;
            }
        }
        $totales = [];
        if ($has_km && $has_hs) {
            $totales['km_recorridos'] = number_format($total_km, 0) . ' km / ' . number_format($total_hs, 1) . ' hs';
        } elseif ($has_hs) {
            $totales['km_recorridos'] = number_format($total_hs, 1) . ' hs';
        } elseif ($has_km) {
            $totales['km_recorridos'] = number_format($total_km, 0) . ' km';
        }
        foreach ($data as &$row) {
            if ($row['por_hora']) {
                $row['km_salida'] = $row['hs_salida'] !== null ? number_format($row['hs_salida'], 1) : '-';
                $row['km_llegada'] = $row['hs_llegada'] !== null ? number_format($row['hs_llegada'], 1) : '-';
                $row['km_recorridos'] = $row['hs_recorridas'] !== null ? number_format($row['hs_recorridas'], 1) . ' hs' : '-';
                $row['hs_salida'] = $row['hs_salida'] !== null ? number_format($row['hs_salida'], 1) : '-';
                $row['hs_llegada'] = $row['hs_llegada'] !== null ? number_format($row['hs_llegada'], 1) : '-';
                $row['hs_recorridas'] = $row['hs_recorridas'] !== null ? number_format($row['hs_recorridas'], 1) . ' hs' : '-';
            } else {
                $row['km_salida'] = number_format($row['km_salida'], 0);
                $row['km_llegada'] = $row['km_llegada'] !== null ? number_format($row['km_llegada'], 0) : '-';
                $row['km_recorridos'] = $row['km_recorridos'] !== null ? number_format($row['km_recorridos'], 0) : '-';
                $row['hs_salida'] = $row['hs_salida'] !== null ? number_format($row['hs_salida'], 1) : '-';
                $row['hs_llegada'] = $row['hs_llegada'] !== null ? number_format($row['hs_llegada'], 1) : '-';
                $row['hs_recorridas'] = $row['hs_recorridas'] !== null ? number_format($row['hs_recorridas'], 1) . ' hs' : '-';
            }
        }
        unset($row);
        $headers = ['Fecha', 'Chofer', 'Patente', 'Origen', 'Destino', 'KM Salida', 'KM Llegada', 'KM Recorridos', 'HS Salida', 'HS Llegada', 'HS Recorridas', 'Estado', 'Nro Hoja Ruta', 'Rol'];
        $title = 'Reporte de Viajes';
        break;

    default:
        $data = [];
        $headers = ['Info'];
        $title = 'Reporte';
}

if ($formato === 'excel') {
    generarExcel($data, $headers, $title, $totales);
} else {
    // PDF: try dompdf if installed, otherwise output HTML for browser printing
    $dompdfPath = __DIR__ . '/../vendor/dompdf/dompdf/src/autoload.inc.php';
    if (file_exists($dompdfPath)) {
        require_once $dompdfPath;
        $dompdf = new Dompdf\Dompdf();
        $html = generarHTML($data, $headers, $title, $totales);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream("reporte_$tipo.pdf", ['Attachment' => true]);
    } else {
        // Fallback: output HTML for browser printing
        $html = generarHTML($data, $headers, $title, $totales);
        $html = str_replace('</body>', '<script>window.onload = function() { window.print(); }</script></body>', $html);
        echo $html;
    }
}
