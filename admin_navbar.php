<?php
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if (empty($_SESSION['profile_image']) && class_exists('Database')) {
    $db = Database::getInstance();
    $avatarStmt = $db->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
    $avatarStmt->bind_param('i', $_SESSION['user_id']);
    $avatarStmt->execute();
    $avatarRow = $avatarStmt->get_result()->fetch_assoc();
    $_SESSION['profile_image'] = $avatarRow['profile_image'] ?? '';
}

if (!function_exists('admin_navbar_avatar_url')) {
    function admin_navbar_avatar_url($path) {
        if (empty($path)) {
            return '';
        }
        if (preg_match('/^(https?:)?\/\//i', $path) || strpos($path, 'data:') === 0) {
            return $path;
        }
        if (strpos($path, '../') === 0 || strpos($path, '/') === 0) {
            return $path;
        }
        return '../' . ltrim($path, './');
    }
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$navItems = [
    ['href' => 'index.php', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
    ['href' => 'manage_users.php', 'icon' => 'fa-users', 'label' => 'Users'],
    ['href' => 'manage_meals.php', 'icon' => 'fa-utensils', 'label' => 'Meals'],
    ['href' => 'manage_categories.php', 'icon' => 'fa-list', 'label' => 'Categories'],
    ['href' => 'manage_orders.php', 'icon' => 'fa-cart-shopping', 'label' => 'Orders'],
    ['href' => 'manage_reviews.php', 'icon' => 'fa-star', 'label' => 'Reviews'],
    ['href' => 'notifications.php', 'icon' => 'fa-bell', 'label' => 'Notifications'],
    ['href' => 'report.php', 'icon' => 'fa-chart-line', 'label' => 'Reports'],
    ['href' => 'system_logs.php', 'icon' => 'fa-clock-rotate-left', 'label' => 'Logs'],
    ['href' => 'settings.php#system-settings', 'icon' => 'fa-sliders', 'label' => 'Admin Settings'],
    ['href' => '../logout.php', 'icon' => 'fa-right-from-bracket', 'label' => 'Logout']
];

$uiSettings = (isset($_SESSION['admin_ui']) && is_array($_SESSION['admin_ui'])) ? $_SESSION['admin_ui'] : [];

$position = $uiSettings['nav_position'] ?? 'left-middle';
if (!in_array($position, ['left-middle', 'left-top', 'left-bottom'], true)) {
    $position = 'left-middle';
}

$size = $uiSettings['nav_size'] ?? 'medium';
if (!in_array($size, ['small', 'medium', 'large'], true)) {
    $size = 'medium';
}

$spread = $uiSettings['nav_spread'] ?? 'normal';
if (!in_array($spread, ['compact', 'normal', 'wide'], true)) {
    $spread = 'normal';
}

$accent = $uiSettings['nav_accent'] ?? 'teal';
if (!in_array($accent, ['teal', 'blue', 'orange'], true)) {
    $accent = 'teal';
}

$dashboardColor = $uiSettings['dashboard_color'] ?? '#ff6b35';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $dashboardColor)) {
    $dashboardColor = '#ff6b35';
}

$dashboardBgColor = $uiSettings['dashboard_bg_color'] ?? '#f7fff7';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $dashboardBgColor)) {
    $dashboardBgColor = '#f7fff7';
}

$sizeMap = [
    'small' => ['toggle' => 70, 'link' => 48, 'icon' => 1.45, 'linkIcon' => 1.0, 'mobileScale' => 0.86],
    'medium' => ['toggle' => 84, 'link' => 58, 'icon' => 1.9, 'linkIcon' => 1.15, 'mobileScale' => 0.86],
    'large' => ['toggle' => 98, 'link' => 66, 'icon' => 2.2, 'linkIcon' => 1.25, 'mobileScale' => 0.86],
];
$spreadMultiplier = ['compact' => 0.82, 'normal' => 1.0, 'wide' => 1.2];
$accentMap = [
    'teal' => ['start' => '#0f766e', 'end' => '#0b5f8a', 'solid' => '#0f766e'],
    'blue' => ['start' => '#1d4ed8', 'end' => '#0e7490', 'solid' => '#1d4ed8'],
    'orange' => ['start' => '#ea580c', 'end' => '#c2410c', 'solid' => '#ea580c'],
];

