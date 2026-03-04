// ===== Sidebar Toggle =====
document.getElementById('sidebarToggle').addEventListener('click', function() {
    document.querySelector('.tech-layout').classList.toggle('sidebar-collapsed');
});

// ===== Active link highlighting =====
document.querySelectorAll('.sidebar-link').forEach(link => {
    link.addEventListener('click', function() {
        document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
    });
});
