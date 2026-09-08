<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!puedeAprobarChecklist() && !isAdmin()) {
    die("Acceso restringido. La impresión de informes oficiales está reservada para Inspectores y Administradores.");
}
requirePermission('checklist_ver');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    die("ID de inspección no especificado.");
}

$stmt = $db->prepare("SELECT i.*, 
    c.patente, c.marca, c.modelo, c.tipo as tipo_vehiculo, c.horas_actuales, c.kilometraje_actual, c.anio,
    CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
    u.username as usuario_nombre
    FROM checklist_inspecciones i
    LEFT JOIN camiones c ON i.id_camion = c.id_camion
    LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
    LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
    WHERE i.id_inspeccion = ?");
$stmt->execute([$id]);
$inspeccion = $stmt->fetch();

if (!$inspeccion) {
    die("Inspección no encontrada.");
}

$stmtResp = $db->prepare("SELECT * FROM checklist_respuestas WHERE id_inspeccion = ? ORDER BY id_respuesta ASC");
$stmtResp->execute([$id]);
$respuestas = $stmtResp->fetchAll();

// Agrupar respuestas por categoría
$respuestasPorCat = [];
foreach ($respuestas as $r) {
    $respuestasPorCat[$r['categoria']][] = $r;
}

$nombreOperador = htmlspecialchars($inspeccion['firmado_por'] ?: ($inspeccion['chofer_nombre'] ?: $inspeccion['usuario_nombre']));
$nombreInspector = htmlspecialchars($inspeccion['inspector_nombre'] ?: 'Inspector Responsable');
$esAprobado = ($inspeccion['estado_aprobacion'] ?? '') === 'aprobado' || ($inspeccion['estado'] === 'aprobado' && empty($inspeccion['estado_aprobacion']));
$esRechazado = ($inspeccion['estado_aprobacion'] ?? '') === 'rechazado' || $inspeccion['estado'] === 'rechazado';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>INFORME-CHK-#<?= str_pad($inspeccion['id_inspeccion'], 4, '0', STR_PAD_LEFT) ?> - <?= htmlspecialchars($inspeccion['patente']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: #f1f5f9; 
            color: #0f172a; 
            font-size: 12px;
        }
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 12mm 10mm;
        }
        @media print {
            body { 
                background: white !important; 
                color: black !important;
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
                padding: 0 !important;
            }
            .no-print { display: none !important; }
            .page-container { 
                box-shadow: none !important; 
                border: none !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
            }
            .page-break-avoid { 
                page-break-inside: avoid !important; 
                break-inside: avoid !important;
            }
            tr { 
                page-break-inside: avoid !important; 
                break-inside: avoid !important;
            }
        }
        .report-table th, .report-table td {
            border: 1px solid #cbd5e1;
        }
    </style>
