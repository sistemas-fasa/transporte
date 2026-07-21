<?php
require_once __DIR__ . '/../includes/auth.php';
requireChoferAccess('combustible_cargar');
$pageTitle = 'Cargar Combustible';

$db = getDB();
$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();
$mensaje = '';
$error = '';

// Si el usuario no tiene id_chofer vinculado, buscarlo en choferes por usuario_id
if (!$idChofer && $userId) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userId]);
        $idChofer = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Si aun no hay id_chofer, buscarlo a traves de vehiculos asignados
if (!$idChofer && $userId) {
    try {
        $stmtVh = $db->prepare("SELECT a.id_chofer FROM vehiculos_usuarios vu JOIN asignaciones a ON vu.vehiculo_id = a.id_camion AND a.activa = 1 WHERE vu.usuario_id = ? LIMIT 1");
        $stmtVh->execute([$userId]);
        $idChofer = $stmtVh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Get all assigned trucks (de ambas fuentes)
$camionesAsignados = [];
$vistos = [];
if ($idChofer) {
    try {
        $stmt = $db->prepare("SELECT c.* FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1");
        $stmt->execute([$idChofer]);
        foreach ($stmt->fetchAll() as $row) {
            $vistos[$row['id_camion']] = true;
            $camionesAsignados[] = $row;
        }
    } catch (Exception $e) {}
}
if ($userId) {
    try {
        $stmt2 = $db->prepare("SELECT c.* FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ?");
        $stmt2->execute([$userId]);
        foreach ($stmt2->fetchAll() as $row) {
            if (!isset($vistos[$row['id_camion']])) {
                $vistos[$row['id_camion']] = true;
                $camionesAsignados[] = $row;
            }
        }
    } catch (Exception $e) {}
}

// Ultimo km_llegada por camion
$ultimoKmPorCamion = [];
foreach ($camionesAsignados as $ca) {
    try {
        $stmtKm = $db->prepare("SELECT km_llegada FROM km_recorrido WHERE id_camion = ? ORDER BY fecha DESC, id_hoja DESC LIMIT 1");
        $stmtKm->execute([$ca['id_camion']]);
        $ultimoKmPorCamion[$ca['id_camion']] = $stmtKm->fetchColumn() ?: $ca['kilometraje_actual'];
    } catch (Exception $e) {
        $ultimoKmPorCamion[$ca['id_camion']] = $ca['kilometraje_actual'];
    }
}

$camion = $camionesAsignados[0] ?? null;

// --- POST handler antes de output (para que header() funcione) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    $estacion = trim($_POST['estacion_servicio'] ?? '');
    $litros = (float)($_POST['litros'] ?? 0);
    $precio_litro = (float)($_POST['precio_litro'] ?? 0);
    $km_carga = isset($_POST['kilometraje']) && $_POST['kilometraje'] !== '' ? (float)$_POST['kilometraje'] : null;
    $horas_carga = isset($_POST['horas_al_cargar']) && $_POST['horas_al_cargar'] !== '' ? (float)$_POST['horas_al_cargar'] : null;
    $id_camion = (int)($_POST['id_camion'] ?? 0);

    if (!$idChofer) {
        $error = 'No se pudo identificar el chofer asociado a su usuario. Contacte al administrador.';
    } else {
        try {
            // Verificar si ya se registró una carga idéntica en los últimos 2 minutos para prevenir duplicados
            $stmtDup = $db->prepare("SELECT id_combustible FROM combustible WHERE id_chofer = ? AND id_camion = ? AND ABS(litros - ?) < 0.001 AND fecha >= (NOW() - INTERVAL 2 MINUTE) LIMIT 1");
            $stmtDup->execute([$idChofer, $id_camion, $litros]);
            if ($stmtDup->fetchColumn()) {
                $error = 'Esta carga de combustible ya fue registrada hace unos momentos. Se evitó el registro duplicado.';
            } else {
                $uploadDir = __DIR__ . '/../assets/uploads/tickets/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $foto_ticket = null;
                if (isset($_FILES['foto_ticket']) && $_FILES['foto_ticket']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['foto_ticket']['name'], PATHINFO_EXTENSION);
                    $foto_ticket = 'ticket_' . uniqid() . '.' . $ext;
                    @move_uploaded_file($_FILES['foto_ticket']['tmp_name'], $uploadDir . $foto_ticket);
                }

                $stmt = $db->prepare("INSERT INTO combustible (fecha, id_chofer, id_camion, estacion_servicio, litros, precio_litro, kilometraje_al_cargar, horas_al_cargar, foto_ticket, id_usuario_registra) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$idChofer, $id_camion, $estacion, $litros, $precio_litro, $km_carga, $horas_carga, $foto_ticket, $userId]);
                
                // Update camion mileage/hours based on por_hora attribute
                $stmtCam = $db->prepare("SELECT por_hora FROM camiones WHERE id_camion = ?");
                $stmtCam->execute([$id_camion]);
                $isPorHora = (int)$stmtCam->fetchColumn();
                
                if ($isPorHora) {
                    if ($km_carga > 0) {
                        $db->prepare("UPDATE camiones SET kilometraje_actual = ? WHERE id_camion = ?")->execute([$km_carga, $id_camion]);
                    }
                    if ($horas_carga > 0) {
                        $db->prepare("UPDATE camiones SET horas_actuales = ? WHERE id_camion = ?")->execute([$horas_carga, $id_camion]);
                    }
                } else {
                    if ($km_carga > 0) {
                        $db->prepare("UPDATE camiones SET kilometraje_actual = ? WHERE id_camion = ?")->execute([$km_carga, $id_camion]);
                    }
                }

                // Recalculate fuel calculations for this truck
                recalcularCombustibleCamion($id_camion);

                registrarAuditoria($userId, 'create', 'combustible', $db->lastInsertId(), "Carga de $litros L para camion ID $id_camion");
                header('Location: ' . BASE_URL . '/chofer/panel.php?ok=carga_combustible');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';

$combustibles = [];
if ($idChofer) {
    $stmtC = $db->prepare("SELECT co.*, c.patente FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion WHERE co.id_chofer = ? ORDER BY co.fecha DESC LIMIT 20");
    $stmtC->execute([$idChofer]);
    $combustibles = $stmtC->fetchAll();
} elseif ($userId) {
    $stmtC = $db->prepare("SELECT co.*, c.patente FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion WHERE co.id_usuario_registra = ? ORDER BY co.fecha DESC LIMIT 20");
    $stmtC->execute([$userId]);
    $combustibles = $stmtC->fetchAll();
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
<div>
<nav class="flex items-center gap-2 text-on-surface-variant font-label-caps text-label-caps mb-2">
<span class="uppercase">Combustible</span>
<span class="material-symbols-outlined text-[16px]">chevron_right</span>
<span class="uppercase text-primary font-bold">Nueva Carga</span>
</nav>
<h2 class="font-headline-lg text-headline-lg text-primary">Nueva Carga de Combustible</h2>
</div>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (empty($camionesAsignados)): ?><div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4">No tiene un vehiculo asignado. Contacte al administrador.</div><?php endif; ?>

<form id="fuelForm" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-gutter">
<div class="lg:col-span-8 bg-surface-container-lowest border border-outline-variant p-lg">
<div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-gutter">
<?php if (!empty($camionesAsignados)): ?>
<div class="md:col-span-2 flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="camionSelect" class="w-full border border-outline-variant rounded p-3 font-body-md focus:ring-2 focus:ring-primary focus:outline-none" required>
<option value="">Seleccionar camion...</option>
<?php foreach ($camionesAsignados as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" data-km="<?= $ultimoKmPorCamion[$ca['id_camion']] ?? $ca['kilometraje_actual'] ?>" data-por-hora="<?= $ca['por_hora'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo']) ?></option>
<?php endforeach; ?>
</select>
<div id="camionInfo" class="bg-primary-container/10 p-4 rounded-lg <?= count($camionesAsignados) === 1 ? '' : 'hidden' ?>">
<p class="font-bold" id="camionInfoText"><?= count($camionesAsignados) === 1 ? 'Vehiculo: ' . htmlspecialchars($camionesAsignados[0]['patente']) . ' - KM Actual: ' . number_format((float)($ultimoKmPorCamion[$camionesAsignados[0]['id_camion']] ?? $camionesAsignados[0]['kilometraje_actual']), 0) : 'Seleccione un vehiculo' ?></p>
</div>
</div>
<?php endif; ?>

<div class="flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estacion de Servicio</label>
<select name="estacion_servicio" id="estacion" class="w-full border border-outline-variant rounded p-3 font-body-md focus:ring-2 focus:ring-primary focus:outline-none" required>
<option value="">Seleccionar...</option>
<option value="YPF">YPF</option>
<option value="SHELL">SHELL</option>
<option value="AXION">AXION</option>
<option value="CISTERNA">CISTERNA</option>
</select>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cantidad (Litros)</label>
<div class="relative">
<input name="litros" id="litros" type="number" step="any" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none" placeholder="0.0000" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">ev_station</span>
</div>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Precio por Litro</label>
<div class="relative">
<input name="precio_litro" id="precio" type="number" step="any" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none pl-8" placeholder="0.0000" required/>
<span class="absolute left-3 top-3.5 text-outline font-data-mono">$</span>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">payments</span>
</div>
</div>

<div class="flex flex-col gap-2">
<label id="kmLabel" class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje Actual</label>
<div class="relative">
<input name="kilometraje" id="kmInput" type="number" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none" placeholder="Ej. 124500" required/>
<span id="kmIcon" class="material-symbols-outlined absolute right-3 top-3 text-outline">speed</span>
</div>
</div>

<div class="flex flex-col gap-2 hidden" id="horasContainer">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Horas Actuales (Horómetro)</label>
<div class="relative">
<input name="horas_al_cargar" id="horasInput" type="number" step="any" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none" placeholder="Ej. 3450"/>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">schedule</span>
</div>
</div>

</div>
</div>

<div class="lg:col-span-4 flex flex-col gap-gutter">
<div class="bg-surface-container-lowest border border-outline-variant p-lg flex flex-col h-full">
<h3 class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-4 font-bold">Documentacion</h3>
<div class="flex-1 border-2 border-dashed border-outline-variant rounded-xl flex flex-col items-center justify-center p-8 text-center bg-surface hover:bg-surface-container transition-colors cursor-pointer group relative overflow-hidden" id="drop-zone">
<input accept="image/*" capture="environment" class="hidden" id="file-input" name="foto_ticket" type="file"/>
<div class="hidden absolute inset-0 w-full h-full bg-surface" id="preview-container">
<img alt="Ticket preview" class="w-full h-full object-cover" id="preview-img"/>
<div class="absolute inset-0 bg-primary/40 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity">
<span class="material-symbols-outlined text-on-primary text-4xl">edit</span>
</div>
</div>
<div class="flex flex-col items-center" id="placeholder-content">
<div class="w-16 h-16 bg-secondary-container rounded-full flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
<span class="material-symbols-outlined text-on-secondary-container text-3xl">add_a_photo</span>
</div>
<p class="font-body-md text-primary font-bold">Subir Foto del Ticket</p>
<p class="text-[12px] text-on-secondary-container mt-2">Arrastre el archivo o haga clic</p>
</div>
</div>
<button type="button" id="scanBtn" onclick="escanearTicket()" disabled class="mt-3 w-full px-4 py-2 bg-secondary-container text-on-secondary-container font-bold rounded-lg hover:opacity-90 transition-opacity flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
<span class="material-symbols-outlined text-[20px]">document_scanner</span> Escanear Ticket
</button>
<div class="mt-3 p-3 border border-outline-variant bg-surface flex items-start gap-3">
<span class="material-symbols-outlined text-secondary text-[18px]">info</span>
<p class="text-[11px] text-on-secondary-container leading-tight">La foto se procesa en el navegador. Revise los datos detectados antes de guardar.</p>
</div>
</div>
</div>

<div class="lg:col-span-12 flex gap-3 pt-4">
<a href="<?= BASE_URL ?>/chofer/panel.php" class="px-6 py-2 border border-outline text-primary font-bold rounded-lg hover:bg-surface-container-high transition-colors">Cancelar</a>
<button type="submit" id="submitBtn" class="px-6 py-2 bg-primary text-on-primary font-bold rounded-lg hover:opacity-90 transition-opacity flex items-center gap-2">
<span class="material-symbols-outlined text-[20px]">save</span> Guardar Registro
</button>
</div>
</div>
</form>

<div class="mt-12">
    <h3 class="font-headline-sm text-headline-sm text-primary mb-4">Mis Ultimas Cargas</h3>
    <div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-surface-container-high/50">
                <tr>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant">FECHA</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant">CAMION</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant">ESTACION</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">LITROS</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">IMPORTE (P/L)</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">ODÓMETRO (RECORRIDO)</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">RENDIMIENTO / CONSUMO</th>
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">COSTO RECORRIDO</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant">
                <?php if (empty($combustibles)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-on-surface-variant">No hay cargas registradas</td></tr>
                <?php else: ?>
                    <?php foreach ($combustibles as $c): ?>
                        <tr class="hover:bg-surface-container transition-colors">
                            <td class="px-4 py-3 font-data-mono text-sm"><?= date('d/m/Y H:i', strtotime($c['fecha'])) ?></td>
                            <td class="px-4 py-3 font-bold text-primary"><?= htmlspecialchars($c['patente']) ?></td>
                            <td class="px-4 py-3 text-sm"><?= htmlspecialchars($c['estacion_servicio'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-right font-data-mono text-xs whitespace-nowrap"><?= number_format($c['litros'], 2) ?> L</td>
                            <td class="px-4 py-3 text-right font-data-mono text-sm">
                                <div class="font-bold">$<?= number_format($c['litros'] * $c['precio_litro'], 2) ?></div>
                                <div class="text-xs text-on-surface-variant">$<?= number_format($c['precio_litro'], 2) ?>/L</div>
                            </td>
                            <td class="px-4 py-3 text-right font-data-mono text-sm">
                                <div>
                                    <?php 
                                        $parts = [];
                                        if ($c['kilometraje_al_cargar'] !== null && $c['kilometraje_al_cargar'] > 0) $parts[] = number_format($c['kilometraje_al_cargar'], 0) . ' KM';
                                        if ($c['horas_al_cargar'] !== null && $c['horas_al_cargar'] > 0) $parts[] = number_format($c['horas_al_cargar'], 1) . ' HS';
                                        echo !empty($parts) ? implode(' / ', $parts) : '-';
                                    ?>
                                </div>
                                <div class="text-xs font-bold mt-0.5">
                                    <?php
                                        if ($c['error_consumo']) {
                                            echo '<span class="text-amber-600 font-bold" title="' . htmlspecialchars($c['error_consumo']) . '">⚠️ Error</span>';
                                        } else {
                                            $parts = [];
                                            if ($c['km_recorridos'] !== null) $parts[] = '+' . number_format($c['km_recorridos'], 0) . ' KM';
                                            if ($c['hs_recorridas'] !== null) $parts[] = '+' . number_format($c['hs_recorridas'], 1) . ' HS';
                                            echo !empty($parts) ? '<span class="text-green-600">' . implode(' / ', $parts) . '</span>' : '<span class="text-on-surface-variant">-</span>';
                                        }
                                    ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-data-mono text-sm">
                                <?php if ($c['error_consumo']): ?>
                                    <span class="text-on-surface-variant text-xs">-</span>
                                <?php else: ?>
                                    <?php if ($c['litros_cada_100km'] !== null): ?>
                                        <div class="font-bold text-blue-600 text-xs whitespace-nowrap"><?= number_format($c['litros_cada_100km'], 2) ?> L/100 Km</div>
                                        <div class="text-[10px] text-on-surface-variant whitespace-nowrap"><?= number_format($c['km_por_litro'], 2) ?> Km/L</div>
                                    <?php elseif ($c['litros_por_hora'] !== null): ?>
                                        <div class="font-bold text-blue-600 text-xs whitespace-nowrap"><?= number_format($c['litros_por_hora'], 2) ?> Hs/L</div>
                                    <?php else: ?>
                                        <span class="text-on-surface-variant text-xs">-</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right font-data-mono text-sm">
                                <?php
                                    $parts = [];
                                    if ($c['costo_por_km'] !== null) $parts[] = '$' . number_format($c['costo_por_km'], 2) . '/Km';
                                    if ($c['costo_por_hora'] !== null) $parts[] = '$' . number_format($c['costo_por_hora'], 2) . '/Hs';
                                    echo !empty($parts) ? implode('<br>', $parts) : '-';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js" defer></script>
<script>
// Truck selection
const camionSelect = document.getElementById('camionSelect');
const camionInfo = document.getElementById('camionInfo');
const camionInfoText = document.getElementById('camionInfoText');
const kmLabel = document.getElementById('kmLabel');
const kmInput = document.getElementById('kmInput');
const kmIcon = document.getElementById('kmIcon');

function updateCargaFields() {
    if (!camionSelect) return;
    const opt = camionSelect.options[camionSelect.selectedIndex];
    const horasContainer = document.getElementById('horasContainer');
    const horasInput = document.getElementById('horasInput');
    
    if (opt && opt.value) {
        const km = opt.getAttribute('data-km') || '0';
        const porHora = opt.getAttribute('data-por-hora') == '1';
        
        if (porHora) {
            camionInfoText.textContent = 'Vehiculo: ' + opt.text + ' - HS Actual: ' + Number(km).toLocaleString('es-ES');
            if (kmLabel) kmLabel.textContent = 'Kilometraje Actual (Opcional)';
            if (kmInput) {
                kmInput.placeholder = 'Ej. 124500';
                kmInput.removeAttribute('required');
            }
            if (kmIcon) kmIcon.textContent = 'speed';
            if (horasContainer) horasContainer.classList.remove('hidden');
            if (horasInput) horasInput.setAttribute('required', 'required');
        } else {
            camionInfoText.textContent = 'Vehiculo: ' + opt.text + ' - KM Actual: ' + Number(km).toLocaleString('es-ES');
            if (kmLabel) kmLabel.textContent = 'Kilometraje Actual';
            if (kmInput) {
                kmInput.placeholder = 'Ej. 124500';
                kmInput.setAttribute('required', 'required');
            }
            if (kmIcon) kmIcon.textContent = 'speed';
            if (horasContainer) horasContainer.classList.add('hidden');
            if (horasInput) {
                horasInput.removeAttribute('required');
                horasInput.value = '';
            }
        }
        camionInfo.classList.remove('hidden');
    } else {
        camionInfo.classList.add('hidden');
    }
}

if (camionSelect) {
    camionSelect.addEventListener('change', updateCargaFields);
    updateCargaFields();
}

// Upload
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const previewContainer = document.getElementById('preview-container');
const previewImg = document.getElementById('preview-img');
const placeholderContent = document.getElementById('placeholder-content');
const scanBtn = document.getElementById('scanBtn');
function onImageSelected(file) {
if (file) {
const reader = new FileReader();
reader.onload = (e) => { previewImg.src = e.target.result; previewContainer.classList.remove('hidden'); placeholderContent.classList.add('hidden'); scanBtn.disabled = false; };
reader.readAsDataURL(file);
}
}
dropZone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', (e) => { onImageSelected(e.target.files[0]); });
dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('bg-secondary-container/20'); });
dropZone.addEventListener('dragleave', () => { dropZone.classList.remove('bg-secondary-container/20'); });
dropZone.addEventListener('drop', (e) => {
e.preventDefault(); dropZone.classList.remove('bg-secondary-container/20');
const file = e.dataTransfer.files[0];
if (file && file.type.startsWith('image/')) { fileInput.files = e.dataTransfer.files; onImageSelected(file); }
});

async function escanearTicket() {
const img = previewImg;
if (!img.src || img.src === '' || img.src === window.location.href) { alert('Primero suba una foto del ticket.'); return; }
scanBtn.disabled = true;
scanBtn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[20px]">sync</span> Escaneando...';
try {
const result = await Tesseract.recognize(img.src, 'spa', { logger: function(){} });
const text = result.data.text;
// Litros
let litrosMatch = text.match(/(\d+[.,]\d+)\s*L(?:itros?|T|\.)?\b/i);
if (!litrosMatch) litrosMatch = text.match(/(?:Litros?|Cantidad|Neto)\s*:?\s*(\d+[.,]\d+)/i);
if (!litrosMatch) litrosMatch = text.match(/(?:Total\s*L[íi]quidos?|Volumen)\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (litrosMatch) {
    let val = parseFloat(litrosMatch[1].replace(',', '.'));
    document.getElementById('litros').value = isNaN(val) ? '' : Number(val.toFixed(4));
}
// Precio por litro
let precMatch = text.match(/(?:Precio\s*(?:por\s*)?[Ll]itro|P\.?\s*[Uu]nitario|\$\/[Ll]|\$\s*\/?\s*[Ll]itro)\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (!precMatch) precMatch = text.match(/\$\s*(\d+[.,]\d{3,4})(?:\s*\/\s*L)?/i);
if (!precMatch) precMatch = text.match(/[Pp]recio\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (precMatch) {
    let val = parseFloat(precMatch[1].replace(',', '.'));
    document.getElementById('precio').value = isNaN(val) ? '' : Number(val.toFixed(4));
}
// Fecha
let fechaMatch = text.match(/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/);
if (!fechaMatch) fechaMatch = text.match(/(\d{2})\s*[\/\-]\s*(\d{2})\s*[\/\-]\s*(\d{4})/);
if (fechaMatch) {
const fechaStr = fechaMatch[3] + '-' + fechaMatch[2] + '-' + fechaMatch[1];
// No hay campo de fecha editable en el form chofer, se usa NOW() automaticamente
}
// Estacion de servicio
const estacionSelect = document.getElementById('estacion');
['YPF','SHELL','AXION','CISTERNA'].forEach(v => {
if (text.toUpperCase().includes(v)) { estacionSelect.value = v; }
});
alert('Datos extraidos del ticket. Revise y corrija si es necesario.');
} catch (err) {
alert('Error al escanear: ' + err.message);
console.error(err);
} finally {
scanBtn.disabled = false;
scanBtn.innerHTML = '<span class="material-symbols-outlined text-[20px]">document_scanner</span> Escanear Ticket';
}
}

const fuelForm = document.getElementById('fuelForm');
if (fuelForm) {
    fuelForm.addEventListener('submit', function(e) {
        if (this.dataset.submitting) {
            e.preventDefault();
            return false;
        }
        this.dataset.submitting = "true";
        const btn = document.getElementById('submitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[20px]">sync</span> Guardando...';
            btn.classList.add('opacity-75', 'cursor-not-allowed');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