$sizeCfg = $sizeMap[$size];
$baseRadius = (int) round(($sizeCfg['toggle'] * 2.2) * $spreadMultiplier[$spread]);
$mobileScale = $sizeCfg['mobileScale'];

$styleVars = sprintf(
    '--nav-toggle:%dpx;--nav-link:%dpx;--nav-radius:%dpx;--nav-icon:%.2frem;--nav-link-icon:%.2frem;--nav-toggle-mobile:%dpx;--nav-link-mobile:%dpx;--nav-radius-mobile:%dpx;--accent-start:%s;--accent-end:%s;--accent-solid:%s;',
    $sizeCfg['toggle'],
    $sizeCfg['link'],
    $baseRadius,
    $sizeCfg['icon'],
    $sizeCfg['linkIcon'],
    (int) round($sizeCfg['toggle'] * $mobileScale),
    (int) round($sizeCfg['link'] * $mobileScale),
    (int) round($baseRadius * 0.72),
    $accentMap[$accent]['start'],
    $accentMap[$accent]['end'],
    $accentMap[$accent]['solid']
);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<nav class="admin-radial-nav <?php echo htmlspecialchars($position); ?>" id="adminRadialNav" style="<?php echo htmlspecialchars($styleVars); ?>" aria-label="Admin navigation">
    <button class="admin-radial-toggle" id="adminRadialToggle" type="button" aria-expanded="false" aria-controls="adminRadialButtons" title="Open Admin Menu">
        <?php if (!empty($_SESSION['profile_image'])): ?>
            <img src="<?php echo htmlspecialchars(admin_navbar_avatar_url($_SESSION['profile_image'])); ?>" alt="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>">
        <?php else: ?>
            <i class="fas fa-user-shield"></i>
        <?php endif; ?>
    </button>

    <div class="admin-radial-buttons" id="adminRadialButtons">
        <?php foreach ($navItems as $index => $item):
            $isActive = ($currentPage === basename($item['href']));
        ?>
            <a
                class="admin-radial-link <?php echo $isActive ? 'active' : ''; ?>"
                href="<?php echo htmlspecialchars($item['href']); ?>"
                style="--item-index: <?php echo $index; ?>; --total-items: <?php echo count($navItems); ?>;"
                title="<?php echo htmlspecialchars($item['label']); ?>"
                aria-label="<?php echo htmlspecialchars($item['label']); ?>"
            >
                <i class="fas <?php echo htmlspecialchars($item['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<script>
