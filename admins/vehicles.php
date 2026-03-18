<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

$page_title = 'Vehicles';
$current_page = 'vehicles';

require_once __DIR__ . '/../includes/db.php';

$success = '';
$error = '';

$knownVehicleModels = [
    'Toyota' => ['Vios', 'Corolla Altis', 'Fortuner', 'Innova', 'Hilux', 'Wigo', 'Avanza'],
    'Honda' => ['Civic', 'City', 'CR-V', 'BR-V', 'HR-V', 'Jazz'],
    'Mitsubishi' => ['Montero Sport', 'Mirage', 'Xpander', 'L300', 'Strada'],
    'Nissan' => ['Navara', 'Almera', 'Terra', 'Livina'],
    'Ford' => ['Ranger', 'Everest', 'Territory', 'EcoSport'],
    'Hyundai' => ['Accent', 'Tucson', 'Starex', 'Reina'],
    'Kia' => ['Soluto', 'Seltos', 'Sportage', 'Stonic'],
    'Suzuki' => ['Ertiga', 'Swift', 'Dzire', 'Celerio'],
    'Mazda' => ['Mazda2', 'Mazda3', 'CX-5', 'BT-50'],
    'Isuzu' => ['D-Max', 'MU-X'],
    'Chevrolet' => ['Trailblazer', 'Spark'],
    'BMW' => ['3 Series', '5 Series', 'X3', 'X5'],
    'Mercedes-Benz' => ['C-Class', 'E-Class', 'GLC'],
    'Audi' => ['A4', 'Q5', 'Q7'],
    'Lexus' => ['IS', 'ES', 'RX', 'NX'],
];

function avResolvedModel(array $post, string $modelKey, string $otherKey): string {
    $modelRaw = trim($post[$modelKey] ?? '');
    $modelOther = trim($post[$otherKey] ?? '');
    return $modelRaw === '__other__' ? $modelOther : $modelRaw;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        try {
            $model = avResolvedModel($_POST, 'model', 'model_other');
            $stmt = $pdo->prepare("INSERT INTO vehicles (client_id, plate_number, vin, make, model, year, color, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['client_id'],
                $_POST['plate_number'],
                $_POST['vin'],
                $_POST['make'],
                $model,
                $_POST['year'],
                $_POST['color'],
                $_POST['status']
            ]);
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, 'Created vehicle', 'vehicles', $new_id);
            $success = 'Vehicle added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add vehicle: ' . $e->getMessage();
        }
    }

    if ($action === 'edit') {
        try {
            $model = avResolvedModel($_POST, 'model', 'model_other_edit');
            $stmt = $pdo->prepare("UPDATE vehicles SET client_id = ?, plate_number = ?, vin = ?, make = ?, model = ?, year = ?, color = ?, status = ? WHERE vehicle_id = ?");
            $stmt->execute([
                $_POST['client_id'],
                $_POST['plate_number'],
                $_POST['vin'],
                $_POST['make'],
                $model,
                $_POST['year'],
                $_POST['color'],
                $_POST['status'],
                $_POST['vehicle_id']
            ]);
            logAudit($pdo, 'Updated vehicle', 'vehicles', $_POST['vehicle_id']);
            $success = 'Vehicle updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update vehicle: ' . $e->getMessage();
        }
    }

    if ($action === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
            $stmt->execute([$_POST['vehicle_id']]);
            logAudit($pdo, 'Deleted vehicle', 'vehicles', $_POST['vehicle_id']);
            $success = 'Vehicle deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete vehicle: ' . $e->getMessage();
        }
    }
}

// Fetch all vehicles with client name
$vehicles = [];
try {
    $vehicles = $pdo->query("SELECT v.*, c.full_name as client_name FROM vehicles v LEFT JOIN clients c ON v.client_id = c.client_id ORDER BY v.created_at DESC")->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to fetch vehicles: ' . $e->getMessage();
}

