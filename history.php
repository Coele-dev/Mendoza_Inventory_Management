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

$current_username = htmlspecialchars($_SESSION['username']);

// 2. Fetch Audit Trail Logs joining historical log entries with accounts data
try {
    $query = "
        SELECT h.id, h.action_type, h.description, h.changed_at, a.username 
        FROM inventory_history h
        JOIN accounts a ON h.account_id = a.id
        ORDER BY h.changed_at DESC
    ";
    $stmt = $pdo->query($query);
    $history_logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $history_logs = [];
    $error_msg = "Failed to load audit transaction history: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse IMS - Change Logs</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background-color: #0b0b0c; color: #e2e8f0; padding: 24px; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        header { display: flex; justify-content: space-between; align-items: center; background-color: #131316; padding: 16px 28px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #222227; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); height: 70px;}
        .branding-container { display: flex; align-items: center; gap: 16px; }
        
        /* Matching Logo Asset Style Rules */
        .branding-logo { display: flex; align-items: center; gap: 12px; }
        .branding-logo img { height: 65px; width: auto; object-fit: contain; margin-top: -4px; position: relative; z-index: 10; }
        .branding-logo h1 { font-size: 18px; color: #ffffff; font-weight: 600; letter-spacing: 0.5px; margin: 0; }

        .user-welcome { color: #94a3b8; font-size: 14px; font-weight: 500; border-left: 1px solid #222227; padding-left: 16px; display: flex; align-items: center; height: 34px; }
        .user-welcome strong { color: #e2e8f0; margin-left: 4px; }
        .nav-links a { text-decoration: none; color: #94a3b8; margin-left: 28px; font-weight: 600; font-size: 15px; transition: color 0.2s; }
        .nav-links a.active, .nav-links a:hover { color: #03dac6; }
        .nav-links a.logout { color: #f43f5e; }
        .nav-links a.logout:hover { color: #e11d48; }

        /* Toolbar Filter Configurations */
        .toolbar { display: flex; align-items: center; margin-bottom: 20px; gap: 12px; }
        .search-bar { padding: 12px 18px; width: 340px; background-color: #131316; border: 1px solid #222227; border-radius: 8px; color: #ffffff; font-size: 15px; outline: none; transition: all 0.2s; }
        .search-bar:focus { border-color: #03dac6; box-shadow: 0 0 0 3px rgba(3, 218, 198, 0.15); }

        /* Custom Structured Dropdown Menus */
        .filter-select { 
            padding: 12px 36px 12px 16px; 
            background-color: #131316; 
            border: 1px solid #222227; 
            border-radius: 8px; 
            color: #e2e8f0; 
            font-size: 14px; 
            font-weight: 600; 
            outline: none; 
            cursor: pointer; 
            transition: all 0.25s ease;
            appearance: none; 
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .filter-select:focus { 
            border-color: #03dac6; 
            box-shadow: 0 0 0 3px rgba(3, 218, 198, 0.15); 
        }

        .select-all { color: #cbd5e1; }
        .filter-select.select-all:focus,
        .filter-select.select-create:focus { 
            border-color: #03dac6; 
            box-shadow: 0 0 0 3px rgba(3, 218, 198, 0.2); 
        }
        .select-create { background-color: #0b0b0c; color: #03dac6; font-weight: 700; }

        .select-update { background-color: #0b0b0c; color: #bb86fc; font-weight: 700; }
        .filter-select.select-update:focus { 
            border-color: #bb86fc; 
            box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.25); 
        }

        .select-delete { background-color: #0b0b0c; color: #f43f5e; font-weight: 700; }
        .filter-select.select-delete:focus { 
            border-color: #f43f5e; 
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.25); 
        }

        .table-container { background-color: #131316; border-radius: 12px; box-shadow: 0 4px 25px rgba(0, 0, 0, 0.5); flex-grow: 1; overflow-y: auto; border: 1px solid #222227; }
        .table-container::-webkit-scrollbar { width: 10px; }
        .table-container::-webkit-scrollbar-track { background: #131316; border-radius: 0 12px 12px 0; }
        .table-container::-webkit-scrollbar-thumb { background: #2a2a32; border-radius: 10px; border: 2px solid #131316; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead th { position: sticky; top: 0; background-color: #1c1c21; padding: 18px 24px; font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; border-bottom: 2px solid #222227; z-index: 1; }
        tbody td { padding: 16px 24px; border-bottom: 1px solid #1f1f24; vertical-align: middle; color: #cbd5e1; font-size: 14px; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: rgba(255, 255, 255, 0.015); }

        .badge { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px; display: inline-block; text-align: center; min-width: 75px; }
        .badge-create { background-color: rgba(3, 218, 198, 0.1); color: #03dac6; border: 1px solid rgba(3, 218, 198, 0.2); }
        .badge-update { background-color: rgba(187, 134, 252, 0.1); color: #bb86fc; border: 1px solid rgba(187, 134, 252, 0.2); }
        .badge-delete { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.2); }
        
        .operator-name { font-weight: 600; color: #ffffff; }
        .timestamp-log { font-family: monospace; color: #64748b; }
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
            <a href="dashboard.php">Inventory</a>
            <a href="history.php" class="active">History</a>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </header>
    
    <div class="toolbar">
        <input type="text" id="historySearch" class="search-bar" placeholder="Search logs or accounts...">
        
        <select id="timeFilter" class="filter-select">
            <option value="all">All Time</option>
            <option value="1h">Last Hour</option>
            <option value="24h">Last 24 Hours</option>
            <option value="1w">Last Week</option>
            <option value="1m">Last Month</option>
            <option value="1y">Last Year</option>
        </select>

        <select id="typeFilter" class="filter-select select-all" onchange="updateDropdownStyling(this)">
            <option value="all" class="select-all">All Actions</option>
            <option value="CREATE" class="select-create">CREATE</option>
            <option value="UPDATE" class="select-update">UPDATE</option>
            <option value="DELETE" class="select-delete">DELETE</option>
        </select>
    </div>

    <div class="table-container">
        <table id="historyTable">
            <thead>
                <tr>
                    <th style="width: 12%;">Type</th>
                    <th style="width: 15%;">Account User</th>
                    <th style="width: 53%;">Action Details Log</th>
                    <th style="width: 20%;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history_logs)): ?>
                    <tr id="emptyFallbackRow">
                        <td colspan="4" style="text-align: center; padding: 48px; color: #64748b;">No system operational history modifications registered yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history_logs as $log): ?>
                    <tr data-type="<?php echo htmlspecialchars($log['action_type']); ?>" data-time="<?php echo htmlspecialchars($log['changed_at']); ?>">
                        <td>
                            <span class="badge badge-<?php echo strtolower($log['action_type']); ?>">
                                <?php echo htmlspecialchars($log['action_type']); ?>
                            </span>
                        </td>
                        <td><span class="operator-name">@<?php echo htmlspecialchars($log['username']); ?></span></td>
                        <td style="color: #e2e8f0; line-height: 1.4;"><?php echo htmlspecialchars($log['description']); ?></td>
                        <td><span class="timestamp-log"><?php echo htmlspecialchars($log['changed_at']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        function updateDropdownStyling(selectElement) {
            selectElement.classList.remove('select-all', 'select-create', 'select-update', 'select-delete');
            
            if (selectElement.value === 'CREATE') {
                selectElement.classList.add('select-create');
            } else if (selectElement.value === 'UPDATE') {
                selectElement.classList.add('select-update');
            } else if (selectElement.value === 'DELETE') {
                selectElement.classList.add('select-delete');
            } else {
                selectElement.classList.add('select-all');
            }
            
            applyUnifiedFilters();
        }

        function applyUnifiedFilters() {
            const searchVal = document.getElementById('historySearch').value.toLowerCase();
            const timeVal = document.getElementById('timeFilter').value;
            const typeVal = document.getElementById('typeFilter').value;
            
            const rows = document.querySelectorAll('#historyTable tbody tr');
            const now = new Date();
            let visibleCount = 0;

            rows.forEach(row => {
                if (!row.hasAttribute('data-time')) return;

                let operator = row.cells[1].innerText.toLowerCase();
                let details = row.cells[2].innerText.toLowerCase();
                let textMatch = operator.includes(searchVal) || details.includes(searchVal);

                let rowType = row.getAttribute('data-type');
                let typeMatch = (typeVal === 'all' || rowType === typeVal);

                let rowTime = new Date(row.getAttribute('data-time').replace(/-/g, "/"));
                let timeMatch = false;
                let diffInMs = now - rowTime;

                if (timeVal === 'all') {
                    timeMatch = true;
                } else if (timeVal === '1h') {
                    timeMatch = (diffInMs <= 60 * 60 * 1000);
                } else if (timeVal === '24h') {
                    timeMatch = (diffInMs <= 24 * 60 * 60 * 1000);
                } else if (timeVal === '1w') {
                    timeMatch = (diffInMs <= 7 * 24 * 60 * 60 * 1000);
                } else if (timeVal === '1m') {
                    timeMatch = (diffInMs <= 30 * 24 * 60 * 60 * 1000);
                } else if (timeVal === '1y') {
                    timeMatch = (diffInMs <= 365 * 24 * 60 * 60 * 1000);
                }

                if (textMatch && typeMatch && timeMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            let fallbackRow = document.getElementById('emptyFallbackRow');
            if (visibleCount === 0) {
                if (!fallbackRow) {
                    const tbody = document.querySelector('#historyTable tbody');
                    tbody.insertAdjacentHTML('beforeend', `<tr id="emptyFallbackRow"><td colspan="4" style="text-align: center; padding: 48px; color: #64748b;">No matching transaction records found for the applied parameters.</td></tr>`);
                } else {
                    fallbackRow.style.display = '';
                    fallbackRow.cells[0].innerText = "No matching transaction records found for the applied parameters.";
                }
            } else if (fallbackRow) {
                fallbackRow.style.display = 'none';
            }
        }

        document.getElementById('historySearch').addEventListener('keyup', applyUnifiedFilters);
        document.getElementById('timeFilter').addEventListener('change', applyUnifiedFilters);
    </script>
</body>
</html>