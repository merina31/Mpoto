<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Get filter parameters
$category_id = $_GET['category'] ?? 0;
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'popular';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Load categories
$db = Database::getInstance();
$categories = [];

$stmt = $db->prepare("SELECT c.id, c.name, COUNT(m.id) as meal_count 
                      FROM categories c 
                      LEFT JOIN meals m ON c.id = m.category_id AND m.is_available = 1 
                      WHERE c.is_active = 1 
                      GROUP BY c.id 
                      ORDER BY c.name");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

// Load meals with filters
$where = "WHERE m.is_available = 1";
$params = [];
$types = "";

if ($category_id > 0) {
    $where .= " AND m.category_id = ?";
    $params[] = $category_id;
    $types .= "i";
}

if (!empty($search)) {
    $where .= " AND (m.name LIKE ? OR m.description LIKE ? OR m.ingredients LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    $types .= "sss";
}

// Order by
$orderBy = "ORDER BY ";
switch ($sort) {
    case 'price_asc':
        $orderBy .= "COALESCE(m.discount_price, m.price) ASC";
        break;
    case 'price_desc':
        $orderBy .= "COALESCE(m.discount_price, m.price) DESC";
        break;
    case 'name':
        $orderBy .= "m.name ASC";
        break;
    case 'newest':
        $orderBy .= "m.created_at DESC";
        break;
    default: // popular
        $orderBy .= "m.total_orders DESC, m.rating DESC";
        break;
}

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM meals m {$where}";
$countStmt = $db->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Get meals
$sql = "SELECT m.*, c.name as category_name 
        FROM meals m 
        LEFT JOIN categories c ON m.category_id = c.id 
        {$where} 
        {$orderBy} 
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$meals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Menu - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Navigation -->
    <?php include '../includes/navbar.php'; ?>

    <!-- Menu Page -->
    <div class="menu-container container">
        <!-- Hero Section -->
        <div class="menu-hero">
            <h1>Our Delicious Menu</h1>
            <p>Discover a wide variety of dishes prepared with love and fresh ingredients</p>
        </div>

        <!-- Filters and Search -->
        <div class="menu-filters">
            <div class="filter-group">
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="search-box">
                        <input type="text" 
                               name="search" 
                               placeholder="Search for meals..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    
                    <div class="filter-controls">
                        <select name="category" onchange="this.form.submit()">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                        <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['meal_count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="sort" onchange="this.form.submit()">
                            <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                            <option value="price_asc" <?php echo $sort == 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_desc" <?php echo $sort == 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="name" <?php echo $sort == 'name' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        </select>
                        
                        <button type="button" class="btn-filter-toggle" onclick="toggleFilterSidebar()">
                            <i class="fas fa-filter"></i> More Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="filter-tags">
                <?php if ($category_id > 0): ?>
                    <?php 
                    $selectedCat = array_filter($categories, fn($cat) => $cat['id'] == $category_id);
                    $selectedCat = array_values($selectedCat);
                    if (!empty($selectedCat)): 
                    ?>
                        <span class="filter-tag">
                            Category: <?php echo htmlspecialchars($selectedCat[0]['name']); ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['category' => 0])); ?>">
                                <i class="fas fa-times"></i>
                            </a>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (!empty($search)): ?>
                    <span class="filter-tag">
                        Search: "<?php echo htmlspecialchars($search); ?>"
                        <a href="?<?php echo http_build_query(array_diff_key($_GET, ['search' => ''])); ?>">
                            <i class="fas fa-times"></i>
                        </a>
                    </span>
                <?php endif; ?>
                
                <?php if ($category_id > 0 || !empty($search)): ?>
                    <a href="../api/menu.php" class="clear-filters">Clear All Filters</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Sidebar (Mobile) -->
        <div class="filter-sidebar" id="filterSidebar">
            <div class="sidebar-header">
                <h3>Filters</h3>
                <button type="button" onclick="toggleFilterSidebar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="sidebar-content">
                <div class="filter-section">
                    <h4>Categories</h4>
                    <div class="category-list">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['category' => 0, 'page' => 1])); ?>" 
                           class="category-item <?php echo $category_id == 0 ? 'active' : ''; ?>">
                            <span>All Categories</span>
                            <span><?php echo $totalRows; ?></span>
                        </a>
                        <?php foreach ($categories as $cat): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['category' => $cat['id'], 'page' => 1])); ?>" 
                               class="category-item <?php echo $category_id == $cat['id'] ? 'active' : ''; ?>">
                                <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                <span><?php echo $cat['meal_count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="filter-section">
                    <h4>Dietary</h4>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="vegetarian" id="vegetarianFilter">
                            <span>Vegetarian</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="spicy" id="spicyFilter">
                            <span>Spicy</span>
                        </label>
                    </div>
                </div>
                
                <div class="filter-section">
                    <h4>Price Range</h4>
                    <div class="price-range">
                        <input type="range" min="0" max="50" value="50" class="price-slider" id="priceSlider">
                        <div class="price-labels">
                            <span><?php echo format_currency(0); ?></span>
                            <span><?php echo format_currency(50); ?>+</span>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="btn btn-primary btn-block" onclick="applyFilters()">
                    Apply Filters
                </button>
            </div>
        </div>

        <!-- Menu Grid -->
        <div class="menu-content">
            <?php if (empty($meals)): ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <h3>No meals found</h3>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="menu.php" class="btn btn-primary">Clear Filters</a>
                </div>
            <?php else: ?>
                <div class="meals-grid">
                    <?php foreach ($meals as $meal): ?>
                        <div class="meal-card">
                            <div class="meal-image">
                                <img src="<?php echo htmlspecialchars($meal['image_url'] ?: 'assets/images/default-food.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($meal['name']); ?>"
                                     loading="lazy">
                                
                                <?php if ($meal['discount_price']): ?>
                                    <span class="discount-badge">
                                        Save <?php echo format_currency($meal['price'] - $meal['discount_price']); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($meal['is_vegetarian']): ?>
                                    <span class="veg-badge" title="Vegetarian">
                                        <i class="fas fa-leaf"></i>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($meal['is_spicy']): ?>
                                    <span class="spicy-badge" title="Spicy">
                                        <i class="fas fa-pepper-hot"></i>
                                    </span>
                                <?php endif; ?>
                                
                                <button class="btn-quick-view" onclick="showMealDetails(<?php echo $meal['id']; ?>)">
                                    <i class="fas fa-eye"></i> Quick View
                                </button>
                            </div>
                            
                            <div class="meal-info">
                                <div class="meal-header">
                                    <h3><?php echo htmlspecialchars($meal['name']); ?></h3>
                                    <span class="meal-category"><?php echo htmlspecialchars($meal['category_name']); ?></span>
                                </div>
                                
                                <p class="meal-description">
                                    <?php echo htmlspecialchars(substr($meal['description'], 0, 80)); ?>...
                                </p>
                                
                                <div class="meal-footer">
                                    <div class="price-section">
                                        <?php if ($meal['discount_price']): ?>
                                            <span class="original-price"><?php echo format_currency($meal['price']); ?></span>
                                            <span class="current-price"><?php echo format_currency($meal['discount_price']); ?></span>
                                        <?php else: ?>
                                            <span class="current-price"><?php echo format_currency($meal['price']); ?></span>
                                        <?php endif; ?>
                                        
                                        <div class="meal-rating">
                                            <i class="fas fa-star"></i>
                                            <span><?php echo number_format($meal['rating'], 1); ?></span>
                                            <small>(<?php echo $meal['total_orders']; ?> orders)</small>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" action="api/cart.php" class="add-to-cart-form">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="meal_id" value="<?php echo $meal['id']; ?>">
                                        <button type="button" class="btn-add-cart" onclick="addToCart(<?php echo $meal['id']; ?>)">
                                            <i class="fas fa-plus"></i> Add to Cart
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <div class="page-numbers">
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Categories Section -->
        <div class="categories-section">
            <h2>Browse by Category</h2>
            <div class="categories-grid">
                <?php foreach ($categories as $cat): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['category' => $cat['id'], 'page' => 1])); ?>" 
                       class="category-card <?php echo $category_id == $cat['id'] ? 'active' : ''; ?>">
                        <div class="category-icon">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <h3><?php echo htmlspecialchars($cat['name']); ?></h3>
                        <p><?php echo $cat['meal_count']; ?> items</p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Meal Details Modal -->
    <div class="modal" id="mealModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="mealModalTitle"></h2>
                <button type="button" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="mealModalBody">
                <!-- Content loaded via JavaScript -->
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>

    <!-- WhatsApp Float -->
    <a href="https://wa.me/<?php echo WHATSAPP_NUMBER; ?>" class="whatsapp-float" target="_blank">
        <i class="fab fa-whatsapp"></i>
    </a>

    <script>
        function formatCurrencyTZS(value) {
            return `TSh ${Number(value || 0).toLocaleString('en-TZ', { maximumFractionDigits: 0 })}`;
        }

        // Toggle filter sidebar on mobile
        function toggleFilterSidebar() {
            const sidebar = document.getElementById('filterSidebar');
            sidebar.classList.toggle('active');
        }
        
        // Apply filters from sidebar
        function applyFilters() {
            const form = document.getElementById('filterForm');
            const vegetarian = document.getElementById('vegetarianFilter').checked;
            const spicy = document.getElementById('spicyFilter').checked;
            const maxPrice = document.getElementById('priceSlider').value;
            
            // Add hidden inputs for filter values
            if (vegetarian) {
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'vegetarian';
                input.value = '1';
                form.appendChild(input);
            }
            
            if (spicy) {
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'spicy';
                input.value = '1';
                form.appendChild(input);
            }
            
            if (maxPrice < 50) {
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'max_price';
                input.value = maxPrice;
                form.appendChild(input);
            }
            
            form.submit();
        }
        
        // Show meal details modal
        async function showMealDetails(mealId) {
            try {
                const response = await fetch(`api/meals.php?action=get_details&meal_id=${mealId}`);
                const data = await response.json();
                
                if (data.success) {
                    const meal = data.meal;
                    const modal = document.getElementById('mealModal');
                    const modalTitle = document.getElementById('mealModalTitle');
                    const modalBody = document.getElementById('mealModalBody');
                    
                    modalTitle.textContent = meal.name;
                    
                    modalBody.innerHTML = `
                        <div class="meal-modal-content">
                            <div class="meal-modal-image">
                                <img src="${meal.image_url || 'assets/images/default-food.jpg'}" alt="${meal.name}">
                            </div>
                            
                            <div class="meal-modal-info">
                                <div class="meal-modal-header">
                                    <div>
                                        <span class="category">${meal.category_name}</span>
                                        <div class="meal-tags">
                                            ${meal.is_vegetarian ? '<span class="tag vegetarian"><i class="fas fa-leaf"></i> Vegetarian</span>' : ''}
                                            ${meal.is_spicy ? '<span class="tag spicy"><i class="fas fa-pepper-hot"></i> Spicy</span>' : ''}
                                        </div>
                                    </div>
                                    <div class="meal-modal-price">
                                        ${meal.discount_price ? 
                                            `<span class="original-price">${formatCurrencyTZS(meal.price)}</span>
                                             <span class="current-price">${formatCurrencyTZS(meal.discount_price)}</span>` :
                                            `<span class="current-price">${formatCurrencyTZS(meal.price)}</span>`
                                        }
                                    </div>
                                </div>
                                
                                <div class="meal-modal-description">
                                    <h4>Description</h4>
                                    <p>${meal.description}</p>
                                </div>
                                
                                <div class="meal-modal-ingredients">
                                    <h4>Ingredients</h4>
                                    <p>${meal.ingredients || 'No ingredients listed'}</p>
                                </div>
                                
                                <div class="meal-modal-details">
                                    <div class="detail">
                                        <i class="fas fa-clock"></i>
                                        <div>
                                            <small>Preparation Time</small>
                                            <strong>${meal.preparation_time} minutes</strong>
                                        </div>
                                    </div>
                                    <div class="detail">
                                        <i class="fas fa-star"></i>
                                        <div>
                                            <small>Rating</small>
                                            <strong>${meal.rating}/5 (${meal.total_orders} orders)</strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="meal-modal-actions">
                                    <form method="POST" action="api/cart.php" class="add-to-cart-form-modal">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="meal_id" value="${meal.id}">
                                        <div class="quantity-selector">
                                            <label>Quantity:</label>
                                            <div class="quantity-control">
                                                <button type="button" onclick="updateModalQuantity(-1)">-</button>
                                                <input type="number" id="modalQuantity" value="1" min="1" max="10">
                                                <button type="button" onclick="updateModalQuantity(1)">+</button>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-block" onclick="addToCart(${meal.id}, document.getElementById('modalQuantity').value)">
                                            <i class="fas fa-plus"></i> Add to Cart - ${formatCurrencyTZS(meal.discount_price || meal.price)}
                                        </button>
                                    </form>
                                </div>
                                
                                ${meal.reviews && meal.reviews.length > 0 ? `
                                    <div class="meal-modal-reviews">
                                        <h4>Customer Reviews</h4>
                                        ${meal.reviews.slice(0, 3).map(review => `
                                            <div class="review">
                                                <div class="review-header">
                                                    <strong>${review.full_name || review.username}</strong>
                                                    <div class="review-rating">
                                                        ${'★'.repeat(review.rating)}${'☆'.repeat(5-review.rating)}
                                                    </div>
                                                </div>
                                                <p class="review-comment">${review.comment}</p>
                                                <small class="review-date">${new Date(review.created_at).toLocaleDateString()}</small>
                                            </div>
                                        `).join('')}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                    
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                }
            } catch (error) {
                console.error('Error loading meal details:', error);
            }
        }
        
        // Close modal
        function closeModal() {
            const modal = document.getElementById('mealModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Update quantity in modal
        function updateModalQuantity(change) {
            const input = document.getElementById('modalQuantity');
            let value = parseInt(input.value) + change;
            if (value < 1) value = 1;
            if (value > 10) value = 10;
            input.value = value;
        }
        
        // Add to cart function
        function addToCart(mealId, quantity = 1) {
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('meal_id', mealId);
            formData.append('quantity', quantity);
            
            fetch('api/cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Item added to cart!', 'success');
                    
                    // Update cart count
                    const cartCount = document.querySelector('.cart-count');
                    if (cartCount) {
                        cartCount.textContent = parseInt(data.cart_count, 10) || 0;
                    }

                    // Redirect user to cart after a short confirmation delay
                    setTimeout(() => {
                        window.location.href = '../cart.php';
                    }, 700);
                } else {
                    showNotification(data.message || 'Failed to add item to cart', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        }

        // Prevent native form submit to api/cart.php (which shows raw JSON in browser)
        document.addEventListener('submit', function(e) {
            if (!e.target.matches('.add-to-cart-form, .add-to-cart-form-modal')) {
                return;
            }

            e.preventDefault();
            const mealId = parseInt(e.target.querySelector('input[name="meal_id"]')?.value || '0', 10);
            const qtyInput = e.target.querySelector('#modalQuantity');
            const quantity = parseInt(qtyInput?.value || '1', 10);

            if (mealId > 0) {
                addToCart(mealId, quantity > 0 ? quantity : 1);
            }
        });
        
        // Show notification
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('mealModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Search with enter key
        document.querySelector('input[name="search"]').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('filterForm').submit();
            }
        });
        
        // Initialize price slider
        const priceSlider = document.getElementById('priceSlider');
        if (priceSlider) {
            priceSlider.addEventListener('input', function() {
                const value = this.value;
                const priceLabel = this.parentElement.querySelector('.price-labels span:last-child');
                priceLabel.textContent = `${formatCurrencyTZS(value)}+`;
            });
        }
    </script>
    
    <style>
        .menu-container {
            padding: 2rem 0;
        }
        
        .menu-hero {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: var(--radius);
            color: white;
        }
        
        .menu-hero h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: white;
        }
        
        .menu-filters {
            background-color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .search-box {
            display: flex;
            margin-bottom: 1rem;
        }
        
        .search-box input {
            flex: 1;
            padding: 0.8rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius) 0 0 var(--radius);
            font-size: 1rem;
        }
        
        .search-box button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0 1.5rem;
            border-radius: 0 var(--radius) var(--radius) 0;
            cursor: pointer;
        }
        
        .filter-controls {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .filter-controls select {
            padding: 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            background-color: white;
            min-width: 200px;
        }
        
        .btn-filter-toggle {
            background-color: var(--light-color);
            border: 1px solid var(--border-color);
            padding: 0.8rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            display: none;
        }
        
        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .filter-tag {
            background-color: var(--light-color);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-tag a {
            color: var(--text-light);
            text-decoration: none;
        }
        
        .clear-filters {
            color: var(--primary-color);
            text-decoration: none;
            align-self: center;
            margin-left: 1rem;
        }
        
        .filter-sidebar {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100%;
            background-color: white;
            box-shadow: var(--shadow-hover);
            transition: left 0.3s ease;
            z-index: 1001;
            overflow-y: auto;
        }
        
        .filter-sidebar.active {
            left: 0;
        }
        
        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .sidebar-content {
            padding: 1.5rem;
        }
        
        .filter-section {
            margin-bottom: 2rem;
        }
        
        .filter-section h4 {
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .category-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .category-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--text-color);
            transition: background-color 0.2s;
        }
        
        .category-item:hover,
        .category-item.active {
            background-color: var(--light-color);
            color: var(--primary-color);
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }
        
        .price-range {
            padding: 0.5rem 0;
        }
        
        .price-slider {
            width: 100%;
            margin: 1rem 0;
        }
        
        .price-labels {
            display: flex;
            justify-content: space-between;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .menu-content {
            margin-bottom: 3rem;
        }
        
        .no-results {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .no-results i {
            font-size: 4rem;
            color: #e0e0e0;
            margin-bottom: 1rem;
        }
        
        .meal-card {
            position: relative;
            background-color: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .meal-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }
        
        .meal-image {
            position: relative;
            height: 200px;
            overflow: hidden;
        }
        
        .meal-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .meal-card:hover .meal-image img {
            transform: scale(1.05);
        }
        
        .discount-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: var(--primary-color);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .veg-badge,
        .spicy-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .veg-badge {
            color: #4CAF50;
        }
        
        .spicy-badge {
            color: #F44336;
        }
        
        .btn-quick-view {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: rgba(0,0,0,0.8);
            color: white;
            border: none;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            opacity: 0;
            transform: translateY(100%);
            transition: all 0.3s ease;
        }
        
        .meal-card:hover .btn-quick-view {
            opacity: 1;
            transform: translateY(0);
        }
        
        .meal-info {
            padding: 1.5rem;
        }
        
        .meal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.8rem;
        }
        
        .meal-header h3 {
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
            flex: 1;
        }
        
        .meal-category {
            background-color: var(--light-color);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            color: var(--text-light);
        }
        
        .meal-description {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        
        .meal-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .price-section {
            display: flex;
            flex-direction: column;
        }
        
        .original-price {
            text-decoration: line-through;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .current-price {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .meal-rating {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 0.3rem;
        }
        
        .meal-rating i {
            color: #FFC107;
        }
        
        .btn-add-cart {
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        
        .btn-add-cart:hover {
            background-color: #3bb5ad;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 3rem;
        }
        
        .page-link {
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            color: var(--text-color);
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .page-link:hover,
        .page-link.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-numbers {
            display: flex;
            gap: 0.5rem;
        }
        
        .categories-section {
            margin-top: 4rem;
        }
        
        .categories-section h2 {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .category-card {
            background-color: white;
            padding: 2rem 1.5rem;
            border-radius: var(--radius);
            text-align: center;
            text-decoration: none;
            color: var(--text-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .category-card:hover,
        .category-card.active {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-5px);
        }
        
        .category-card.active .category-icon,
        .category-card:hover .category-icon {
            background-color: rgba(255,255,255,0.2);
        }
        
        .category-icon {
            width: 60px;
            height: 60px;
            background-color: var(--light-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .category-card.active .category-icon,
        .category-card:hover .category-icon {
            color: white;
        }
        
        .category-card h3 {
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .category-card p {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            z-index: 1002;
            overflow-y: auto;
        }
        
        .modal-content {
            background-color: white;
            margin: 2rem auto;
            width: 90%;
            max-width: 800px;
            border-radius: var(--radius);
            overflow: hidden;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header button {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .meal-modal-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }
        
        .meal-modal-image {
            border-radius: var(--radius);
            overflow: hidden;
            height: 300px;
        }
        
        .meal-modal-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .meal-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        
        .meal-tags {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .tag {
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .tag.vegetarian {
            background-color: #E8F5E9;
            color: #2E7D32;
        }
        
        .tag.spicy {
            background-color: #FFEBEE;
            color: #C62828;
        }
        
        .meal-modal-price {
            text-align: right;
        }
        
        .meal-modal-price .original-price {
            display: block;
            text-decoration: line-through;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .meal-modal-price .current-price {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .meal-modal-description,
        .meal-modal-ingredients {
            margin-bottom: 1.5rem;
        }
        
        .meal-modal-description h4,
        .meal-modal-ingredients h4 {
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .meal-modal-description p,
        .meal-modal-ingredients p {
            color: var(--text-light);
            line-height: 1.6;
        }
        
        .meal-modal-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem;
            background-color: var(--light-color);
            border-radius: var(--radius);
        }
        
        .detail {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .detail i {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .detail small {
            display: block;
            color: var(--text-light);
            font-size: 0.8rem;
        }
        
        .detail strong {
            display: block;
            color: var(--text-color);
        }
        
        .quantity-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quantity-control button {
            width: 30px;
            height: 30px;
            border: 1px solid var(--border-color);
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .quantity-control input {
            width: 50px;
            text-align: center;
            padding: 5px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        
        .meal-modal-reviews {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }
        
        .review {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .review:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .review-rating {
            color: #FFC107;
        }
        
        .review-comment {
            color: var(--text-color);
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }
        
        .review-date {
            color: var(--text-light);
            font-size: 0.8rem;
        }
        
        @media (max-width: 992px) {
            .meal-modal-content {
                grid-template-columns: 1fr;
            }
            
            .meal-modal-image {
                height: 200px;
            }
            
            .btn-filter-toggle {
                display: inline-block;
            }
        }
        
        @media (max-width: 768px) {
            .menu-hero h1 {
                font-size: 2rem;
            }
            
            .filter-controls {
                flex-direction: column;
            }
            
            .filter-controls select {
                min-width: 100%;
            }
            
            .filter-sidebar {
                width: 100%;
                left: -100%;
            }
            
            .categories-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem auto;
            }
        }
        
        @media (max-width: 576px) {
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .meal-footer {
                flex-direction: column;
                gap: 1rem;
            }
            
            .btn-add-cart {
                width: 100%;
            }
            
            .pagination {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .page-numbers {
                order: 2;
            }
        }
    </style>
</body>
</html>
