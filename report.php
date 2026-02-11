<?php

session_start();
require_once '../includes/config.php';
require_once '../includes/db_connect.php';
require_once '../includes/auth_functions.php';

// Require admin authentication
$auth->requireAdmin();

$db = Database::getInstance();

// Handle report generation and export
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_type = $_POST['report_type'] ?? 'sales_summary';
    $start_date = $_POST['start_date'] ?? date('Y-m-01');
    $end_date = $_POST['end_date'] ?? date('Y-m-d');
    $format = $_POST['export_format'] ?? 'html';
    
    // Generate report data
    $report_data = generateReportData($report_type, $start_date, $end_date);
    
    if ($format === 'pdf') {
        exportAsPDF($report_type, $report_data, $start_date, $end_date);
        exit();
    } elseif ($format === 'csv') {
        exportAsCSV($report_type, $report_data, $start_date, $end_date);
        exit();
    } elseif ($format === 'excel') {
        exportAsExcel($report_type, $report_data, $start_date, $end_date);
        exit();
    }
}

// Handle filter requests
$report_type = $_GET['type'] ?? 'sales_summary';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$category_id = $_GET['category_id'] ?? 'all';
$user_id = $_GET['user_id'] ?? 'all';

// Generate report data for display
$report_data = generateReportData($report_type, $start_date, $end_date, $category_id, $user_id);

