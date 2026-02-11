<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$auth->requireAdmin();

$allowedPositions = ['left-middle', 'left-top', 'left-bottom'];
$allowedSizes = ['small', 'medium', 'large'];
$allowedSpreads = ['compact', 'normal', 'wide'];
$allowedAccents = ['teal', 'blue', 'orange'];
$allowedLandingPages = ['index.php', 'manage_orders.php', 'manage_users.php', 'manage_categories.php'];
$allowedRefreshRates = [15, 30, 60, 120];

if (!isset($_SESSION['admin_ui']) || !is_array($_SESSION['admin_ui'])) {
    $_SESSION['admin_ui'] = [];
}
if (!isset($_SESSION['admin_system']) || !is_array($_SESSION['admin_system'])) {
    $_SESSION['admin_system'] = [];
}

$currentUi = $_SESSION['admin_ui'];
$currentSystem = $_SESSION['admin_system'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? 'all';

    if ($formType === 'all' || $formType === 'system') {
        $landingPage = $_POST['landing_page'] ?? ($currentSystem['landing_page'] ?? 'index.php');
        $refreshSeconds = (int) ($_POST['refresh_seconds'] ?? ($currentSystem['refresh_seconds'] ?? 60));
        $showDashboardStats = isset($_POST['show_dashboard_stats']) ? 1 : 0;
        $enableUnreadBadge = isset($_POST['enable_unread_badge']) ? 1 : 0;
        $enableEmailAlerts = isset($_POST['enable_email_alerts']) ? 1 : 0;

        $_SESSION['admin_system']['landing_page'] = in_array($landingPage, $allowedLandingPages, true) ? $landingPage : 'index.php';
        $_SESSION['admin_system']['refresh_seconds'] = in_array($refreshSeconds, $allowedRefreshRates, true) ? $refreshSeconds : 60;
        $_SESSION['admin_system']['show_dashboard_stats'] = $showDashboardStats;
        $_SESSION['admin_system']['enable_unread_badge'] = $enableUnreadBadge;
        $_SESSION['admin_system']['enable_email_alerts'] = $enableEmailAlerts;
    }

    if ($formType === 'all' || $formType === 'appearance') {
        $position = $_POST['nav_position'] ?? ($currentUi['nav_position'] ?? 'left-middle');
        $size = $_POST['nav_size'] ?? ($currentUi['nav_size'] ?? 'medium');
        $spread = $_POST['nav_spread'] ?? ($currentUi['nav_spread'] ?? 'normal');
        $accent = $_POST['nav_accent'] ?? ($currentUi['nav_accent'] ?? 'teal');
        $dashboardColor = $_POST['dashboard_color'] ?? ($currentUi['dashboard_color'] ?? '#ff6b35');
        $dashboardBgColor = $_POST['dashboard_bg_color'] ?? ($currentUi['dashboard_bg_color'] ?? '#f7fff7');

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $dashboardColor)) {
            $dashboardColor = '#ff6b35';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $dashboardBgColor)) {
            $dashboardBgColor = '#f7fff7';
        }

        $_SESSION['admin_ui']['nav_position'] = in_array($position, $allowedPositions, true) ? $position : 'left-middle';
        $_SESSION['admin_ui']['nav_size'] = in_array($size, $allowedSizes, true) ? $size : 'medium';
        $_SESSION['admin_ui']['nav_spread'] = in_array($spread, $allowedSpreads, true) ? $spread : 'normal';
        $_SESSION['admin_ui']['nav_accent'] = in_array($accent, $allowedAccents, true) ? $accent : 'teal';
        $_SESSION['admin_ui']['dashboard_color'] = $dashboardColor;
        $_SESSION['admin_ui']['dashboard_bg_color'] = $dashboardBgColor;
    }

    $message = 'Settings saved successfully.';
}

