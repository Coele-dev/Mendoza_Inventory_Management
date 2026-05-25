<?php
// Start a secure user session at the absolute top entry point of the script execution loop
session_start();

// 1. Connect to the Railway/XAMPP dual-environment database file
require_once 'config/database.php';

// Security Check: Kick users to login if they aren't authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$success_msg = '';
$error_msg = '';

/**
 * AUTOMATED SKU GENERATION LOGIC
 */
function generateNextSku($pdo) {
    try {
        $stmt = $pdo->query("SELECT MAX(id) AS max_id FROM inventory");
        $row = $stmt->fetch();
        $next_number = ($row['max_id'] ?? 0) + 1;
        return '#' . str_pad($next_number, 5, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("SKU Generation Error: " . $e->getMessage());
        return '#00001';
    }
}

/**
 * BACKEND CORE: PROCESSING FORM DATABASE ACTIONS
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION A: ADD A NEW PRODUCT
    if ($_POST['action'] === 'add') {
        $product_name = trim($_POST['product_name']);
        $quantity = (int)$_POST['quantity'];
        $price = (float)$_POST['price'];
        $sku = generateNextSku($pdo);

        if (!empty($product_name)) {
            try {
                // 1. Always save the product first (Your reliable working code layout)
                $stmt = $pdo->prepare("INSERT INTO inventory (sku, product_name, quantity, price, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$sku, $product_name, $quantity, $price]);
                $new_product_id = $pdo->lastInsertId();
                
                $success_msg = "Product added successfully!";

                // 2. Safe History Logging: Adds a background record with the name and the code bundled together
                try {
                    $history_stmt = $pdo->prepare("INSERT INTO inventory_history (product_id, account_id, action_type, description) VALUES (?, ?, 'CREATE', ?)");
                    $description = "Added product '$product_name' ($sku) with starting qty: $quantity, price: ₱" . number_format($price, 2);
                    $history_stmt->execute([$new_product_id, $current_user_id, $description]);
                } catch (PDOException $history_error) {
                    // Fail silently—keeps your dashboard working even if the log table isn't created yet
                    error_log("History logging omitted: " . $history_error->getMessage());
                }

            } catch (PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        } else {
            $error_msg = "Product name cannot be empty.";
        }
    }

    // ACTION B: EDIT AN EXISTING PRODUCT
    if ($_POST['action'] === 'edit') {
        $product_id = (int)$_POST['product_id'];
        $product_name = trim($_POST['product_name']);
        $quantity = (int)$_POST['quantity'];
        $price = (float)$_POST['price'];

        if (!empty($product_name) && $product_id > 0) {
            try {
                // Fetch the original item snapshot first to compute the mutation values
                $orig_stmt = $pdo->prepare("SELECT sku, product_name, quantity, price FROM inventory WHERE id = ?");
                $orig_stmt->execute([$product_id]);
                $original = $orig_stmt->fetch();

                // 1. Update the inventory table details directly
                $update_stmt = $pdo->prepare("UPDATE inventory SET product_name = ?, quantity = ?, price = ? WHERE id = ?");
                $update_stmt->execute([$product_name, $quantity, $price, $product_id]);

                $success_msg = "Product updated successfully!";

                // 2. Safe History Logging: Track altered fields dynamically along with name and code reference
                if ($original) {
                    try {
                        $changes = [];
                        if ($original['product_name'] !== $product_name) {
                            $changes[] = "Name: '{$original['product_name']}' -> '$product_name'";
                        }
                        if ((int)$original['quantity'] !== $quantity) {
                            $changes[] = "Qty: {$original['quantity']} -> $quantity";
                        }
                        if ((float)$original['price'] !== $price) {
                            $changes[] = "Price: ₱" . number_format($original['price'], 2) . " -> ₱" . number_format($price, 2);
                        }

                        // Appends the current modified name along with the item's custom unique code identifier
                        $log_desc = !empty($changes) ? "Updated product '$product_name' ({$original['sku']}): " . implode(', ', $changes) : "Updated product '$product_name' ({$original['sku']}) (No properties were changed)";
                        
                        $history_stmt = $pdo->prepare("INSERT INTO inventory_history (product_id, account_id, action_type, description) VALUES (?, ?, 'UPDATE', ?)");
                        $history_stmt->execute([$product_id, $current_user_id, $log_desc]);
                    } catch (PDOException $history_error) {
                        error_log("History logging omitted: " . $history_error->getMessage());
                    }
                }

            } catch (PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        } else {
            $error_msg = "All modification fields must be filled out correctly.";
        }
    }

    // ACTION C: SOFT-DELETE A PRODUCT
    if ($_POST['action'] === 'delete') {
        $product_id = (int)$_POST['product_id'];

        if ($product_id > 0) {
            try {
                // Gather item name details before flagging it away
                $item_stmt = $pdo->prepare("SELECT sku, product_name FROM inventory WHERE id = ?");
                $item_stmt->execute([$product_id]);
                $item = $item_stmt->fetch();

                // 1. Mark product status string as inactive
                $delete_stmt = $pdo->prepare("UPDATE inventory SET status = 'inactive' WHERE id = ?");
                $delete_stmt->execute([$product_id]);

                $success_msg = "Product removed from active inventory list.";

                // 2. Safe History Logging: Bundle the product name together with the code on deletion logs
                if ($item) {
                    try {
                        $history_stmt = $pdo->prepare("INSERT INTO inventory_history (product_id, account_id, action_type, description) VALUES (?, ?, 'DELETE', ?)");
                        $description = "Marked product '{$item['product_name']}' ({$item['sku']}) as inactive.";
                        $history_stmt->execute([$product_id, $current_user_id, $description]);
                    } catch (PDOException $history_error) {
                        error_log("History logging omitted: " . $history_error->getMessage());
                    }
                }
            } catch (PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// 4. Fetch clean up-to-date active inventory dataset
try {
    $stmt = $pdo->query("SELECT * FROM inventory WHERE status = 'active' ORDER BY id DESC");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
    $error_msg = "Failed to load inventory dataset: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse IMS - Inventory</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background-color: #0b0b0c; color: #e2e8f0; padding: 24px; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        header { display: flex; justify-content: space-between; align-items: center; background-color: #131316; padding: 16px 28px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #222227; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); height: 70px;}
        .branding-container { display: flex; align-items: center; gap: 16px; }
        
        .branding-logo { display: flex; align-items: center; gap: 12px; }
        .branding-logo img { height: 65px; width: auto; object-fit: contain; margin-top: -4px; position: relative; z-index: 10; }
        .branding-logo h1 { font-size: 18px; color: #ffffff; font-weight: 600; letter-spacing: 0.5px; margin: 0; }

        .user-welcome { color: #94a3b8; font-size: 14px; font-weight: 500; border-left: 1px solid #222227; padding-left: 16px; display: flex; align-items: center; height: 34px; }
        .user-welcome strong { color: #e2e8f0; margin-left: 4px; }
        .nav-links a { text-decoration: none; color: #94a3b8; margin-left: 28px; font-weight: 600; font-size: 15px; transition: color 0.2s; }
        .nav-links a.active, .nav-links a:hover { color: #03dac6; }
        .nav-links a.logout { color: #f43f5e; }
        .nav-links a.logout:hover { color: #e11d48; }

        .banner { padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; border-left: 4px solid; }
        .banner-success { background-color: rgba(3, 218, 198, 0.1); color: #03dac6; border-left-color: #03dac6; }
        .banner-error { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; border-left-color: #f43f5e; }

        .toolbar { display: flex; justify-content: space-between; margin-bottom: 20px; gap: 16px; }
        .search-bar { padding: 12px 18px; width: 380px; background-color: #131316; border: 1px solid #222227; border-radius: 8px; color: #ffffff; font-size: 15px; outline: none; transition: all 0.2s; }
        .search-bar:focus { border-color: #03dac6; box-shadow: 0 0 0 3px rgba(3, 218, 198, 0.15); }
        .btn-add { background-color: #03dac6; color: #0b0b0c; border: none; padding: 12px 26px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 15px; transition: background-color 0.2s; }
        .btn-add:hover { background-color: #01bfa5; }

        .table-container { background-color: #131316; border-radius: 12px; box-shadow: 0 4px 25px rgba(0, 0, 0, 0.5); flex-grow: 1; overflow-y: auto; border: 1px solid #222227; }
        .table-container::-webkit-scrollbar { width: 10px; }
        .table-container::-webkit-scrollbar-track { background: #131316; border-radius: 0 12px 12px 0; }
        .table-container::-webkit-scrollbar-thumb { background: #2a2a32; border-radius: 10px; border: 2px solid #131316; }
        .table-container::-webkit-scrollbar-thumb:hover { background: #3f3f4e; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead th { position: sticky; top: 0; background-color: #1c1c21; padding: 18px 24px; font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; border-bottom: 2px solid #222227; z-index: 1; }
        tbody td { padding: 16px 24px; border-bottom: 1px solid #1f1f24; vertical-align: middle; color: #cbd5e1; font-size: 15px; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: rgba(255, 255, 255, 0.015); }
        
        .sku-tag { background-color: rgba(3, 218, 198, 0.08); color: #03dac6; padding: 4px 10px; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-weight: 600; font-size: 14px; border: 1px solid rgba(3, 218, 198, 0.15); }
        .action-buttons { display: flex; gap: 8px; }
        .btn-edit, .btn-delete { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; }
        
        .btn-edit { background-color: #1f1f24; color: #cbd5e1; border: 1px solid #33333f; }
        .btn-edit:hover { background-color: #2a2a33; color: #ffffff; border-color: #444454; }
        .btn-delete { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.15); }
        .btn-delete:hover { background-color: #f43f5e; color: #0b0b0c; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 100; }
        .modal-content { background-color: #131316; padding: 32px; border-radius: 12px; width: 100%; max-width: 460px; border: 1px solid #222227; box-shadow: 0 10px 30px rgba(0,0,0,0.6); }
        .modal h3 { color: #fff; margin-bottom: 24px; font-size: 20px; font-weight: 600; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; color: #94a3b8; font-size: 14px; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px; background-color: #1c1c21; border: 1px solid #222227; border-radius: 6px; color: #ffffff; font-size: 15px; outline: none; }
        .form-group input:focus { border-color: #03dac6; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px; }
        .btn-cancel { background: none; border: 1px solid #222227; color: #94a3b8; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .btn-cancel:hover { background-color: #1c1c21; color: #fff; }
        .btn-submit { background-color: #03dac6; color: #0b0b0c; border: none; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; }
        .btn-submit:hover { background-color: #01bfa5; }

        .btn-confirm-delete { background-color: #f43f5e; color: #ffffff; border: none; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; transition: background-color 0.2s; }
        .btn-confirm-delete:hover { background-color: #e11d48; }
        .delete-warning-text { color: #94a3b8; font-size: 15px; line-height: 1.5; margin-bottom: 10px; }
    </style>
</head>
<body>

    <header>
        <div class="branding-container">
            <div class="branding-logo">
                <img src="image/logo.png" alt="Warehouse IMS Logo">
                <h1>Warehouse IMS</h1>
            </div>
            <div class="user-welcome">Welcome, <strong><?php echo $current_username; ?></strong></div>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="active">Inventory</a>
            <a href="history.php">History</a>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </header>

    <?php if (!empty($success_msg)): ?>
        <div class="banner banner-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="banner banner-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>
    
    <div class="toolbar">
        <input type="text" id="searchInput" class="search-bar" placeholder="Search SKU or Product Name...">
        <button class="btn-add" onclick="openAddModal()">+ Add Product</button>
    </div>

    <div class="table-container">
        <table id="inventoryTable">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px; color: #64748b;">No active warehouse products found in data system tables.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $item): ?>
                    <tr>
                        <td><span class="sku-tag"><?php echo htmlspecialchars($item['sku']); ?></span></td>
                        <td style="font-weight: 600; color: #ffffff;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                        <td style="font-weight: 500;">₱<?php echo number_format($item['price'], 2); ?></td>
                        <td style="color: #64748b; font-size: 14px; font-family: monospace;"><?php echo htmlspecialchars($item['updated_at']); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">Edit</button>
                                <button class="btn-delete" onclick="openDeleteModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES); ?>')">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <h3>Add New Product</h3>
            <form action="dashboard.php" method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="product_name" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="0" min="0" required>
                </div>
                <div class="form-group">
                    <label>Price (₱)</label>
                    <input type="number" name="price" value="0.00" step="0.01" min="0" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3>Modify Product <span id="editSkuDisplay" style="color: #03dac6;"></span></h3>
            <form action="dashboard.php" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="editProductId" name="product_id">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="editProductName" name="product_name" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" id="editQuantity" name="quantity" min="0" required>
                </div>
                <div class="form-group">
                    <label>Price (₱)</label>
                    <input type="number" id="editPrice" name="price" step="0.01" min="0" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 420px; border-color: rgba(244, 63, 94, 0.3);">
            <h3 style="color: #f43f5e;">Confirm Deletion</h3>
            <p class="delete-warning-text">
                Are you sure you want to delete <strong id="deleteProductNameDisplay" style="color: #ffffff;"></strong>?
            </p>
            <p class="delete-warning-text" style="font-size: 13px; color: #64748b;">
                This operation will flag the record as inactive.
            </p>
            <form action="dashboard.php" method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" id="deleteProductId" name="product_id">
                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-delete">Delete Item</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('searchInput').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#inventoryTable tbody tr');
            rows.forEach(row => {
                if(row.cells.length === 1) return;
                let sku = row.cells[0].innerText.toLowerCase();
                let name = row.cells[1].innerText.toLowerCase();
                row.style.display = (sku.includes(filter) || name.includes(filter)) ? '' : 'none';
            });
        });

        function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
        function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

        function openEditModal(item) {
            document.getElementById('editProductId').value = item.id;
            document.getElementById('editSkuDisplay').innerText = "(" + item.sku + ")";
            document.getElementById('editProductName').value = item.product_name;
            document.getElementById('editQuantity').value = item.quantity;
            document.getElementById('editPrice').value = item.price;
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }

        function openDeleteModal(id, name) {
            document.getElementById('deleteProductId').value = id;
            document.getElementById('deleteProductNameDisplay').innerText = "'" + name + "'";
            document.getElementById('deleteModal').style.display = 'flex';
        }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
    </script>
</body>
</html>