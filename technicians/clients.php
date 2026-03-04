<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') { header('Location: ../users/login.php'); exit; }
$page_title = 'Clients & Vehicles'; $current_page = 'clients';
require_once __DIR__ . '/../includes/db.php';

$tid = $_SESSION['user_id'];

// Get all clients and vehicles associated with this technician's assignments
$clients_vehicles = $pdo->prepare("
    SELECT DISTINCT c.client_id, c.full_name, c.phone, c.email, c.address,
        v.vehicle_id, v.plate_number, v.make, v.model, v.year, v.color, v.status AS vehicle_status,
        COUNT(a.assignment_id) AS total_services,
        SUM(CASE WHEN a.status = 'Finished' THEN 1 ELSE 0 END) AS completed_services,
        SUM(CASE WHEN a.status IN ('Assigned','Ongoing') THEN 1 ELSE 0 END) AS active_services
    FROM assignments a
    LEFT JOIN vehicles v ON a.vehicle_id = v.vehicle_id
    LEFT JOIN clients c ON v.client_id = c.client_id
    WHERE a.technician_id = ?
    GROUP BY c.client_id, v.vehicle_id
    ORDER BY c.full_name ASC, v.make ASC
");
$clients_vehicles->execute([$tid]);
$records = $clients_vehicles->fetchAll();

// Group by client
$clients = [];
foreach ($records as $r) {
    $cid = $r['client_id'];
    if (!isset($clients[$cid])) {
        $clients[$cid] = [
            'client_id' => $r['client_id'],
            'full_name' => $r['full_name'],
            'phone' => $r['phone'],
            'email' => $r['email'],
            'address' => $r['address'],
            'vehicles' => [],
            'total_services' => 0,
            'completed_services' => 0,
            'active_services' => 0,
        ];
    }
    $clients[$cid]['vehicles'][] = $r;
    $clients[$cid]['total_services'] += $r['total_services'];
    $clients[$cid]['completed_services'] += $r['completed_services'];
    $clients[$cid]['active_services'] += $r['active_services'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - VehiCare Technician</title>
    <link rel="stylesheet" href="../includes/style/technician.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="tech-body">
<div class="tech-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="tech-main">
        <?php include __DIR__ . '/includes/topbar.php'; ?>
        <div class="tech-content">
            <div class="page-header">
                <div class="page-header-left">
                    <h1><i class="fas fa-users" style="color:var(--primary);margin-right:10px;"></i> Clients & Vehicles</h1>
                    <p>View client information and vehicle details for your assignments</p>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Total Clients</span>
                        <span class="stat-value"><?= count($clients) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-car"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Vehicles Served</span>
                        <span class="stat-value"><?= count($records) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-wrench"></i></div>
                    <div class="stat-info">
                        <span class="stat-label">Active Services</span>
                        <span class="stat-value"><?= array_sum(array_column($clients, 'active_services')) ?></span>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="tech-card" style="margin-bottom:24px;">
                <div class="table-toolbar">
                    <div class="search-box" style="min-width:300px;">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search clients or vehicles..." id="searchInput" onkeyup="filterClients()">
                    </div>
                </div>
            </div>

            <!-- Client Cards -->
            <?php if (empty($clients)): ?>
                <div class="tech-card"><div class="card-body"><div class="empty-state"><i class="fas fa-users"></i><h3>No clients yet</h3><p>Clients from your assignments will appear here.</p></div></div></div>
            <?php else: ?>
                <div id="clientsContainer">
                <?php foreach ($clients as $c): ?>
                <div class="client-card-wrapper" style="margin-bottom:20px;" data-search="<?= strtolower(htmlspecialchars($c['full_name'] . ' ' . $c['email'] . ' ' . $c['phone'])) ?>">
                    <div class="tech-card">
                        <div class="card-header" style="cursor:pointer;" onclick="toggleClient(<?= $c['client_id'] ?>)">
                            <h3 style="gap:14px;">
                                <div class="client-avatar" style="width:36px;height:36px;font-size:14px;"><?= strtoupper(substr($c['full_name'], 0, 1)) ?></div>
                                <?= htmlspecialchars($c['full_name']) ?>
                                <span class="badge badge-info" style="font-size:11px;"><?= $c['total_services'] ?> services</span>
                                <?php if ($c['active_services'] > 0): ?>
                                    <span class="badge badge-ongoing" style="font-size:11px;"><?= $c['active_services'] ?> active</span>
                                <?php endif; ?>
                            </h3>
                            <i class="fas fa-chevron-down" id="chevron-<?= $c['client_id'] ?>" style="color:var(--tech-text-muted);transition:var(--transition);"></i>
                        </div>
                        <div class="card-body" id="client-details-<?= $c['client_id'] ?>" style="display:none;">
                            <!-- Contact Info -->
                            <div class="client-info-grid" style="margin-bottom:20px;">
                                <div class="client-info-item">
                                    <div class="client-info-label"><i class="fas fa-phone"></i> Phone</div>
                                    <div class="client-info-value"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></div>
                                </div>
                                <div class="client-info-item">
                                    <div class="client-info-label"><i class="fas fa-envelope"></i> Email</div>
                                    <div class="client-info-value"><?= htmlspecialchars($c['email'] ?? 'N/A') ?></div>
                                </div>
                                <div class="client-info-item">
                                    <div class="client-info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                                    <div class="client-info-value"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></div>
                                </div>
                                <div class="client-info-item">
                                    <div class="client-info-label"><i class="fas fa-check-circle"></i> Completed</div>
                                    <div class="client-info-value"><?= $c['completed_services'] ?> / <?= $c['total_services'] ?></div>
                                </div>
                            </div>

                            <!-- Vehicles -->
                            <h4 style="font-size:13px;color:var(--tech-text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">
                                <i class="fas fa-car" style="color:var(--primary)"></i> Vehicles
                            </h4>
                            <?php foreach ($c['vehicles'] as $v): ?>
                            <div class="vehicle-info-card">
                                <div class="vehicle-icon"><i class="fas fa-car"></i></div>
                                <div class="vehicle-details" style="flex:1;">
                                    <div class="vehicle-name"><?= htmlspecialchars(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?></div>
                                    <div class="vehicle-plate">
                                        <i class="fas fa-id-badge"></i> <?= htmlspecialchars($v['plate_number'] ?? 'N/A') ?>
                                        <?= $v['color'] ? '&bull; ' . htmlspecialchars($v['color']) : '' ?>
                                    </div>
                                </div>
                                <div style="text-align:right;">
                                    <span class="badge badge-<?= $v['vehicle_status'] ?>"><?= ucfirst(str_replace('_', ' ', $v['vehicle_status'])) ?></span>
                                    <div style="font-size:12px;color:var(--tech-text-muted);margin-top:4px;"><?= $v['total_services'] ?> service(s)</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="includes/tech.js"></script>
<script>
function toggleClient(id) {
    var details = document.getElementById('client-details-' + id);
    var chevron = document.getElementById('chevron-' + id);
    if (details.style.display === 'none') {
        details.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        details.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
    }
}

function filterClients() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.client-card-wrapper').forEach(function(card) {
        var searchData = card.getAttribute('data-search') || '';
        var content = card.textContent.toLowerCase();
        card.style.display = (searchData.includes(q) || content.includes(q)) ? '' : 'none';
    });
}
</script>
</body>
</html>
