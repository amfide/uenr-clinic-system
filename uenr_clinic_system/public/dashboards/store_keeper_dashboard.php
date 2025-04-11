<?php
require_once '../includes/config.php';
checkAuth();

$user = getCurrentUser();
if ($user['role'] != 'store_keeper') {
    header("Location: {$user['role']}_dashboard.php");
    exit();
}

// Handle inventory update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_inventory'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $itemId = $_POST['item_id'];
        $quantity = $_POST['quantity'];
        
        try {
            $stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ?, last_restocked = NOW() WHERE id = ?");
            $stmt->execute([$quantity, $itemId]);
            
            $_SESSION['success'] = "Inventory item updated successfully";
            logActivity($user['id'], "Updated inventory item ID: $itemId");
        } catch (PDOException $e) {
            $error = "Error updating inventory item: " . $e->getMessage();
        }
    }
}

// Handle inventory request processing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_request'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $requestId = $_POST['request_id'];
        $action = $_POST['action'];
        
        try {
            if ($action == 'approve') {
                // Check inventory stock
                $stmt = $pdo->prepare("SELECT i.quantity, ir.quantity as requested_qty 
                                       FROM inventory_requests ir
                                       JOIN inventory_items i ON ir.item_id = i.id
                                       WHERE ir.id = ?");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch();
                
                if ($request['quantity'] >= $request['requested_qty']) {
                    // Update inventory stock
                    $stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = (SELECT item_id FROM inventory_requests WHERE id = ?)");
                    $stmt->execute([$request['requested_qty'], $requestId]);
                    
                    // Update request status
                    $stmt = $pdo->prepare("UPDATE inventory_requests SET status = 'Fulfilled', processed_by = ?, processed_date = NOW() WHERE id = ?");
                    $stmt->execute([$user['id'], $requestId]);
                    
                    $_SESSION['success'] = "Inventory request fulfilled successfully";
                    logActivity($user['id'], "Fulfilled inventory request ID: $requestId");
                } else {
                    $error = "Insufficient inventory stock to fulfill this request";
                }
            } else {
                // Reject request
                $stmt = $pdo->prepare("UPDATE inventory_requests SET status = 'Rejected', processed_by = ?, processed_date = NOW() WHERE id = ?");
                $stmt->execute([$user['id'], $requestId]);
                
                $_SESSION['success'] = "Inventory request rejected";
                logActivity($user['id'], "Rejected inventory request ID: $requestId");
            }
        } catch (PDOException $e) {
            $error = "Error processing inventory request: " . $e->getMessage();
        }
    }
}

// Get low stock items
$stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE quantity <= reorder_level ORDER BY quantity ASC");
$stmt->execute();
$lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending inventory requests
$stmt = $pdo->prepare("SELECT ir.*, ii.name as item_name, u.full_name as requester_name 
                       FROM inventory_requests ir
                       JOIN inventory_items ii ON ir.item_id = ii.id
                       JOIN users u ON ir.requested_by = u.id
                       WHERE ir.status = 'Pending'
                       ORDER BY ir.request_date ASC");
$stmt->execute();
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all inventory items
$stmt = $pdo->prepare("SELECT * FROM inventory_items ORDER BY name");
$stmt->execute();
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Keeper Dashboard - UENR Clinic</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center mb-4">
                    <h4>UENR Clinic</h4>
                    <hr>
                    <p class="mb-0">Welcome, <?php echo $user['full_name']; ?></p>
                    <small>(Store Keeper)</small>
                </div>
                <a href="store_keeper_dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="#inventory" data-bs-toggle="collapse"><i class="bi bi-box-seam"></i> Inventory Management</a>
                <a href="#requests" data-bs-toggle="collapse"><i class="bi bi-clipboard-check"></i> Process Requests</a>
                <a href="../auth/logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <h2 class="mb-4">Store Keeper Dashboard</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Low Stock Items -->
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0">Items Low in Stock</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($lowStockItems)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Last Restocked</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lowStockItems as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                <td class="<?php echo $item['quantity'] == 0 ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo $item['quantity']; ?>
                                                </td>
                                                <td><?php echo $item['reorder_level']; ?></td>
                                                <td><?php echo $item['last_restocked'] ? date('d/m/Y', strtotime($item['last_restocked'])) : 'Never'; ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#restockModal" 
                                                            data-item-id="<?php echo $item['id']; ?>" 
                                                            data-item-name="<?php echo htmlspecialchars($item['name']); ?>">
                                                        Restock
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">All inventory items have sufficient stock.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending Inventory Requests -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">Pending Inventory Requests</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($pendingRequests)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Request ID</th>
                                            <th>Item Name</th>
                                            <th>Quantity</th>
                                            <th>Requested By</th>
                                            <th>Request Date</th>
                                            <th>Reason</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingRequests as $request): ?>
                                            <tr>
                                                <td>INV-<?php echo $request['id']; ?></td>
                                                <td><?php echo htmlspecialchars($request['item_name']); ?></td>
                                                <td><?php echo $request['quantity']; ?></td>
                                                <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($request['request_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($request['reason']); ?></td>
                                                <td>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" name="process_request" class="btn btn-sm btn-success" 
                                                                onclick="return confirm('Approve this inventory request?')">Approve</button>
                                                    </form>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" name="process_request" class="btn btn-sm btn-danger" 
                                                                onclick="return confirm('Reject this inventory request?')">Reject</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No pending inventory requests found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Full Inventory -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">Inventory Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Description</th>
                                        <th>Current Stock</th>
                                        <th>Reorder Level</th>
                                        <th>Last Restocked</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                                            <td class="<?php echo $item['quantity'] <= $item['reorder_level'] ? 'text-warning fw-bold' : ''; ?>">
                                                <?php echo $item['quantity']; ?>
                                            </td>
                                            <td><?php echo $item['reorder_level']; ?></td>
                                            <td><?php echo $item['last_restocked'] ? date('d/m/Y', strtotime($item['last_restocked'])) : 'Never'; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#restockModal" 
                                                        data-item-id="<?php echo $item['id']; ?>" 
                                                        data-item-name="<?php echo htmlspecialchars($item['name']); ?>">
                                                    Restock
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restock Modal -->
    <div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="restockModalLabel">Restock Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="item_id" id="modalItemId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="itemName" class="form-label">Item</label>
                            <input type="text" class="form-control" id="itemName" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="quantity" class="form-label">Quantity to Add</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_inventory" class="btn btn-primary" 
                                onclick="return confirm('Confirm this inventory update?')">Update Inventory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Handle modal data
        var restockModal = document.getElementById('restockModal');
        restockModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var itemId = button.getAttribute('data-item-id');
            var itemName = button.getAttribute('data-item-name');
            
            document.getElementById('modalItemId').value = itemId;
            document.getElementById('itemName').value = itemName;
        });
    </script>
</body>
</html>