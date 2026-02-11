<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

$auth->requireAdmin();
$db = Database::getInstance();

$message = '';
// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $meal_id = intval($_POST['meal_id'] ?? 0);
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please select an image to upload.';
    } else {
        $tmp = $_FILES['image']['tmp_name'];
        $orig = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif'];
        if (!in_array($ext, $allowed)) {
            $message = 'Invalid image type.';
        } else {
            $targetDir = realpath(__DIR__ . '/../assets/images') ?: (__DIR__ . '/../assets/images');
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $filename = 'meal_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            $dest = $targetDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($tmp, $dest)) {
                $imageUrl = 'assets/images/' . $filename;
                if ($meal_id > 0) {
                    $stmt = $db->prepare("UPDATE meals SET image_url = ? WHERE id = ?");
                    $stmt->bind_param('si', $imageUrl, $meal_id);
                    $stmt->execute();
                    $message = 'Image uploaded and meal updated.';
                } else {
                    $message = 'Image uploaded to ' . htmlspecialchars($imageUrl);
                }
            } else {
                $message = 'Failed to move uploaded file.';
            }
        }
    }
}

// Fetch meals for selection
$mealsStmt = $db->prepare("SELECT id, name FROM meals ORDER BY name");
$mealsStmt->execute();
$meals = $mealsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload Meal Image - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
</head>
<body>
    <?php include 'includes/admin_navbar.php'; ?>
    <div class="admin-container">
        <main class="admin-main">
            <h1>Upload Meal Image</h1>
            <?php if ($message): ?>
                <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="meal_id">Select Meal (optional)</label>
                    <select name="meal_id" id="meal_id" class="form-control">
                        <option value="0">-- Just upload file --</option>
                        <?php foreach ($meals as $m): ?>
                            <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="image">Image File</label>
                    <input type="file" name="image" id="image" accept="image/*" required>
                </div>
                <button class="btn btn-primary">Upload</button>
            </form>
        </main>
    </div>
    <?php include 'includes/admin_footer.php'; ?>
</body>
</html>