</head>
<body class="p-3 sm:p-6 md:p-8">

    <!-- Barra Superior de Herramientas (No Imprimible) -->
    <div class="max-w-[850px] mx-auto mb-5 no-print flex items-center justify-between bg-slate-900 text-white px-5 py-3.5 rounded-2xl shadow-xl">
        <div class="flex items-center gap-3">
            <span class="w-8 h-8 rounded-lg bg-emerald-500 text-white flex items-center justify-center font-bold text-sm">
                ✓
            </span>
            <div>
                <p class="font-bold text-sm leading-tight">Informe de Inspección Técnica #<?= $inspeccion['id_inspeccion'] ?></p>
                <p class="text-[11px] text-slate-400"><?= htmlspecialchars($inspeccion['patente']) ?> - <?= htmlspecialchars($inspeccion['marca'] . ' ' . $inspeccion['modelo']) ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2.5">
            <button onclick="window.print()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl shadow transition-all flex items-center gap-1.5 cursor-pointer">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                <span>Imprimir / Guardar PDF</span>
            </button>
            <button onclick="window.close()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white text-xs font-semibold rounded-xl transition-all">
                Cerrar
            </button>
        </div>
    </div>

    <!-- DOCUMENTO OFICIAL A4 -->
    <div class="page-container max-w-[850px] mx-auto bg-white p-7 sm:p-9 rounded-2xl border border-slate-300 shadow-md">
        
        <!-- ENCABEZADO INSTITUCIONAL / TITULAR OFICIAL -->
        <div class="border-b-2 border-slate-900 pb-4 mb-5">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo Empresa" class="h-16 w-auto max-w-[140px] object-contain shrink-0" onerror="this.style.display='none'">
                    <div>
                        <span class="inline-block px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-mono font-bold text-[10px] tracking-wider uppercase border border-slate-300 mb-1">
                            SISTEMA INTEGRAL DE CONTROL DE FLOTA Y MAQUINARIAS
                        </span>
                        <h1 class="text-sm sm:text-[15px] font-black uppercase text-slate-950 tracking-tight leading-snug">
                            CHECK LIST DIARIO PARA USO DE AUTOELEVADORES, MONTACARGAS SEGÚN RESOLUCIÓN DE LA SRT. Nº 960/15
                        </h1>
                        <p class="text-[11px] text-slate-600 font-semibold mt-0.5">
                            INFORME TÉCNICO DE INSPECCIÓN DIARIA Y CONDICIONES DE SEGURIDAD OPERATIVA
                        </p>
                    </div>
                </div>

                <div class="shrink-0 text-right">
                    <div class="inline-block bg-slate-900 text-white px-3 py-1.5 rounded-lg text-xs font-mono font-extrabold tracking-wider">
                        INF-CHK-<?= str_pad($inspeccion['id_inspeccion'], 5, '0', STR_PAD_LEFT) ?>
                    </div>
                    <p class="text-[11px] text-slate-500 font-semibold mt-1">
                        Fecha: <?= date('d/m/Y', strtotime($inspeccion['fecha'])) ?>
                    </p>
                    <p class="text-[10px] text-slate-400 font-mono">
                        Hora: <?= date('H:i', strtotime($inspeccion['fecha'])) ?> hs
                    </p>
                </div>
            </div>
        </div>

        <!-- CUADRO DE DATOS TÉCNICOS DE LA UNIDAD Y LA INSPECCIÓN -->
        <div class="border border-slate-300 rounded-xl overflow-hidden mb-5">
            <div class="bg-slate-800 text-white px-3.5 py-1.5 font-bold text-[11px] uppercase tracking-wider flex items-center justify-between">
                <span>1. Información General del Equipo y Responsables</span>
                <span class="text-[10px] text-slate-300 font-normal">Resolución SRT 960/15</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-slate-200 bg-slate-50/50 text-[11px]">
                <div class="p-2.5">
                    <span class="block text-[9px] font-bold uppercase text-slate-400">Equipo / Patente:</span>
                    <span class="font-black text-sm text-slate-900"><?= htmlspecialchars($inspeccion['patente']) ?></span>
                    <span class="block text-slate-600 text-[10px]"><?= htmlspecialchars($inspeccion['marca'] . ' ' . $inspeccion['modelo']) ?></span>
                </div>

                <div class="p-2.5">
                    <span class="block text-[9px] font-bold uppercase text-slate-400">Tipo de Unidad:</span>
                    <span class="font-bold text-slate-900 uppercase"><?= htmlspecialchars($inspeccion['tipo_vehiculo'] ?: 'Autoelevador') ?></span>
                    <span class="block text-slate-500 text-[10px]"><?= $inspeccion['anio'] ? 'Año: ' . $inspeccion['anio'] : 'Maquinaria de planta' ?></span>
                </div>

                <div class="p-2.5">
                    <span class="block text-[9px] font-bold uppercase text-slate-400">Horómetro Registrado:</span>
                    <span class="font-black text-slate-900 text-sm">
                        <?= $inspeccion['horas_maquina'] ? number_format($inspeccion['horas_maquina'], 1) . ' hs' : ($inspeccion['kilometraje_actual'] ? number_format($inspeccion['kilometraje_actual'], 0) . ' km' : 'N/D') ?>
                    </span>
                    <span class="block text-slate-500 text-[10px]">Lectura al momento</span>
                </div>

                <div class="p-2.5 bg-slate-100/70">
                    <span class="block text-[9px] font-bold uppercase text-slate-400">Dictamen Final:</span>
                    <?php if ($esRechazado): ?>
                    <span class="inline-block font-extrabold text-[11px] text-red-700 bg-red-100 border border-red-300 px-2 py-0.5 rounded">
                        ❌ RECHAZADO / FUERA DE SERV.
                    </span>
                    <?php elseif ($inspeccion['total_fallas'] > 0 || $inspeccion['estado'] === 'con_observaciones'): ?>
                    <span class="inline-block font-extrabold text-[11px] text-amber-800 bg-amber-100 border border-amber-300 px-2 py-0.5 rounded">
                        ⚠ CON OBSERVACIONES (<?= $inspeccion['total_fallas'] ?>)
                    </span>
                    <?php else: ?>
                    <span class="inline-block font-extrabold text-[11px] text-emerald-800 bg-emerald-100 border border-emerald-300 px-2 py-0.5 rounded">
                        ✓ 100% CONFORME (OK)
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Fila de Responsables -->
            <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 border-t border-slate-200 p-2.5 bg-white text-[11px]">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-bold uppercase text-slate-400 w-28 shrink-0">Operador / Chofer:</span>
                    <span class="font-bold text-slate-900"><?= $nombreOperador ?></span>
                </div>
                <div class="flex items-center gap-2 pt-2 sm:pt-0 sm:pl-3">
                    <span class="text-[10px] font-bold uppercase text-slate-400 w-36 shrink-0">Inspector / Responsable:</span>
                    <span class="font-bold text-slate-900"><?= $nombreInspector ?></span>
                    <?php if (!empty($inspeccion['fecha_aprobacion'])): ?>
                    <span class="text-[10px] text-emerald-700 font-semibold">(<?= date('d/m/Y H:i', strtotime($inspeccion['fecha_aprobacion'])) ?> hs)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TABLA PRINCIPAL DE ÍTEMS CONTROLADOS (CHECKLIST) -->
        <div class="mb-5">
            <div class="bg-slate-800 text-white px-3.5 py-1.5 rounded-t-xl font-bold text-[11px] uppercase tracking-wider flex items-center justify-between">
                <span>2. Registro de Verificaciones y Puntos de Control (Checklist)</span>
                <span class="text-[10px] text-slate-300 font-normal">Total: <?= count($respuestas) ?> Ítems</span>
            </div>

            <table class="w-full report-table text-left border-collapse text-[11px]">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 text-[10px] uppercase font-extrabold">
                        <th class="py-1.5 px-2 text-center w-8">N°</th>
                        <th class="py-1.5 px-2.5 w-36">Categoría / Sistema</th>
                        <th class="py-1.5 px-2.5">Punto de Inspección / Requisito</th>
                        <th class="py-1.5 px-2 text-center w-24">Resultado</th>
                        <th class="py-1.5 px-2.5 w-48">Observaciones / Evidencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php 
                    $numGlobal = 0;
                    foreach ($respuestasPorCat as $catNombre => $items): 
                    ?>
                        <!-- Fila Separadora de Categoría -->
                        <tr class="bg-slate-50 font-bold text-slate-800">
                            <td colspan="5" class="py-1 px-2.5 bg-slate-100/90 text-slate-900 uppercase text-[10px] tracking-wider border-y border-slate-300">
                                📁 <?= htmlspecialchars($catNombre) ?>
                            </td>
                        </tr>

                        <?php foreach ($items as $it): 
                            $numGlobal++;
                            $esNo = ($it['resultado'] === 'NO');
                        ?>
                        <tr class="<?= $esNo ? 'bg-amber-50/80' : 'hover:bg-slate-50/40' ?>">
                            <td class="py-1.5 px-2 text-center font-mono font-semibold text-slate-500">
                                <?= $numGlobal ?>
                            </td>
                            <td class="py-1.5 px-2.5 text-slate-600 font-medium">
                                <?= htmlspecialchars($it['categoria']) ?>
                            </td>
                            <td class="py-1.5 px-2.5 font-semibold text-slate-900 leading-snug">
                                <?= htmlspecialchars($it['pregunta_texto']) ?>
                            </td>
                            <td class="py-1.5 px-2 text-center font-bold">
                                <?php if ($it['resultado'] === 'SI'): ?>
                                <span class="inline-block px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 text-[10px] uppercase">
                                    ✓ SÍ (OK)
                                </span>
                                <?php else: ?>
                                <span class="inline-block px-2 py-0.5 rounded bg-amber-200 text-amber-900 border border-amber-400 text-[10px] uppercase font-black">
                                    ✗ NO (FALLA)
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-1.5 px-2.5 text-[10px]">
                                <?php if ($esNo && !empty($it['observacion'])): ?>
                                <div class="text-amber-950 font-medium">
                                    <span class="font-bold text-amber-800 uppercase text-[9px] block">Detalle Falla:</span>
                                    <?= htmlspecialchars($it['observacion']) ?>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($it['foto_path'])): ?>
                                <div class="mt-1 flex items-center gap-1.5">
                                    <span class="text-[9px] text-slate-500 font-bold uppercase">Foto:</span>
                                    <img src="../<?= htmlspecialchars($it['foto_path']) ?>" alt="Evidencia" class="h-10 w-auto object-cover rounded border border-slate-300">
                                </div>
                                <?php elseif (!$esNo): ?>
                                <span class="text-slate-400 italic">Sin observaciones</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- OBSERVACIONES GENERALES Y DICTAMEN -->
        <div class="page-break-avoid space-y-3 mb-6">
            <?php if (!empty($inspeccion['observacion_general'])): ?>
            <div class="border border-slate-300 rounded-xl overflow-hidden">
                <div class="bg-slate-100 text-slate-800 px-3 py-1 font-bold text-[10px] uppercase tracking-wider border-b border-slate-200">
                    3. Observaciones Generales del Operador / Conductor
                </div>
                <div class="p-3 text-[11px] text-slate-800 bg-white">
                    <?= nl2br(htmlspecialchars($inspeccion['observacion_general'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($inspeccion['observacion_inspector'])): ?>
            <div class="border border-emerald-300 bg-emerald-50/50 rounded-xl overflow-hidden">
                <div class="bg-emerald-100 text-emerald-900 px-3 py-1 font-bold text-[10px] uppercase tracking-wider border-b border-emerald-200 flex items-center justify-between">
                    <span>4. Dictamen y Observaciones Técnicas del Inspector Responsable</span>
                    <span class="text-[9px] text-emerald-700 font-semibold">Revisión de Seguridad</span>
                </div>
                <div class="p-3 text-[11px] text-emerald-950 font-medium">
                    <?= nl2br(htmlspecialchars($inspeccion['observacion_inspector'])) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- CUADRO DE FIRMAS DE CONFORMIDAD Y CIERRE (AL FINAL) -->
        <div class="page-break-avoid border-2 border-slate-300 rounded-xl overflow-hidden bg-slate-50/40">
            <div class="bg-slate-800 text-white px-3.5 py-1.5 font-bold text-[11px] uppercase tracking-wider text-center">
                Firmas Digitales de Conformidad y Responsabilidad Técnica
            </div>

            <div class="p-4 grid grid-cols-2 gap-6 text-center text-[11px]">
                <!-- Firma Operador -->
                <div class="flex flex-col justify-between border border-slate-300 rounded-xl p-3 bg-white shadow-sm">
                    <span class="block font-bold text-[10px] uppercase text-slate-500 mb-1">
                        Operador / Chofer Responsable
                    </span>
                    <div class="h-20 flex items-center justify-center border-b border-dashed border-slate-300 my-1">
                        <?php if (!empty($inspeccion['firma_digital'])): ?>
                        <img src="../<?= htmlspecialchars($inspeccion['firma_digital']) ?>" alt="Firma Operador" class="h-16 w-auto max-w-[200px] object-contain">
                        <?php else: ?>
                        <span class="text-slate-300 italic text-[11px] select-none">(Registrado en Sistema)</span>
                        <?php endif; ?>
                    </div>
                    <div class="pt-1">
                        <p class="font-extrabold text-slate-900 text-xs"><?= $nombreOperador ?></p>
                        <p class="text-[10px] text-slate-500">Operador Certificado SRT 960/15</p>
                        <p class="text-[9px] text-slate-400 font-mono mt-0.5">Fecha: <?= date('d/m/Y H:i', strtotime($inspeccion['fecha'])) ?> hs</p>
                    </div>
                </div>

                <!-- Firma Inspector -->
                <div class="flex flex-col justify-between border border-slate-300 rounded-xl p-3 bg-white shadow-sm">
                    <span class="block font-bold text-[10px] uppercase text-emerald-800 mb-1">
                        Inspector / Responsable Técnico
                    </span>
                    <div class="h-20 flex items-center justify-center border-b border-dashed border-slate-300 my-1">
                        <?php if (!empty($inspeccion['firma_inspector'])): ?>
                        <img src="../<?= htmlspecialchars($inspeccion['firma_inspector']) ?>" alt="Firma Inspector" class="h-16 w-auto max-w-[200px] object-contain">
                        <?php else: ?>
                        <span class="text-slate-300 italic text-[11px] select-none">(Pendiente de Firma)</span>
                        <?php endif; ?>
                    </div>
                    <div class="pt-1">
                        <p class="font-extrabold text-slate-900 text-xs"><?= $nombreInspector ?></p>
                        <p class="text-[10px] text-slate-500">Aprobación Técnica y Operativa</p>
                        <p class="text-[9px] text-slate-400 font-mono mt-0.5">
                            <?= !empty($inspeccion['fecha_aprobacion']) ? 'Aprobado: ' . date('d/m/Y H:i', strtotime($inspeccion['fecha_aprobacion'])) . ' hs' : 'Dictamen: ' . ucfirst($inspeccion['estado']) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Pie Legal -->
            <div class="bg-slate-100 text-slate-500 px-4 py-2 text-[9px] text-center border-t border-slate-200">
                Documento generado electrónicamente de conformidad con la Resolución S.R.T. Nº 960/15 para control de autoelevadores y maquinarias. Validez legal e intransferible.
            </div>
        </div>

    </div>

</body>
</html>

