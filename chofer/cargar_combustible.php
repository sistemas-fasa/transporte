<?php
require_once __DIR__ . '/../includes/auth.php';
requireChoferAccess('combustible_cargar');
$pageTitle = 'Cargar Combustible';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    $estacion = trim($_POST['estacion_servicio'] ?? '');
    $litros = (float)($_POST['litros'] ?? 0);
    $precio_litro = (float)($_POST['precio_litro'] ?? 0);
    $km_carga = (float)($_POST['kilometraje'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);

    $foto_ticket = null;
    if (isset($_FILES['foto_ticket']) && $_FILES['foto_ticket']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['foto_ticket']['name'], PATHINFO_EXTENSION);
        $foto_ticket = 'ticket_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['foto_ticket']['tmp_name'], __DIR__ . '/../assets/uploads/tickets/' . $foto_ticket);
    }

    if (!$idChofer) {
        $error = 'No se pudo identificar el chofer asociado a su usuario. Contacte al administrador.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO combustible (fecha, id_chofer, id_camion, estacion_servicio, litros, precio_litro, kilometraje_al_cargar, foto_ticket, id_usuario_registra) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$idChofer, $id_camion, $estacion, $litros, $precio_litro, $km_carga, $foto_ticket, $userId]);
            registrarAuditoria($userId, 'create', 'combustible', $db->lastInsertId(), "Carga de $litros L para camion ID $id_camion");
            $mensaje = 'Carga de combustible registrada exitosamente';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

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
<?php if (empty($camionesAsignados)): ?><div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4">No tiene un camion asignado. Contacte al administrador.</div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-gutter">
<div class="lg:col-span-8 bg-surface-container-lowest border border-outline-variant p-lg">
<div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-gutter">
<?php if (!empty($camionesAsignados)): ?>
<div class="md:col-span-2 flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="camionSelect" class="w-full border border-outline-variant rounded p-3 font-body-md focus:ring-2 focus:ring-primary focus:outline-none" required>
<option value="">Seleccionar camion...</option>
<?php foreach ($camionesAsignados as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" data-km="<?= $ultimoKmPorCamion[$ca['id_camion']] ?? $ca['kilometraje_actual'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo']) ?></option>
<?php endforeach; ?>
</select>
<div id="camionInfo" class="bg-primary-container/10 p-4 rounded-lg <?= count($camionesAsignados) === 1 ? '' : 'hidden' ?>">
<p class="font-bold" id="camionInfoText"><?= count($camionesAsignados) === 1 ? 'Camion: ' . htmlspecialchars($camionesAsignados[0]['patente']) . ' - KM Actual: ' . number_format((float)($ultimoKmPorCamion[$camionesAsignados[0]['id_camion']] ?? $camionesAsignados[0]['kilometraje_actual']), 0) : 'Seleccione un camion' ?></p>
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
<input name="litros" id="litros" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none" placeholder="0.00" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">ev_station</span>
</div>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Precio por Litro</label>
<div class="relative">
<input name="precio_litro" id="precio" type="number" step="0.001" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none pl-8" placeholder="0.000" required/>
<span class="absolute left-3 top-3.5 text-outline font-data-mono">$</span>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">payments</span>
</div>
</div>

<div class="flex flex-col gap-2">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje Actual</label>
<div class="relative">
<input name="kilometraje" type="number" class="w-full border border-outline-variant rounded p-3 font-data-mono focus:ring-2 focus:ring-primary focus:outline-none" placeholder="Ej. 124500"/>
<span class="material-symbols-outlined absolute right-3 top-3 text-outline">speed</span>
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
<button type="submit" class="px-6 py-2 bg-primary text-on-primary font-bold rounded-lg hover:opacity-90 transition-opacity flex items-center gap-2">
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
                    <th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">PRECIO/L</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant">
                <?php if (empty($combustibles)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-on-surface-variant">No hay cargas registradas</td></tr>
                <?php else: ?>
                    <?php foreach ($combustibles as $c): ?>
                        <tr class="hover:bg-surface-container transition-colors">
                            <td class="px-4 py-3 font-data-mono"><?= date('d/m/Y H:i', strtotime($c['fecha'])) ?></td>
                            <td class="px-4 py-3 font-bold"><?= htmlspecialchars($c['patente']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($c['estacion_servicio'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-right font-data-mono"><?= number_format($c['litros'], 2) ?> L</td>
                            <td class="px-4 py-3 text-right font-data-mono">$<?= number_format($c['precio_litro'], 3) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
// Truck selection
const camionSelect = document.getElementById('camionSelect');
const camionInfo = document.getElementById('camionInfo');
const camionInfoText = document.getElementById('camionInfoText');
if (camionSelect) {
camionSelect.addEventListener('change', function() {
const opt = this.options[this.selectedIndex];
if (opt && opt.value) {
const km = opt.getAttribute('data-km') || '0';
camionInfoText.textContent = 'Camion: ' + opt.text + ' - KM Actual: ' + Number(km).toLocaleString('es-ES');
camionInfo.classList.remove('hidden');
} else {
camionInfo.classList.add('hidden');
}
});
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
if (litrosMatch) { document.getElementById('litros').value = litrosMatch[1].replace(',', '.'); }
// Precio por litro
let precMatch = text.match(/(?:Precio\s*(?:por\s*)?[Ll]itro|P\.?\s*[Uu]nitario|\$\/[Ll]|\$\s*\/?\s*[Ll]itro)\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (!precMatch) precMatch = text.match(/\$\s*(\d+[.,]\d{3,4})(?:\s*\/\s*L)?/i);
if (!precMatch) precMatch = text.match(/[Pp]recio\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (precMatch) { document.getElementById('precio').value = precMatch[1].replace(',', '.'); }
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