$currentUi = $_SESSION['admin_ui'];
$currentSystem = $_SESSION['admin_system'];
$currentPosition = $currentUi['nav_position'] ?? 'left-middle';
$currentSize = $currentUi['nav_size'] ?? 'medium';
$currentSpread = $currentUi['nav_spread'] ?? 'normal';
$currentAccent = $currentUi['nav_accent'] ?? 'teal';
$currentDashboardColor = $currentUi['dashboard_color'] ?? '#ff6b35';
$currentDashboardBgColor = $currentUi['dashboard_bg_color'] ?? '#f7fff7';
$currentLandingPage = $currentSystem['landing_page'] ?? 'index.php';
$currentRefreshSeconds = (int) ($currentSystem['refresh_seconds'] ?? 60);
$showDashboardStats = (int) ($currentSystem['show_dashboard_stats'] ?? 1) === 1;
$enableUnreadBadge = (int) ($currentSystem['enable_unread_badge'] ?? 1) === 1;
$enableEmailAlerts = (int) ($currentSystem['enable_email_alerts'] ?? 0) === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Settings - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <main class="admin-main">
            <header class="admin-header">
                <h1><i class="fas fa-sliders-h"></i> Admin Settings</h1>
            </header>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="admin-card" id="system-settings">
                <div class="card-header">
                    <h3><i class="fas fa-screwdriver-wrench"></i> Dashboard & System Settings</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="settings-form-grid">
                        <input type="hidden" name="form_type" value="system">
                        <div class="form-group">
                            <label for="landing_page">Default Admin Start Page</label>
                            <select id="landing_page" name="landing_page" class="form-control">
                                <option value="index.php" <?php echo $currentLandingPage === 'index.php' ? 'selected' : ''; ?>>Dashboard</option>
                                <option value="manage_orders.php" <?php echo $currentLandingPage === 'manage_orders.php' ? 'selected' : ''; ?>>Orders</option>
                                <option value="manage_users.php" <?php echo $currentLandingPage === 'manage_users.php' ? 'selected' : ''; ?>>Users</option>
                                <option value="manage_categories.php" <?php echo $currentLandingPage === 'manage_categories.php' ? 'selected' : ''; ?>>Categories</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="refresh_seconds">Dashboard Auto Refresh</label>
                            <select id="refresh_seconds" name="refresh_seconds" class="form-control">
                                <option value="15" <?php echo $currentRefreshSeconds === 15 ? 'selected' : ''; ?>>15 seconds</option>
                                <option value="30" <?php echo $currentRefreshSeconds === 30 ? 'selected' : ''; ?>>30 seconds</option>
                                <option value="60" <?php echo $currentRefreshSeconds === 60 ? 'selected' : ''; ?>>60 seconds</option>
                                <option value="120" <?php echo $currentRefreshSeconds === 120 ? 'selected' : ''; ?>>120 seconds</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_dashboard_stats" value="1" <?php echo $showDashboardStats ? 'checked' : ''; ?>>
                                <span>Show dashboard stats cards</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="enable_unread_badge" value="1" <?php echo $enableUnreadBadge ? 'checked' : ''; ?>>
                                <span>Enable unread notification badges</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="enable_email_alerts" value="1" <?php echo $enableEmailAlerts ? 'checked' : ''; ?>>
                                <span>Enable email alerts (system flag)</span>
                            </label>
                        </div>

                        <div class="settings-actions">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-save"></i> Save System Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-header">
                    <h3><i class="fas fa-palette"></i> Appearance</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="settings-form-grid">
                        <input type="hidden" name="form_type" value="appearance">
                        <div class="form-group">
                            <label for="nav_position">Radial Menu Position</label>
                            <select id="nav_position" name="nav_position" class="form-control">
                                <option value="left-middle" <?php echo $currentPosition === 'left-middle' ? 'selected' : ''; ?>>Middle Left</option>
                                <option value="left-top" <?php echo $currentPosition === 'left-top' ? 'selected' : ''; ?>>Top Left</option>
                                <option value="left-bottom" <?php echo $currentPosition === 'left-bottom' ? 'selected' : ''; ?>>Bottom Left</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="nav_size">Button Size</label>
                            <select id="nav_size" name="nav_size" class="form-control">
                                <option value="small" <?php echo $currentSize === 'small' ? 'selected' : ''; ?>>Small</option>
                                <option value="medium" <?php echo $currentSize === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="large" <?php echo $currentSize === 'large' ? 'selected' : ''; ?>>Large</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="nav_spread">Menu Spread</label>
                            <select id="nav_spread" name="nav_spread" class="form-control">
                                <option value="compact" <?php echo $currentSpread === 'compact' ? 'selected' : ''; ?>>Compact</option>
                                <option value="normal" <?php echo $currentSpread === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="wide" <?php echo $currentSpread === 'wide' ? 'selected' : ''; ?>>Wide</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="nav_accent">Color Theme</label>
                            <select id="nav_accent" name="nav_accent" class="form-control">
                                <option value="teal" <?php echo $currentAccent === 'teal' ? 'selected' : ''; ?>>Teal</option>
                                <option value="blue" <?php echo $currentAccent === 'blue' ? 'selected' : ''; ?>>Blue</option>
                                <option value="orange" <?php echo $currentAccent === 'orange' ? 'selected' : ''; ?>>Orange</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dashboard_color">Dashboard Main Color</label>
                            <input type="color" id="dashboard_color" name="dashboard_color" class="form-control" value="<?php echo htmlspecialchars($currentDashboardColor); ?>">
                        </div>

                        <div class="form-group">
                            <label for="dashboard_bg_color">Dashboard Background Color</label>
                            <input type="color" id="dashboard_bg_color" name="dashboard_bg_color" class="form-control" value="<?php echo htmlspecialchars($currentDashboardBgColor); ?>">
                        </div>

                        <div class="settings-actions">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-save"></i> Save Appearance
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-header">
                    <h3><i class="fas fa-circle-info"></i> Notes</h3>
                </div>
                <div class="card-body">
                    <p>Action buttons for View, Edit, and Delete are enabled in admin management pages. Delete still keeps safety checks where records are linked to other data.</p>
                </div>
            </div>
        </main>
    </div>
    <?php include 'includes/admin_footer.php'; ?>

    <style>
        .settings-form-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }

        .settings-actions {
            display: flex;
            align-items: end;
        }
    </style>
</body>
</html>