// Fetch clients for dropdown
$clients = [];
try {
    $clients = $pdo->query("SELECT client_id, full_name FROM clients ORDER BY full_name")->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to fetch clients: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="../includes/style/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="admin-content">

            <!-- Alert messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Page header -->
            <div class="page-header">
                <h2><i class="fas fa-car"></i> Vehicles</h2>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add New
                </button>
            </div>

            <!-- Admin card with table -->
            <div class="admin-card">
                <div class="table-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search vehicles..." onkeyup="searchTable()">
                    </div>
                </div>

                <?php if (count($vehicles) > 0): ?>
                <div class="table-responsive">
                    <table class="admin-table" id="vehiclesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Plate #</th>
                                <th>VIN</th>
                                <th>Make/Model</th>
                                <th>Year</th>
                                <th>Color</th>
                                <th>Client</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['vehicle_id']) ?></td>
                                <td><?= htmlspecialchars($row['plate_number']) ?></td>
                                <td><?= htmlspecialchars($row['vin'] ?? '') ?></td>
                                <td><?= htmlspecialchars(($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($row['year'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['color'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['client_name'] ?? 'N/A') ?></td>
                                <td><span class="badge badge-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))) ?></span></td>
                                <td class="action-btns">
                                    <button class="btn-icon btn-edit" onclick="editVehicle(
                                        <?= $row['vehicle_id'] ?>,
                                        <?= $row['client_id'] ?>,
                                        '<?= htmlspecialchars(addslashes($row['plate_number']), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['vin'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['make'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['model'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['year'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['color'] ?? ''), ENT_QUOTES) ?>',
                                        '<?= htmlspecialchars(addslashes($row['status']), ENT_QUOTES) ?>'
                                    )"><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this vehicle?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="vehicle_id" value="<?= $row['vehicle_id'] ?>">
                                        <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-car"></i>
                    <h3>No vehicles found</h3>
                    <p>Click "Add New" to register a vehicle.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Modal -->
            <div class="modal-overlay" id="addModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Add Vehicle</h3>
                        <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" class="form-control" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Plate Number</label>
                                <input type="text" name="plate_number" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>VIN</label>
                                <input type="text" name="vin" class="form-control" maxlength="17">
                            </div>
                            <div class="form-group">
                                <label>Make</label>
                                <select name="make" id="add_make" class="form-control" onchange="avPopulateModelOptions('add_make','add_model','add_model_other_wrap')">
                                    <option value="">-- Select Make --</option>
                                    <?php foreach (array_keys($knownVehicleModels) as $mk): ?>
                                        <option value="<?= htmlspecialchars($mk) ?>"><?= htmlspecialchars($mk) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Model</label>
                                <select name="model" id="add_model" class="form-control">
                                    <option value="">-- Select Make First --</option>
                                </select>
                            </div>
                            <div class="form-group" id="add_model_other_wrap" style="display:none;">
                                <label>New Model</label>
                                <input type="text" name="model_other" id="add_model_other" class="form-control" placeholder="Enter new model">
                            </div>
                            <div class="form-group">
                                <label>Year</label>
                                <input type="number" name="year" class="form-control" min="1900" max="2099">
                            </div>
                            <div class="form-group">
                                <label>Color</label>
                                <input type="text" name="color" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control" required>
                                    <option value="active">Active</option>
                                    <option value="in_service">In Service</option>
                                    <option value="completed">Completed</option>
                                    <option value="released">Released</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" id="editModal">
                <div class="modal">
                    <div class="modal-header">
                        <h3>Edit Vehicle</h3>
                        <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Client</label>
                                <select name="client_id" id="edit_client_id" class="form-control" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Plate Number</label>
                                <input type="text" name="plate_number" id="edit_plate_number" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>VIN</label>
                                <input type="text" name="vin" id="edit_vin" class="form-control" maxlength="17">
                            </div>
                            <div class="form-group">
                                <label>Make</label>
                                <select name="make" id="edit_make" class="form-control" onchange="avPopulateModelOptions('edit_make','edit_model','edit_model_other_wrap')">
                                    <option value="">-- Select Make --</option>
                                    <?php foreach (array_keys($knownVehicleModels) as $mk): ?>
                                        <option value="<?= htmlspecialchars($mk) ?>"><?= htmlspecialchars($mk) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Model</label>
                                <select name="model" id="edit_model" class="form-control">
                                    <option value="">-- Select Make First --</option>
                                </select>
                            </div>
                            <div class="form-group" id="edit_model_other_wrap" style="display:none;">
                                <label>New Model</label>
                                <input type="text" name="model_other_edit" id="edit_model_other" class="form-control" placeholder="Enter new model">
                            </div>
                            <div class="form-group">
                                <label>Year</label>
                                <input type="number" name="year" id="edit_year" class="form-control" min="1900" max="2099">
                            </div>
                            <div class="form-group">
                                <label>Color</label>
                                <input type="text" name="color" id="edit_color" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="active">Active</option>
                                    <option value="in_service">In Service</option>
                                    <option value="completed">Completed</option>
                                    <option value="released">Released</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="includes/admin.js"></script>
<script>
const avMakeModelMap = <?= json_encode($knownVehicleModels) ?>;

function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function avPopulateModelOptions(makeId, modelId, otherWrapId, selectedModel = '') {
    const makeSel = document.getElementById(makeId);
    const modelSel = document.getElementById(modelId);
    const otherWrap = document.getElementById(otherWrapId);
    const otherInputId = modelId === 'edit_model' ? 'edit_model_other' : 'add_model_other';
    const otherInput = document.getElementById(otherInputId);
    const make = makeSel.value;
    const models = avMakeModelMap[make] || [];

    modelSel.innerHTML = '';
    if (!make) {
        modelSel.innerHTML = '<option value="">-- Select Make First --</option>';
        if (otherWrap) otherWrap.style.display = 'none';
        if (otherInput) otherInput.value = '';
        return;
    }

    modelSel.innerHTML = '<option value="">-- Select Model --</option>';
    models.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = m;
        modelSel.appendChild(opt);
    });

    const otherOpt = document.createElement('option');
    otherOpt.value = '__other__';
    otherOpt.textContent = 'Other / New Model';
    modelSel.appendChild(otherOpt);

    if (selectedModel) {
        if (models.includes(selectedModel)) {
            modelSel.value = selectedModel;
            if (otherWrap) otherWrap.style.display = 'none';
            if (otherInput) otherInput.value = '';
        } else {
            modelSel.value = '__other__';
            if (otherWrap) otherWrap.style.display = '';
            if (otherInput) otherInput.value = selectedModel;
        }
    } else {
        if (otherWrap) otherWrap.style.display = 'none';
        if (otherInput) otherInput.value = '';
    }
}

function editVehicle(vehicleId, clientId, plateNumber, vin, make, model, year, color, status) {
    document.getElementById('edit_vehicle_id').value = vehicleId;
    document.getElementById('edit_client_id').value = clientId;
    document.getElementById('edit_plate_number').value = plateNumber;
    document.getElementById('edit_vin').value = vin;
    const editMake = document.getElementById('edit_make');
    if (make && !avMakeModelMap[make]) {
        const exists = Array.from(editMake.options).some(o => o.value === make);
        if (!exists) {
            const opt = document.createElement('option');
            opt.value = make;
            opt.textContent = make + ' (existing)';
            editMake.appendChild(opt);
        }
    }
    editMake.value = make;
    avPopulateModelOptions('edit_make', 'edit_model', 'edit_model_other_wrap', model);
    document.getElementById('edit_year').value = year;
    document.getElementById('edit_color').value = color;
    document.getElementById('edit_status').value = status;
    openModal('editModal');
}

function searchTable() {
    var input = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('vehiclesTable');
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        rows[i].style.display = text.indexOf(input) > -1 ? '' : 'none';
    }
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.classList.remove('active');
        }
    });
});

document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'add_model') {
        document.getElementById('add_model_other_wrap').style.display = e.target.value === '__other__' ? '' : 'none';
        if (e.target.value !== '__other__') document.getElementById('add_model_other').value = '';
    }
    if (e.target && e.target.id === 'edit_model') {
        document.getElementById('edit_model_other_wrap').style.display = e.target.value === '__other__' ? '' : 'none';
        if (e.target.value !== '__other__') document.getElementById('edit_model_other').value = '';
    }
});
</script>
</body>
</html>
