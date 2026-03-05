<!-- Technician Sidebar -->
<aside class="tech-sidebar" id="techSidebar">
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-logo">
            <span class="logo-vehi">Vehi</span><span class="logo-care">Care</span>
        </a>
    </div>

    <div class="sidebar-profile">
        <div class="sidebar-avatar"><i class="fas fa-user-gear"></i></div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Technician') ?></span>
            <span class="sidebar-user-role">Technician</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-section">MENU</div>
        <a href="dashboard.php" class="sidebar-link <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-th-large"></i> <span>Dashboard</span>
        </a>

        <div class="sidebar-section">WORK</div>
        <a href="assignments.php" class="sidebar-link <?= ($current_page ?? '') === 'assignments' ? 'active' : '' ?>">
            <i class="fas fa-tasks"></i> <span>My Assignments</span>
            <?php
                $badge_count = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE technician_id = ? AND status IN ('Assigned','Ongoing')");
                $badge_count->execute([$_SESSION['user_id']]);
                $active_count = $badge_count->fetchColumn();
                if ($active_count > 0): ?>
                <span class="link-badge urgent"><?= $active_count ?></span>
            <?php endif; ?>
        </a>
        <a href="clients.php" class="sidebar-link <?= ($current_page ?? '') === 'clients' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <span>Clients & Vehicles</span>
        </a>

        <div class="sidebar-section">INSIGHTS</div>
        <a href="performance.php" class="sidebar-link <?= ($current_page ?? '') === 'performance' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i> <span>Performance</span>
        </a>

        <div class="sidebar-section">GENERAL</div>
        <a href="notifications.php" class="sidebar-link <?= ($current_page ?? '') === 'notifications' ? 'active' : '' ?>">
            <i class="fas fa-bell"></i> <span>Notifications</span>
            <?php
                $notif_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $notif_count->execute([$_SESSION['user_id']]);
                $unread_notifs = $notif_count->fetchColumn();
                if ($unread_notifs > 0): ?>
                <span class="link-badge urgent"><?= $unread_notifs ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="sidebar-link <?= ($current_page ?? '') === 'profile' ? 'active' : '' ?>">
            <i class="fas fa-user-circle"></i> <span>My Profile</span>
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
