<?php
$pageTitle = 'Stock Taking List';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!hasPermission('stock_taking', 'view')) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';

// Get filters
$locationFilter = $_GET['location'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = $_GET['page'] ?? 1;

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($locationFilter) {
    // Aggregate parent + all descendant locations
    $locIds = getDescendantLocationIds($locationFilter, $conn);
    $locInClause = implode(',', array_map('intval', $locIds));
    $whereConditions[] = "st.location_id IN ($locInClause)";
}

if ($dateFrom) {
    $whereConditions[] = "st.stock_date >= ?";
    $params[] = dbDate($dateFrom);
}

if ($dateTo) {
    $whereConditions[] = "st.stock_date <= ?";
    $params[] = dbDate($dateTo);
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Get total count
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM stock_taking st $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];

$pagination = paginate($totalRecords, $page);

// Get stock takings
$stmt = $conn->prepare("
    SELECT 
        st.*,
        l.name as location_name,
        u.name as created_by_name,
        (SELECT COUNT(*) FROM stock_taking_details WHERE taking_id = st.id) as product_count,
        (SELECT SUM(ABS(variance_qty)) FROM stock_taking_details WHERE taking_id = st.id) as total_variance_qty,
        (SELECT SUM(variance_amount) FROM stock_taking_details WHERE taking_id = st.id) as total_variance_amount
    FROM stock_taking st
    LEFT JOIN locations l ON st.location_id = l.id
    LEFT JOIN users u ON st.created_by = u.id
    $whereClause
    ORDER BY st.stock_date DESC, st.id DESC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$stockTakings = $stmt->fetchAll();

// Get locations for filter
$locations = getUserLocations();
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-clipboard-check"></i> Stock Taking List</h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="stock_taking.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> New Stock Taking
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label for="location" class="form-label">Location</label>
                <select name="location" id="location" class="form-select">
                    <option value="">All Locations</option>
                    <?= renderTreeOptions($locations, $locationFilter) ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="date_from" class="form-label">Date From</label>
                <input type="date" name="date_from" id="date_from" class="form-control" 
                       value="<?php echo $dateFrom; ?>">
            </div>
            <div class="col-md-3">
                <label for="date_to" class="form-label">Date To</label>
                <input type="date" name="date_to" id="date_to" class="form-control" 
                       value="<?php echo $dateTo; ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <?php if ($locationFilter || $dateFrom || $dateTo): ?>
                    <a href="stock_taking_list.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stock Takings Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($stockTakings)): ?>
        <div class="empty-state">
            <i class="bi bi-clipboard-check"></i>
            <h5>No stock takings found</h5>
            <p>Start by creating your first stock taking</p>
            <a href="stock_taking.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create Stock Taking
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Stock Taking #</th>
                        <th>Location</th>
                        <th>Method</th>
                        <th>Products</th>
                        <th>Variance Amt</th>
                        <th>Expected Sales</th>
                        <th>Actual Cash</th>
                        <th>Cash Variance</th>
                        <th>By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stockTakings as $taking): ?>
                    <tr>
                        <td><?php echo formatDate($taking['stock_date']); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($taking['stock_taking_number']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($taking['location_name']); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $taking['calculation_method']; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $taking['product_count']; ?> items</span>
                        </td>
                        <td>
                            <?php 
                            $varAmt = $taking['total_variance_amount'];
                            $badgeClass = 'bg-success';
                            if ($varAmt > 0) {
                                $badgeClass = 'bg-info';
                            } elseif ($varAmt < 0) {
                                $badgeClass = 'bg-danger';
                            }
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php echo formatCurrency($varAmt); ?>
                            </span>
                        </td>
                        <td><?php echo formatCurrency($taking['expected_sales_amount']); ?></td>
                        <td><?php echo formatCurrency($taking['actual_cash_amount']); ?></td>
                        <td>
                            <?php 
                            $cashVar = $taking['cash_variance'];
                            $badgeClass = 'bg-success';
                            if ($cashVar > 0) {
                                $badgeClass = 'bg-info';
                            } elseif ($cashVar < 0) {
                                $badgeClass = 'bg-danger';
                            }
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php echo formatCurrency($cashVar); ?>
                            </span>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars($taking['created_by_name']); ?></small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="stock_taking_view.php?id=<?php echo $taking['id']; ?>" 
                                   class="btn btn-outline-info" title="View Report">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (hasPermission('stock_taking', 'delete')): ?>
                                <a href="stock_taking_view.php?delete=<?php echo $taking['id']; ?>" 
                                   class="btn btn-outline-danger confirm-delete" 
                                   title="Delete"
                                   onclick="return confirm('Are you sure you want to delete stock taking <?php echo htmlspecialchars($taking['stock_taking_number']); ?>?\n\nThis action cannot be undone!');">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&location=<?php echo $locationFilter; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        Previous
                    </a>
                </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                <li class="page-item <?php echo $i == $pagination['current_page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&location=<?php echo $locationFilter; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($pagination['has_next']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&location=<?php echo $locationFilter; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>">
                        Next
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
