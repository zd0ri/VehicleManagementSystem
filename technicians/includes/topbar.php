<!-- Technician Top Bar -->
<header class="tech-topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-search">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search...">
        </div>
    </div>
    <div class="topbar-right">
        <div class="topbar-date">
            <i class="fas fa-calendar-alt"></i>
            <span><?= date('D, d M Y') ?></span>
        </div>
        <div class="topbar-notifications">
            <button class="topbar-icon-btn">
                <i class="fas fa-bell"></i>
                <?php
                    $nstmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                    $nstmt->execute([$_SESSION['user_id']]);
                    $notif_count = $nstmt->fetchColumn();
                ?>
                <span class="notif-badge"><?= $notif_count ?></span>
            </button>
        </div>
        <div class="topbar-user">
            <div class="user-avatar"><i class="fas fa-user-gear"></i></div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Technician') ?></span>
                <span class="user-role">Technician</span>
            </div>
        </div>
    </div>
</header>
