<!-- Admin Top Bar -->
<header class="admin-topbar">
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
                <span class="notif-badge">0</span>
            </button>
        </div>
        <div class="topbar-user">
            <div class="user-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
                <span class="user-role"><?= ucfirst($_SESSION['role'] ?? 'admin') ?></span>
            </div>
        </div>
    </div>
</header>
