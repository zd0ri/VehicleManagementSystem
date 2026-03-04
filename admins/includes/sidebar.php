<!-- Admin Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-logo">
            <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>

        <div class="sidebar-section">BOOKINGS</div>
        <a href="appointments.php" class="sidebar-link <?= ($current_page ?? '') === 'appointments' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i> <span>Appointments</span>
        </a>
        <a href="walkin.php" class="sidebar-link <?= ($current_page ?? '') === 'walkin' ? 'active' : '' ?>">
            <i class="fas fa-walking"></i> <span>Walk-In Bookings</span>
        </a>

        <div class="sidebar-section">MANAGEMENT</div>
        <a href="clients.php" class="sidebar-link <?= ($current_page ?? '') === 'clients' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <span>Clients</span>
        </a>
        <a href="vehicles.php" class="sidebar-link <?= ($current_page ?? '') === 'vehicles' ? 'active' : '' ?>">
            <i class="fas fa-car"></i> <span>Vehicles</span>
        </a>
        <a href="technicians.php" class="sidebar-link <?= ($current_page ?? '') === 'technicians' ? 'active' : '' ?>">
            <i class="fas fa-tools"></i> <span>Technicians</span>
        </a>
        <a href="assignments.php" class="sidebar-link <?= ($current_page ?? '') === 'assignments' ? 'active' : '' ?>">
            <i class="fas fa-tasks"></i> <span>Assignments</span>
        </a>

        <div class="sidebar-section">OPERATIONS</div>
        <a href="queue.php" class="sidebar-link <?= ($current_page ?? '') === 'queue' ? 'active' : '' ?>">
            <i class="fas fa-list-ol"></i> <span>Queue</span>
        </a>
        <a href="inventory.php" class="sidebar-link <?= ($current_page ?? '') === 'inventory' ? 'active' : '' ?>">
            <i class="fas fa-boxes-stacked"></i> <span>Inventory</span>
        </a>
        <a href="services.php" class="sidebar-link <?= ($current_page ?? '') === 'services' ? 'active' : '' ?>">
            <i class="fas fa-wrench"></i> <span>Services</span>
        </a>

        <div class="sidebar-section">FINANCIAL</div>
        <a href="payments.php" class="sidebar-link <?= ($current_page ?? '') === 'payments' ? 'active' : '' ?>">
            <i class="fas fa-credit-card"></i> <span>Payments</span>
        </a>
        <a href="invoices.php" class="sidebar-link <?= ($current_page ?? '') === 'invoices' ? 'active' : '' ?>">
            <i class="fas fa-file-invoice-dollar"></i> <span>Invoices</span>
        </a>

        <div class="sidebar-section">REPORTS & SYSTEM</div>
        <a href="ratings.php" class="sidebar-link <?= ($current_page ?? '') === 'ratings' ? 'active' : '' ?>">
            <i class="fas fa-star"></i> <span>Ratings</span>
        </a>
        <a href="notifications.php" class="sidebar-link <?= ($current_page ?? '') === 'notifications' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> <span>Notifications</span>
        </a>
        <a href="audit_logs.php" class="sidebar-link <?= ($current_page ?? '') === 'audit_logs' ? 'active' : '' ?>">
            <i class="fas fa-history"></i> <span>Audit Logs</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../index.php" class="sidebar-link sidebar-shop" target="_blank">
            <i class="fas fa-store"></i> <span>View Shop</span>
        </a>
        <a href="../users/logout.php" class="sidebar-link sidebar-logout">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>
</aside>