(function () {
    const nav = document.getElementById('adminRadialNav');
    const toggle = document.getElementById('adminRadialToggle');

    if (!nav || !toggle) {
        return;
    }

    function setMenuState(isOpen) {
        nav.classList.toggle('open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        setMenuState(!nav.classList.contains('open'));
    });

    document.addEventListener('click', function (event) {
        if (!nav.contains(event.target)) {
            setMenuState(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setMenuState(false);
        }
    });
})();
</script>

<style>
.admin-sidebar {
    display: none !important;
}

.admin-main {
    margin-left: 0 !important;
    width: 100%;
}

:root {
    --dashboard-main: <?php echo htmlspecialchars($dashboardColor, ENT_QUOTES, 'UTF-8'); ?>;
    --primary-color: <?php echo htmlspecialchars($dashboardColor, ENT_QUOTES, 'UTF-8'); ?>;
    --dashboard-bg: <?php echo htmlspecialchars($dashboardBgColor, ENT_QUOTES, 'UTF-8'); ?>;
    --admin-font-color: <?php echo htmlspecialchars($dashboardBgColor, ENT_QUOTES, 'UTF-8'); ?>;
}

body {
    background-color: var(--dashboard-bg, #f7fff7);
}

.admin-container {
    background-color: var(--dashboard-bg, #f7fff7) !important;
}

.admin-card,
.admin-header,
.admin-filters,
.stat-card,
.stat-box,
.table-responsive,
.modal-content {
    background-color: var(--dashboard-main, #ff6b35) !important;
}

.admin-main,
.admin-main h1,
.admin-main h2,
.admin-main h3,
.admin-main h4,
.admin-main h5,
.admin-main h6,
.admin-main .stat-info h3,
.admin-main .card-header h3,
.admin-main .page-description,
.admin-main .table-responsive th,
.admin-main .user-details strong {
    color: var(--admin-font-color, #ff6b35);
}

.admin-card .card-header {
    border-bottom: 2px solid rgba(0, 0, 0, 0.06);
}

.admin-header h1 i,
.admin-card h3 i {
    color: var(--dashboard-main, #ff6b35);
}

.btn-primary {
    background-color: var(--dashboard-main, #ff6b35);
}

.btn-secondary {
    color: var(--dashboard-main, #ff6b35);
    border-color: var(--dashboard-main, #ff6b35);
}

.admin-radial-nav {
    position: fixed;
    left: 1.2rem;
    width: var(--nav-toggle);
    height: var(--nav-toggle);
    z-index: 1300;
}

.admin-radial-nav.left-middle {
    top: 50%;
    transform: translateY(-50%);
}

.admin-radial-nav.left-top {
    top: 1.2rem;
}

.admin-radial-nav.left-bottom {
    bottom: 1.2rem;
}

.admin-radial-toggle {
    width: var(--nav-toggle);
    height: var(--nav-toggle);
    border-radius: 50%;
    border: 0;
    cursor: pointer;
    background: linear-gradient(145deg, var(--accent-start) 0%, var(--accent-end) 100%);
    color: #fff;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.30);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    overflow: hidden;
    position: relative;
    z-index: 2;
}

.admin-radial-toggle:hover {
    transform: scale(1.06);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.40);
}

.admin-radial-toggle i {
    font-size: var(--nav-icon);
}

.admin-radial-toggle img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.admin-radial-buttons {
    position: absolute;
    top: calc(var(--nav-toggle) / 2);
    left: calc(var(--nav-toggle) / 2);
    width: 0;
    height: 0;
    pointer-events: none;
}

.admin-radial-link {
    --radius: var(--nav-radius);
    --angle: calc((360deg / var(--total-items)) * var(--item-index));
    position: absolute;
    top: 0;
    left: 0;
    transform: translate(-50%, -50%) rotate(var(--angle)) translate(var(--radius)) rotate(calc(-1 * var(--angle))) scale(0.65);
    opacity: 0;
    width: var(--nav-link);
    height: var(--nav-link);
    border-radius: 50%;
    text-decoration: none;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #dbe4ea;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 16px rgba(15, 23, 42, 0.14);
    transition: transform 0.28s ease, opacity 0.2s ease, background 0.22s ease, color 0.22s ease;
}

.admin-radial-link i {
    font-size: var(--nav-link-icon);
}

.admin-radial-link span {
    position: absolute;
    left: calc(100% + 8px);
    top: 50%;
    transform: translateY(-50%);
    background: #0f172a;
    color: #fff;
    font-size: 0.72rem;
    border-radius: 5px;
    padding: 0.2rem 0.45rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s ease;
}

.admin-radial-link:hover,
.admin-radial-link:focus-visible,
.admin-radial-link.active {
    background: var(--accent-solid);
    color: #fff;
    transform: translate(-50%, -50%) rotate(var(--angle)) translate(var(--radius)) rotate(calc(-1 * var(--angle))) scale(1.06);
}

.admin-radial-link:hover span,
.admin-radial-link:focus-visible span {
    opacity: 1;
}

.admin-radial-nav.open .admin-radial-buttons {
    pointer-events: auto;
}

.admin-radial-nav.open .admin-radial-link {
    opacity: 1;
    transform: translate(-50%, -50%) rotate(var(--angle)) translate(var(--radius)) rotate(calc(-1 * var(--angle))) scale(1);
}

@media (max-width: 900px) {
    .admin-radial-nav {
        left: 0.9rem;
        width: var(--nav-toggle-mobile);
        height: var(--nav-toggle-mobile);
    }

    .admin-radial-nav.left-middle {
        top: 50%;
        transform: translateY(-50%);
    }

    .admin-radial-nav.left-top {
        top: 0.9rem;
    }

    .admin-radial-nav.left-bottom {
        bottom: 0.9rem;
    }

    .admin-radial-toggle {
        width: var(--nav-toggle-mobile);
        height: var(--nav-toggle-mobile);
    }

    .admin-radial-buttons {
        top: calc(var(--nav-toggle-mobile) / 2);
        left: calc(var(--nav-toggle-mobile) / 2);
    }

    .admin-radial-link {
        --radius: var(--nav-radius-mobile);
        width: var(--nav-link-mobile);
        height: var(--nav-link-mobile);
    }
}
</style>