// Get categories for filters
$categories = [];
$cat_stmt = $db->prepare("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
$cat_stmt->execute();
$cat_result = $cat_stmt->get_result();
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get users for filters
$users = [];
$user_stmt = $db->prepare("SELECT id, username, full_name FROM users WHERE role = 'user' ORDER BY full_name");
$user_stmt->execute();
$user_result = $user_stmt->get_result();
while ($row = $user_result->fetch_assoc()) {
    $users[] = $row;
}

// Function to generate report data
function generateReportData($type, $start_date, $end_date, $category_id = 'all', $user_id = 'all') {
    global $db;
    $data = [];
    
    switch ($type) {
        case 'sales_summary':
            $query = "SELECT 
                        DATE(o.created_at) as date,
                        COUNT(o.id) as order_count,
                        SUM(o.final_amount) as total_revenue,
                        AVG(o.final_amount) as avg_order_value
                      FROM orders o
                      WHERE o.status = 'delivered' 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                      GROUP BY DATE(o.created_at)
                      ORDER BY date DESC";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $data['daily_sales'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Calculate summary
            $summary = [
                'total_orders' => 0,
                'total_revenue' => 0,
                'avg_order_value' => 0
            ];
            foreach ($data['daily_sales'] as $sale) {
                $summary['total_orders'] += $sale['order_count'];
                $summary['total_revenue'] += $sale['total_revenue'];
            }
            if ($summary['total_orders'] > 0) {
                $summary['avg_order_value'] = $summary['total_revenue'] / $summary['total_orders'];
            }
            $data['summary'] = $summary;
            break;
            
        case 'product_performance':
            $query = "SELECT 
                        m.id,
                        m.name,
                        c.name as category_name,
                        SUM(oi.quantity) as total_sold,
                        SUM(oi.total_price) as total_revenue,
                        COUNT(DISTINCT o.id) as order_count,
                        AVG(r.rating) as avg_rating
                      FROM meals m
                      LEFT JOIN categories c ON m.category_id = c.id
                      LEFT JOIN order_items oi ON m.id = oi.meal_id
                      LEFT JOIN orders o ON oi.order_id = o.id
                      LEFT JOIN reviews r ON m.id = r.meal_id
                      WHERE o.status = 'delivered' 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                      ";
            
            if ($category_id !== 'all') {
                $query .= " AND m.category_id = ? ";
            }
            
            $query .= " GROUP BY m.id
                        ORDER BY total_revenue DESC
                        LIMIT 50";
            
            $stmt = $db->prepare($query);
            if ($category_id !== 'all') {
                $stmt->bind_param("ssi", $start_date, $end_date, $category_id);
            } else {
                $stmt->bind_param("ss", $start_date, $end_date);
            }
            $stmt->execute();
            $data['products'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'customer_analysis':
            $query = "SELECT 
                        u.id,
                        u.username,
                        u.full_name,
                        u.email,
                        COUNT(o.id) as total_orders,
                        SUM(o.final_amount) as total_spent,
                        MIN(o.created_at) as first_order,
                        MAX(o.created_at) as last_order
                      FROM users u
                      LEFT JOIN orders o ON u.id = o.user_id
                      WHERE u.role = 'user' 
                        AND (o.created_at IS NULL OR (DATE(o.created_at) BETWEEN ? AND ?))
                      GROUP BY u.id
                      HAVING total_orders > 0
                      ORDER BY total_spent DESC
                      LIMIT 50";
            
            if ($user_id !== 'all') {
                $query = "SELECT 
                            u.id,
                            u.username,
                            u.full_name,
                            u.email,
                            COUNT(o.id) as total_orders,
                            SUM(o.final_amount) as total_spent,
                            MIN(o.created_at) as first_order,
                            MAX(o.created_at) as last_order
                          FROM users u
                          LEFT JOIN orders o ON u.id = o.user_id
                          WHERE u.id = ?
                            AND (o.created_at IS NULL OR (DATE(o.created_at) BETWEEN ? AND ?))
                          GROUP BY u.id
                          ORDER BY total_spent DESC";
            }
            
            $stmt = $db->prepare($query);
            if ($user_id !== 'all') {
                $stmt->bind_param("iss", $user_id, $start_date, $end_date);
            } else {
                $stmt->bind_param("ss", $start_date, $end_date);
            }
            $stmt->execute();
            $data['customers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'order_analysis':
            $query = "SELECT 
                        o.*,
                        u.full_name,
                        u.username,
                        COUNT(oi.id) as item_count,
                        TIME_TO_SEC(TIMEDIFF(o.updated_at, o.created_at)) as processing_time
                      FROM orders o
                      LEFT JOIN users u ON o.user_id = u.id
                      LEFT JOIN order_items oi ON o.id = oi.order_id
                      WHERE DATE(o.created_at) BETWEEN ? AND ?
                      GROUP BY o.id
                      ORDER BY o.created_at DESC
                      LIMIT 100";
            
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $data['orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Calculate status distribution
            $status_query = "SELECT 
                                status,
                                COUNT(*) as count,
                                SUM(final_amount) as revenue
                             FROM orders
                             WHERE DATE(created_at) BETWEEN ? AND ?
                             GROUP BY status";
            $status_stmt = $db->prepare($status_query);
            $status_stmt->bind_param("ss", $start_date, $end_date);
            $status_stmt->execute();
            $data['status_distribution'] = $status_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'revenue_by_category':
            $query = "SELECT 
                        c.id,
                        c.name as category_name,
                        COUNT(DISTINCT o.id) as order_count,
                        SUM(oi.quantity) as items_sold,
                        SUM(oi.total_price) as total_revenue,
                        AVG(oi.unit_price) as avg_item_price
                      FROM categories c
                      LEFT JOIN meals m ON c.id = m.category_id
                      LEFT JOIN order_items oi ON m.id = oi.meal_id
                      LEFT JOIN orders o ON oi.order_id = o.id
                      WHERE o.status = 'delivered' 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                      GROUP BY c.id
                      HAVING total_revenue > 0
                      ORDER BY total_revenue DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $data['categories'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'best_sellers':
            $query = "SELECT 
                        m.id,
                        m.name,
                        m.image_url,
                        c.name as category_name,
                        SUM(oi.quantity) as total_sold,
                        SUM(oi.total_price) as total_revenue,
                        COUNT(DISTINCT o.id) as order_count
                      FROM meals m
                      LEFT JOIN categories c ON m.category_id = c.id
                      LEFT JOIN order_items oi ON m.id = oi.meal_id
                      LEFT JOIN orders o ON oi.order_id = o.id
                      WHERE o.status = 'delivered' 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                      GROUP BY m.id
                      ORDER BY total_sold DESC
                      LIMIT 20";
            
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $data['best_sellers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'top_rated':
            $query = "SELECT 
                        m.id,
                        m.name,
                        m.image_url,
                        c.name as category_name,
                        AVG(r.rating) as avg_rating,
                        COUNT(r.id) as review_count,
                        SUM(oi.quantity) as total_sold
                      FROM meals m
                      LEFT JOIN categories c ON m.category_id = c.id
                      LEFT JOIN reviews r ON m.id = r.meal_id
                      LEFT JOIN order_items oi ON m.id = oi.meal_id
                      WHERE r.is_approved = 1
                        AND DATE(r.created_at) BETWEEN ? AND ?
                      GROUP BY m.id
                      HAVING review_count >= 3
                      ORDER BY avg_rating DESC
                      LIMIT 20";
            
            $stmt = $db->prepare($query);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $data['top_rated'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
    }
    
    return $data;
}

// Function to export as PDF
function exportAsPDF($report_type, $data, $start_date, $end_date) {
    require_once('../includes/tcpdf/tcpdf.php');
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor(SITE_NAME);
    $pdf->SetTitle($report_type . ' Report');
    $pdf->SetSubject('Business Report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Report title
    $title = strtoupper(str_replace('_', ' ', $report_type)) . ' REPORT';
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Period: ' . date('F d, Y', strtotime($start_date)) . ' to ' . date('F d, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 5, 'Generated: ' . date('F d, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Generate report content based on type
    switch ($report_type) {
        case 'sales_summary':
            generateSalesSummaryPDF($pdf, $data, $start_date, $end_date);
            break;
        case 'product_performance':
            generateProductPerformancePDF($pdf, $data, $start_date, $end_date);
            break;
        case 'customer_analysis':
            generateCustomerAnalysisPDF($pdf, $data, $start_date, $end_date);
            break;
        case 'order_analysis':
            generateOrderAnalysisPDF($pdf, $data, $start_date, $end_date);
            break;
        case 'revenue_by_category':
            generateRevenueByCategoryPDF($pdf, $data, $start_date, $end_date);
            break;
        case 'best_sellers':
            generateBestSellersPDF($pdf, $data, $start_date, $end_date);
            break;
        case 'top_rated':
            generateTopRatedPDF($pdf, $data, $start_date, $end_date);
            break;
    }
    
    // Add footer
    $pdf->SetY(-25);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Report generated by ' . SITE_NAME . ' Admin System', 0, 0, 'C');
    $pdf->Ln();
    $pdf->Cell(0, 5, 'Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Output PDF
    $filename = $report_type . '_report_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');
}

// Helper functions for PDF generation
function generateSalesSummaryPDF($pdf, $data, $start_date, $end_date) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Sales Summary', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    // Summary statistics
    if (isset($data['summary'])) {
        $summary = $data['summary'];
        $pdf->Cell(0, 6, 'Total Orders: ' . $summary['total_orders'], 0, 1);
        $pdf->Cell(0, 6, 'Total Revenue: $' . number_format($summary['total_revenue'], 2), 0, 1);
        $pdf->Cell(0, 6, 'Average Order Value: $' . number_format($summary['avg_order_value'], 2), 0, 1);
        $pdf->Ln(5);
    }
    
    // Daily sales table
    if (isset($data['daily_sales']) && count($data['daily_sales']) > 0) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Daily Sales Details', 0, 1);
        $pdf->SetFont('helvetica', 'B', 9);
        
        // Table header
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(40, 6, 'Date', 1, 0, 'C', 1);
        $pdf->Cell(40, 6, 'Orders', 1, 0, 'C', 1);
        $pdf->Cell(50, 6, 'Revenue', 1, 0, 'C', 1);
        $pdf->Cell(40, 6, 'Avg Order Value', 1, 1, 'C', 1);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(245, 245, 245);
        $fill = false;
        
        foreach ($data['daily_sales'] as $sale) {
            $pdf->Cell(40, 6, date('M d, Y', strtotime($sale['date'])), 1, 0, 'L', $fill);
            $pdf->Cell(40, 6, $sale['order_count'], 1, 0, 'C', $fill);
            $pdf->Cell(50, 6, '$' . number_format($sale['total_revenue'], 2), 1, 0, 'R', $fill);
            $pdf->Cell(40, 6, '$' . number_format($sale['avg_order_value'], 2), 1, 1, 'R', $fill);
            $fill = !$fill;
        }
    }
}

function generateProductPerformancePDF($pdf, $data, $start_date, $end_date) {
    if (isset($data['products']) && count($data['products']) > 0) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Product Performance Report', 0, 1);
        $pdf->SetFont('helvetica', 'B', 9);
        
        // Table header
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(60, 6, 'Product Name', 1, 0, 'L', 1);
        $pdf->Cell(40, 6, 'Category', 1, 0, 'C', 1);
        $pdf->Cell(30, 6, 'Units Sold', 1, 0, 'C', 1);
        $pdf->Cell(40, 6, 'Revenue', 1, 0, 'C', 1);
        $pdf->Cell(20, 6, 'Rating', 1, 1, 'C', 1);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(245, 245, 245);
        $fill = false;
        
        $total_revenue = 0;
        $total_units = 0;
        
        foreach ($data['products'] as $product) {
            $pdf->Cell(60, 6, substr($product['name'], 0, 30), 1, 0, 'L', $fill);
            $pdf->Cell(40, 6, substr($product['category_name'], 0, 15), 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, $product['total_sold'] ?? 0, 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, '$' . number_format($product['total_revenue'] ?? 0, 2), 1, 0, 'R', $fill);
            $pdf->Cell(20, 6, number_format($product['avg_rating'] ?? 0, 1), 1, 1, 'C', $fill);
            
            $total_revenue += $product['total_revenue'] ?? 0;
            $total_units += $product['total_sold'] ?? 0;
            $fill = !$fill;
        }
        
        // Summary
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Total Units Sold: ' . $total_units, 0, 1);
        $pdf->Cell(0, 6, 'Total Revenue: $' . number_format($total_revenue, 2), 0, 1);
    }
}

// Similar functions for other report types would be implemented...

function exportAsCSV($report_type, $data, $start_date, $end_date) {
    $filename = $report_type . '_report_' . date('Ymd_His') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers based on report type
    switch ($report_type) {
        case 'sales_summary':
            fputcsv($output, ['Date', 'Orders', 'Revenue', 'Average Order Value']);
            foreach ($data['daily_sales'] as $sale) {
                fputcsv($output, [
                    $sale['date'],
                    $sale['order_count'],
                    $sale['total_revenue'],
                    $sale['avg_order_value']
                ]);
            }
            break;
        case 'product_performance':
            fputcsv($output, ['Product ID', 'Product Name', 'Category', 'Units Sold', 'Revenue', 'Orders', 'Rating']);
            foreach ($data['products'] as $product) {
                fputcsv($output, [
                    $product['id'],
                    $product['name'],
                    $product['category_name'],
                    $product['total_sold'] ?? 0,
                    $product['total_revenue'] ?? 0,
                    $product['order_count'] ?? 0,
                    $product['avg_rating'] ?? 0
                ]);
            }
            break;
    }
    
    fclose($output);
    exit();
}

function exportAsExcel($report_type, $data, $start_date, $end_date) {
    require_once('../includes/PHPExcel/PHPExcel.php');
    
    $objPHPExcel = new PHP Excel();
    $objPHPExcel->getProperties()->setTitle($report_type . " Report")
                                 ->setSubject("Business Report")
                                 ->setDescription("Report generated by " . SITE_NAME);
    
    $sheet = $objPHPExcel->getActiveSheet();
    $sheet->setTitle('Report');
    
    // Add data based on report type
    switch ($report_type) {
        case 'sales_summary':
            $sheet->setCellValue('A1', 'Date');
            $sheet->setCellValue('B1', 'Orders');
            $sheet->setCellValue('C1', 'Revenue');
            $sheet->setCellValue('D1', 'Average Order Value');
            
            $row = 2;
            foreach ($data['daily_sales'] as $sale) {
                $sheet->setCellValue('A' . $row, $sale['date']);
                $sheet->setCellValue('B' . $row, $sale['order_count']);
                $sheet->setCellValue('C' . $row, $sale['total_revenue']);
                $sheet->setCellValue('D' . $row, $sale['avg_order_value']);
                $row++;
            }
            break;
    }
    
    $filename = $report_type . '_report_' . date('Ymd_His') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
    $objWriter->save('php://output');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include '../admin/includes/admin_navbar.php'; ?>
    
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chart-bar"></i> Reports</h2>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li class="<?php echo $report_type === 'sales_summary' ? 'active' : ''; ?>">
                        <a href="?type=sales_summary&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-chart-line"></i> Sales Summary
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'product_performance' ? 'active' : ''; ?>">
                        <a href="?type=product_performance&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-box"></i> Product Performance
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'customer_analysis' ? 'active' : ''; ?>">
                        <a href="?type=customer_analysis&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-users"></i> Customer Analysis
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'order_analysis' ? 'active' : ''; ?>">
                        <a href="?type=order_analysis&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-shopping-cart"></i> Order Analysis
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'revenue_by_category' ? 'active' : ''; ?>">
                        <a href="?type=revenue_by_category&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-tags"></i> Revenue by Category
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'best_sellers' ? 'active' : ''; ?>">
                        <a href="?type=best_sellers&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-fire"></i> Best Sellers
                        </a>
                    </li>
                    <li class="<?php echo $report_type === 'top_rated' ? 'active' : ''; ?>">
                        <a href="?type=top_rated&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-star"></i> Top Rated
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-chart-pie"></i> 
                        <?php echo ucwords(str_replace('_', ' ', $report_type)); ?> Report
                    </h1>
                    <p class="page-description">
                        Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
                    </p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" onclick="showExportModal()">
                        <i class="fas fa-file-export"></i> Export Report
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="printReport()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </header>

            <!-- Filters -->
            <div class="admin-filters">
                <form method="GET" class="filter-form" id="reportForm">
                    <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Start Date</label>
                            <input type="date" 
                                   name="start_date" 
                                   value="<?php echo $start_date; ?>"
                                   max="<?php echo date('Y-m-d'); ?>"
                                   required>
                        </div>
                        
                        <div class="filter-group">
                            <label>End Date</label>
                            <input type="date" 
                                   name="end_date" 
                                   value="<?php echo $end_date; ?>"
                                   max="<?php echo date('Y-m-d'); ?>"
                                   required>
                        </div>
                        
                        <?php if ($report_type === 'product_performance' || $report_type === 'revenue_by_category'): ?>
                        <div class="filter-group">
                            <label>Category</label>
                            <select name="category_id">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($report_type === 'customer_analysis'): ?>
                        <div class="filter-group">
                            <label>Customer</label>
                            <select name="user_id">
                                <option value="all">All Customers</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" 
                                            <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        
                        <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        
                        <button type="button" class="btn btn-info" onclick="setDateRange('today')">
                            Today
                        </button>
                        <button type="button" class="btn btn-info" onclick="setDateRange('week')">
                            This Week
                        </button>
                        <button type="button" class="btn btn-info" onclick="setDateRange('month')">
                            This Month
                        </button>
                        <button type="button" class="btn btn-info" onclick="setDateRange('year')">
                            This Year
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Content -->
            <div class="report-container" id="reportContent">
                <?php if ($report_type === 'sales_summary'): ?>
                    <div class="report-section">
                        <div class="report-header">
                            <h3><i class="fas fa-chart-line"></i> Sales Overview</h3>
                            <div class="report-summary">
                                <div class="summary-card">
                                    <div class="summary-icon" style="background-color: #4CAF50;">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <div class="summary-info">
                                        <h4><?php echo $report_data['summary']['total_orders'] ?? 0; ?></h4>
                                        <p>Total Orders</p>
                                    </div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-icon" style="background-color: #2196F3;">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                    <div class="summary-info">
                                        <h4>$<?php echo number_format($report_data['summary']['total_revenue'] ?? 0, 2); ?></h4>
                                        <p>Total Revenue</p>
                                    </div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-icon" style="background-color: #FF9800;">
                                        <i class="fas fa-chart-bar"></i>
                                    </div>
                                    <div class="summary-info">
                                        <h4>$<?php echo number_format($report_data['summary']['avg_order_value'] ?? 0, 2); ?></h4>
                                        <p>Avg Order Value</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="report-chart">
                            <canvas id="salesChart"></canvas>
                        </div>
                        
                        <div class="report-table">
                            <h4>Daily Sales Details</h4>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Orders</th>
                                        <th>Revenue</th>
                                        <th>Avg Order Value</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['daily_sales'] as $sale): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($sale['date'])); ?></td>
                                        <td><?php echo $sale['order_count']; ?></td>
                                        <td>$<?php echo number_format($sale['total_revenue'], 2); ?></td>
                                        <td>$<?php echo number_format($sale['avg_order_value'], 2); ?></td>
                                        <td>
                                            <?php if ($sale['avg_order_value'] > ($report_data['summary']['avg_order_value'] ?? 0)): ?>
                                                <span class="trend-up"><i class="fas fa-arrow-up"></i> Above Avg</span>
                                            <?php else: ?>
                                                <span class="trend-down"><i class="fas fa-arrow-down"></i> Below Avg</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'product_performance'): ?>
                    <div class="report-section">
                        <div class="report-header">
                            <h3><i class="fas fa-box"></i> Product Performance Analysis</h3>
                            <p>Top 50 performing products by revenue</p>
                        </div>
                        
                        <div class="report-table">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Units Sold</th>
                                        <th>Revenue</th>
                                        <th>Orders</th>
                                        <th>Rating</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['products'] as $product): ?>
                                    <tr>
                                        <td>
                                            <div class="product-info">
                                                <?php if (!empty($product['image_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($product['image_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['name']); ?>">
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($product['name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                        <td><?php echo $product['total_sold'] ?? 0; ?></td>
                                        <td>$<?php echo number_format($product['total_revenue'] ?? 0, 2); ?></td>
                                        <td><?php echo $product['order_count'] ?? 0; ?></td>
                                        <td>
                                            <div class="rating">
                                                <?php
                                                $rating = $product['avg_rating'] ?? 0;
                                                for ($i = 1; $i <= 5; $i++):
                                                    if ($i <= floor($rating)): ?>
                                                        <i class="fas fa-star"></i>
                                                    <?php elseif ($i == floor($rating) + 1 && $rating - floor($rating) >= 0.5): ?>
                                                        <i class="fas fa-star-half-alt"></i>
                                                    <?php else: ?>
                                                        <i class="far fa-star"></i>
                                                    <?php endif;
                                                endfor; ?>
                                                <small>(<?php echo number_format($rating, 1); ?>)</small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $revenue = $product['total_revenue'] ?? 0;
                                            if ($revenue > 1000): ?>
                                                <span class="performance-high"><i class="fas fa-rocket"></i> High</span>
                                            <?php elseif ($revenue > 500): ?>
                                                <span class="performance-medium"><i class="fas fa-chart-line"></i> Medium</span>
                                            <?php else: ?>
                                                <span class="performance-low"><i class="fas fa-chart-line"></i> Low</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'customer_analysis'): ?>
                    <div class="report-section">
                        <div class="report-header">
                            <h3><i class="fas fa-users"></i> Customer Analysis</h3>
                            <p>Customer spending and order patterns</p>
                        </div>
                        
                        <div class="report-table">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Email</th>
                                        <th>Total Orders</th>
                                        <th>Total Spent</th>
                                        <th>First Order</th>
                                        <th>Last Order</th>
                                        <th>Customer Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['customers'] as $customer): ?>
                                    <tr>
                                        <td>
                                            <div class="customer-info">
                                                <strong><?php echo htmlspecialchars($customer['full_name']); ?></strong>
                                                <small>@<?php echo htmlspecialchars($customer['username']); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                        <td><?php echo $customer['total_orders'] ?? 0; ?></td>
                                        <td>$<?php echo number_format($customer['total_spent'] ?? 0, 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($customer['first_order'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($customer['last_order'])); ?></td>
                                        <td>
                                            <?php 
                                            $spent = $customer['total_spent'] ?? 0;
                                            if ($spent > 1000): ?>
                                                <span class="customer-premium"><i class="fas fa-crown"></i> Premium</span>
                                            <?php elseif ($spent > 500): ?>
                                                <span class="customer-regular"><i class="fas fa-user-check"></i> Regular</span>
                                            <?php else: ?>
                                                <span class="customer-new"><i class="fas fa-user-clock"></i> New</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                <?php elseif ($report_type === 'best_sellers'): ?>
                    <div class="report-section">
                        <div class="report-header">
                            <h3><i class="fas fa-fire"></i> Best Selling Products</h3>
                            <p>Top 20 products by units sold</p>
                        </div>
                        
                        <div class="report-chart">
                            <canvas id="bestSellersChart"></canvas>
                        </div>
                        
                        <div class="report-table">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Units Sold</th>
                                        <th>Revenue</th>
                                        <th>Orders</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rank = 1; ?>
                                    <?php foreach ($report_data['best_sellers'] as $product): ?>
                                    <tr>
                                        <td>
                                            <span class="rank-badge rank-<?php echo $rank; ?>">
                                                <?php echo $rank++; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="product-info">
                                                <?php if (!empty($product['image_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($product['image_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['name']); ?>">
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($product['name']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                        <td><?php echo $product['total_sold'] ?? 0; ?></td>
                                        <td>$<?php echo number_format($product['total_revenue'] ?? 0, 2); ?></td>
                                        <td><?php echo $product['order_count'] ?? 0; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                <?php endif; ?>
            </div>

            <!-- Report Insights -->
            <div class="report-insights">
                <h3><i class="fas fa-lightbulb"></i> Key Insights</h3>
                <div class="insights-grid">
                    <div class="insight-card">
                        <div class="insight-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="insight-content">
                            <h4>Best Performing Day</h4>
                            <p>
                                <?php 
                                $best_day = null;
                                $best_revenue = 0;
                                if (isset($report_data['daily_sales'])) {
                                    foreach ($report_data['daily_sales'] as $sale) {
                                        if ($sale['total_revenue'] > $best_revenue) {
                                            $best_revenue = $sale['total_revenue'];
                                            $best_day = $sale['date'];
                                        }
                                    }
                                }
                                echo $best_day ? date('l, F d, Y', strtotime($best_day)) . ' ($' . number_format($best_revenue, 2) . ')' : 'No data';
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="insight-card">
                        <div class="insight-icon">
                            <i class="fas fa-trending-up"></i>
                        </div>
                        <div class="insight-content">
                            <h4>Growth Rate</h4>
                            <p>
                                <?php
                                $growth = 'N/A';
                                if (isset($report_data['daily_sales']) && count($report_data['daily_sales']) >= 2) {
                                    $first = $report_data['daily_sales'][count($report_data['daily_sales'])-1]['total_revenue'];
                                    $last = $report_data['daily_sales'][0]['total_revenue'];
                                    if ($first > 0) {
                                        $growth = (($last - $first) / $first) * 100;
                                        echo number_format($growth, 1) . '% ' . ($growth >= 0 ? 'increase' : 'decrease');
                                    }
                                } else {
                                    echo $growth;
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="insight-card">
                        <div class="insight-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="insight-content">
                            <h4>Recommendation</h4>
                            <p>
                                <?php
                                if (isset($report_data['summary']) && $report_data['summary']['total_orders'] > 50) {
                                    echo "Consider running a promotion to boost weekday sales";
                                } else {
                                    echo "Focus on customer acquisition to increase order volume";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Export Modal -->
    <div class="modal" id="exportModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-export"></i> Export Report</h2>
                <button type="button" onclick="closeExportModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="exportForm">
                    <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                    <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                    <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                    
                    <div class="form-group">
                        <label>Export Format</label>
                        <div class="format-options">
                            <label class="format-option">
                                <input type="radio" name="export_format" value="pdf" checked>
                                <div class="format-card">
                                    <i class="fas fa-file-pdf"></i>
                                    <span>PDF Document</span>
                                    <small>Best for printing and sharing</small>
                                </div>
                            </label>
                            
                            <label class="format-option">
                                <input type="radio" name="export_format" value="excel">
                                <div class="format-card">
                                    <i class="fas fa-file-excel"></i>
                                    <span>Excel Spreadsheet</span>
                                    <small>For data analysis</small>
                                </div>
                            </label>
                            
                            <label class="format-option">
                                <input type="radio" name="export_format" value="csv">
                                <div class="format-card">
                                    <i class="fas fa-file-csv"></i>
                                    <span>CSV File</span>
                                    <small>Simple data export</small>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="export_name">Report Name (Optional)</label>
                        <input type="text" 
                               id="export_name" 
                               name="export_name" 
                               class="form-control"
                               placeholder="<?php echo ucwords(str_replace('_', ' ', $report_type)); ?> Report">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeExportModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../admin/includes/admin_footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('.data-table').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [],
                dom: '<"top"lf>rt<"bottom"ip><"clear">',
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
            
            // Initialize charts if needed
            initializeCharts();
        });

        // Show export modal
        function showExportModal() {
            document.getElementById('exportModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close export modal
        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Print report
        function printReport() {
            window.print();
        }

        // Set date range
        function setDateRange(range) {
            const today = new Date();
            let startDate = new Date();
            let endDate = new Date();
            
            switch (range) {
                case 'today':
                    startDate = today;
                    endDate = today;
                    break;
                case 'week':
                    startDate.setDate(today.getDate() - 7);
                    break;
                case 'month':
                    startDate.setMonth(today.getMonth() - 1);
                    break;
                case 'year':
                    startDate.setFullYear(today.getFullYear() - 1);
                    break;
            }
            
            // Format dates as YYYY-MM-DD
            const formatDate = (date) => {
                return date.toISOString().split('T')[0];
            };
            
            document.querySelector('input[name="start_date"]').value = formatDate(startDate);
            document.querySelector('input[name="end_date"]').value = formatDate(endDate);
            
            // Submit form
            document.getElementById('reportForm').submit();
        }

        // Reset filters
        function resetFilters() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            
            document.querySelector('input[name="start_date"]').value = 
                firstDay.toISOString().split('T')[0];
            document.querySelector('input[name="end_date"]').value = 
                today.toISOString().split('T')[0];
            
            // Reset selects
            document.querySelectorAll('select').forEach(select => {
                select.value = 'all';
            });
            
            document.getElementById('reportForm').submit();
        }

        // Initialize charts
        function initializeCharts() {
            // Sales Chart
            const salesChart = document.getElementById('salesChart');
            if (salesChart) {
                const ctx = salesChart.getContext('2d');
                
                <?php if (isset($report_data['daily_sales'])): ?>
                const dates = <?php echo json_encode(array_map(function($sale) {
                    return date('M d', strtotime($sale['date']));
                }, $report_data['daily_sales'])); ?>;
                
                const revenues = <?php echo json_encode(array_map(function($sale) {
                    return $sale['total_revenue'];
                }, $report_data['daily_sales'])); ?>;
                
                const orders = <?php echo json_encode(array_map(function($sale) {
                    return $sale['order_count'];
                }, $report_data['daily_sales'])); ?>;
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dates.reverse(),
                        datasets: [{
                            label: 'Revenue ($)',
                            data: revenues.reverse(),
                            borderColor: '#4CAF50',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            fill: true,
                            yAxisID: 'y'
                        }, {
                            label: 'Orders',
                            data: orders.reverse(),
                            borderColor: '#2196F3',
                            backgroundColor: 'rgba(33, 150, 243, 0.1)',
                            fill: true,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            },
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Revenue ($)'
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Orders'
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            }
                        }
                    }
                });
                <?php endif; ?>
            }
            
            // Best Sellers Chart
            const bestSellersChart = document.getElementById('bestSellersChart');
            if (bestSellersChart) {
                const ctx2 = bestSellersChart.getContext('2d');
                
                <?php if (isset($report_data['best_sellers'])): ?>
                const productNames = <?php echo json_encode(array_map(function($product) {
                    return substr($product['name'], 0, 20) . (strlen($product['name']) > 20 ? '...' : '');
                }, array_slice($report_data['best_sellers'], 0, 10))); ?>;
                
                const unitsSold = <?php echo json_encode(array_map(function($product) {
                    return $product['total_sold'];
                }, array_slice($report_data['best_sellers'], 0, 10))); ?>;
                
                new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: productNames,
                        datasets: [{
                            label: 'Units Sold',
                            data: unitsSold,
                            backgroundColor: [
                                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                                '#9966FF', '#FF9F40', '#8AC926', '#1982C4',
                                '#6A4C93', '#FF595E'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Top 10 Best Sellers'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Units Sold'
                                }
                            }
                        }
                    }
                });
                <?php endif; ?>
            }
        }

        // Generate PDF using jsPDF (client-side)
        function generatePDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const element = document.getElementById('reportContent');
            
            // Add title
            doc.setFontSize(20);
            doc.text('Report - ' + '<?php echo ucwords(str_replace('_', ' ', $report_type)); ?>', 20, 20);
            
            // Add date range
            doc.setFontSize(12);
            doc.text('Period: ' + '<?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>', 20, 30);
            doc.text('Generated: ' + new Date().toLocaleString(), 20, 38);
            
            // Use html2canvas to capture the content
            html2canvas(element, {
                scale: 2,
                useCORS: true,
                logging: false,
                windowHeight: element.scrollHeight
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = 180;
                const pageHeight = 280;
                const imgHeight = canvas.height * imgWidth / canvas.width;
                
                let heightLeft = imgHeight;
                let position = 50;
                
                doc.addImage(imgData, 'PNG', 15, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'PNG', 15, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                doc.save('report_<?php echo date('Ymd_His'); ?>.pdf');
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const exportModal = document.getElementById('exportModal');
            if (event.target === exportModal) {
                closeExportModal();
            }
        };
    </script>

    <style>
        /* Report Specific Styles */
        .report-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .report-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .report-header h3 {
            margin-bottom: 0.5rem;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .report-summary {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .summary-card {
            flex: 1;
            min-width: 200px;
            background: #f7fafc;
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .summary-info h4 {
            margin: 0;
            font-size: 1.5rem;
            color: #2d3748;
        }
        
        .summary-info p {
            margin: 0;
            color: #718096;
            font-size: 0.9rem;
        }
        
        .report-chart {
            margin: 2rem 0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .report-table {
            margin-top: 2rem;
        }
        
        .product-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .product-info img {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-info small {
            color: #718096;
            font-size: 0.85rem;
        }
        
        .rating {
            color: #f6ad55;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .rating small {
            color: #718096;
            margin-left: 0.25rem;
        }
        
        .performance-high {
            color: #38a169;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .performance-medium {
            color: #d69e2e;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .performance-low {
            color: #e53e3e;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .customer-premium {
            color: #805ad5;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .customer-regular {
            color: #3182ce;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .customer-new {
            color: #718096;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .rank-badge {
            display: inline-block;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            text-align: center;
            line-height: 28px;
            font-weight: bold;
            color: white;
        }
        
        .rank-1 { background: #f6ad55; }
        .rank-2 { background: #cbd5e0; }
        .rank-3 { background: #d69e2e; }
        .rank-4, .rank-5 { background: #a0aec0; }
        .rank-6, .rank-7, .rank-8, .rank-9, .rank-10 { background: #e2e8f0; color: #4a5568; }
        
        .trend-up {
            color: #38a169;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .trend-down {
            color: #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .report-insights {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .report-insights h3 {
            margin-bottom: 1.5rem;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .insight-card {
            background: #f7fafc;
            border-radius: 8px;
            padding: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            transition: transform 0.2s;
        }
        
        .insight-card:hover {
            transform: translateY(-2px);
        }
        
        .insight-icon {
            width: 48px;
            height: 48px;
            background: #667eea;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }
        
        .insight-content h4 {
            margin: 0 0 0.5rem 0;
            color: #2d3748;
        }
        
        .insight-content p {
            margin: 0;
            color: #718096;
            line-height: 1.5;
        }
        
        .format-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .format-option input {
            display: none;
        }
        
        .format-card {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .format-option input:checked + .format-card {
            border-color: #667eea;
            background: #f7fafc;
        }
        
        .format-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #667eea;
        }
        
        .format-card span {
            display: block;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .format-card small {
            color: #718096;
            font-size: 0.85rem;
        }
        
        /* Print Styles */
        @media print {
            .admin-sidebar,
            .admin-header .header-actions,
            .admin-filters,
            .report-insights,
            .admin-footer,
            .dataTables_wrapper .top,
            .dataTables_wrapper .bottom {
                display: none !important;
            }
            
            .admin-container {
                margin: 0;
                padding: 0;
            }
            
            .admin-main {
                width: 100%;
                margin: 0;
                padding: 0;
            }
            
            .report-container {
                box-shadow: none;
                border: none;
                padding: 0;
            }
            
            body {
                background: white;
                font-size: 12pt;
            }
            
            .report-header h3 {
                font-size: 16pt;
                color: black;
            }
            
            .summary-card {
                break-inside: avoid;
            }
            
            .data-table {
                width: 100%;
                font-size: 10pt;
            }
            
            .data-table th {
                background: #f5f5f5 !important;
                color: black !important;
            }
            
            a {
                color: black;
                text-decoration: none;
            }
            
            @page {
                margin: 0.5in;
            }
        }
        
        @media (max-width: 768px) {
            .report-summary {
                flex-direction: column;
            }
            
            .summary-card {
                min-width: 100%;
            }
            
            .insights-grid {
                grid-template-columns: 1fr;
            }
            
            .format-options {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .filter-group {
                width: 100%;
            }
        }
    </style>
</body>
</html>
<?php
