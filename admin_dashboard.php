<?php
// Start a secure user session to check login status across pages
session_start();

// Connect to the database using the shared PDO configurations
require_once 'config/database.php';

// SECURITY GATE: Redirect the visitor back to login if they are not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

// Extract current session data into local variables for easy placement
$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);
$success_msg = '';
$error_msg = '';

/**
 * FORM CONTROLLER: Listens for incoming POST requests from forms
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // SUB-ACTION 1: REGISTERING A NEW OPERATOR ACCOUNT
    if ($_POST['action'] === 'create_user') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = trim($_POST['role']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        // Backend sanity check to ensure no field was left blank
        if (!empty($username) && !empty($email) && !empty($role) && !empty($password) && !empty($confirm_password)) {
            
            // Validate that both passwords match perfectly
            if ($password !== $confirm_password) {
                $error_msg = "Registration Error: Passwords do not match.";
            // Validate that the email structural pattern is correct
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_msg = "Registration Error: Please supply a structurally valid email format.";
            // Guard clause to block tampering with roles via inspector tools
            } elseif ($role !== 'manager' && $role !== 'admin') {
                $error_msg = "Registration Error: Invalid privilege allocation mapping requested.";
            } else {
                try {
                    // SQL CHECK: Ensure the username or email is not already taken
                    $check_stmt = $pdo->prepare("SELECT id FROM accounts WHERE username = ? OR email = ?");
                    $check_stmt->execute([$username, $email]);
                    
                    if ($check_stmt->fetch()) {
                        $error_msg = "Username or Email record is already occupied in system files.";
                    } else {
                        // Securely encrypt the password using a strong one-way hashing algorithm (BCRYPT)
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        
                        // SQL INSERT: Push the new verified operator record directly into the database
                        $create_stmt = $pdo->prepare("INSERT INTO accounts (username, email, role, password, status) VALUES (?, ?, ?, ?, 'active')");
                        $create_stmt->execute([$username, $email, $role, $hashed_password]);
                        
                        $success_msg = "User account '$username' registered successfully as active!";
                    }
                } catch (PDOException $e) {
                    $error_msg = "Failed to register user: " . $e->getMessage();
                }
            }
        } else {
            $error_msg = "All operational user details inputs are required.";
        }
    }

    // SUB-ACTION 2: SUSPENDING OR REACTIVATING AN ACCOUNT
    if ($_POST['action'] === 'toggle_user_status') {
        $user_id = (int)$_POST['user_id'];
        $new_status = $_POST['target_status'] === 'inactive' ? 'inactive' : 'active';

        if ($user_id > 0) {
            // SELF-ACCIDENT PROTECTION: Block administrators from locking themselves out of the system
            if ($user_id === (int)$current_user_id) {
                $error_msg = "Security block: You cannot change your own active admin log status.";
            } else {
                try {
                    // SQL UPDATE: Adjust the target account's access state flag
                    $toggle_stmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ?");
                    $toggle_stmt->execute([$new_status, $user_id]);
                    $success_msg = $new_status === 'inactive' ? "System access successfully suspended for this operator account." : "Operator account successfully reactivated.";
                } catch (PDOException $e) {
                    $error_msg = "Failed to update account status: " . $e->getMessage();
                }
            }
        }
    }
}

/**
 * INITIALIZATION QUERY: Pulls down all registered accounts to draw the dashboard layout
 */
try {
    $user_stmt = $pdo->query("SELECT id, username, email, role, status, created_at FROM accounts ORDER BY id DESC");
    $system_users = $user_stmt->fetchAll();
} catch (PDOException $e) {
    $system_users = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse IMS - Account Management</title>
    <style>
        /* BASE DESIGN CONFIGURATIONS */
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { background-color: #0b0b0c; color: #e2e8f0; padding: 24px; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        /* HEADER & IDENTITY LOGO ELEMENTS */
        header { display: flex; justify-content: space-between; align-items: center; background-color: #131316; padding: 16px 28px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #222227; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); height: 70px;}
        .branding-container { display: flex; align-items: center; gap: 16px; }
        .branding-logo { display: flex; align-items: center; gap: 12px; }
        .branding-logo img { height: 65px; width: auto; object-fit: contain; margin-top: -4px; position: relative; z-index: 10; }
        .branding-logo h1 { font-size: 18px; color: #ffffff; font-weight: 600; letter-spacing: 0.5px; margin: 0; }

        /* WELCOME TAG & BUTTONS */
        .user-welcome { color: #94a3b8; font-size: 14px; font-weight: 500; border-left: 1px solid #222227; padding-left: 16px; display: flex; align-items: center; height: 34px; }
        .user-welcome strong { color: #3b82f6; margin-left: 4px; }
        .nav-links a { text-decoration: none; color: #94a3b8; margin-left: 28px; font-weight: 600; font-size: 15px; transition: color 0.2s; cursor: pointer; }
        .nav-links a.logout { color: #f43f5e; }
        .nav-links a.logout:hover { color: #e11d48; }

        /* ALERT NOTIFICATION BANNERS */
        .banner { padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; border-left: 4px solid; }
        .banner-success { background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; border-left-color: #3b82f6; }
        .banner-error { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; border-left-color: #f43f5e; }

        /* INTERFACE UTILITY TOOLBAR */
        .toolbar { display: flex; justify-content: space-between; margin-bottom: 20px; gap: 16px; }
        .search-bar { padding: 12px 18px; width: 380px; background-color: #131316; border: 1px solid #222227; border-radius: 8px; color: #ffffff; font-size: 15px; outline: none; transition: all 0.2s; }
        .search-bar:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .btn-add { background-color: #3b82f6; color: #ffffff; border: none; padding: 12px 26px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 15px; transition: background-color 0.2s; }
        .btn-add:hover { background-color: #2563eb; }

        /* DATA MANAGEMENT TABLES */
        .table-container { background-color: #131316; border-radius: 12px; box-shadow: 0 4px 25px rgba(0, 0, 0, 0.5); flex-grow: 1; overflow-y: auto; border: 1px solid #222227; }
        .table-container::-webkit-scrollbar { width: 10px; }
        .table-container::-webkit-scrollbar-track { background: #131316; border-radius: 0 12px 12px 0; }
        .table-container::-webkit-scrollbar-thumb { background: #2a2a32; border-radius: 10px; border: 2px solid #131316; }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        thead th { position: sticky; top: 0; background-color: #1c1c21; padding: 18px 24px; font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; border-bottom: 2px solid #222227; z-index: 1; }
        tbody td { padding: 16px 24px; border-bottom: 1px solid #1f1f24; vertical-align: middle; color: #cbd5e1; font-size: 15px; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: rgba(255, 255, 255, 0.015); }
        
        /* STATUS BADGES & STYLING LABELS */
        .sku-tag { background-color: rgba(59, 130, 246, 0.05); color: #3b82f6; padding: 4px 10px; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-weight: 600; font-size: 14px; border: 1px solid rgba(59, 130, 246, 0.15); }
        .user-tag { background-color: rgba(255, 255, 255, 0.03); color: #e2e8f0; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 14px; border: 1px solid #222227; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .status-active { background-color: rgba(3, 218, 198, 0.1); color: #03dac6; }
        .status-inactive { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; }
        
        .role-tag { background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; border: 1px solid rgba(59,130,246,0.2); text-transform: uppercase; }
        .role-admin { background-color: rgba(168, 85, 247, 0.1); color: #a855f7; border: 1px solid rgba(168,85,247,0.2); }

        /* ACTION TRIGGER CONTROLS */
        .action-buttons { display: flex; gap: 8px; }
        .btn-edit, .btn-delete { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .btn-edit { background-color: #1f1f24; color: #cbd5e1; border: 1px solid #33333f; }
        .btn-edit:hover { background-color: #2a2a33; color: #ffffff; border-color: #444454; }
        .btn-delete { background-color: rgba(244, 63, 94, 0.1); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.15); }
        .btn-delete:hover { background-color: #f43f5e; color: #0b0b0c; }
        .btn-activate { background-color: rgba(3, 218, 198, 0.1); color: #03dac6; border: 1px solid rgba(3, 218, 198, 0.15); }
        .btn-activate:hover { background-color: #03dac6; color: #0b0b0c; }

        /* OVERLAY WINDOW DIAGRAMS (MODALS) */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); justify-content: center; align-items: center; z-index: 100; }
        .modal-content { background-color: #131316; padding: 32px; border-radius: 12px; width: 100%; max-width: 460px; border: 1px solid #222227; box-shadow: 0 10px 30px rgba(0,0,0,0.6); }
        .modal h3 { color: #fff; margin-bottom: 24px; font-size: 20px; font-weight: 600; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; color: #94a3b8; font-size: 14px; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 12px; background-color: #1c1c21; border: 1px solid #222227; border-radius: 6px; color: #ffffff; font-size: 15px; outline: none; }
        .form-group input:focus, .form-group select:focus { border-color: #3b82f6; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px; }
        .btn-cancel { background: none; border: 1px solid #222227; color: #94a3b8; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .btn-cancel:hover { background-color: #1c1c21; color: #fff; }
        .btn-submit { background-color: #3b82f6; color: white; border: none; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; }
        .btn-submit:hover { background-color: #2563eb; }
        .btn-confirm-delete { background-color: #f43f5e; color: #ffffff; border: none; padding: 11px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; }

        /* PASSWORD WRAPPER & VISIBILITY EYE ICON STYLING */
        .password-input-container { position: relative; display: flex; align-items: center; }
        .password-input-container input { padding-right: 44px; }
        .toggle-password-eye { position: absolute; right: 14px; background: none; border: none; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 4px; transition: color 0.2s; user-select: none; }
        .toggle-password-eye:hover { color: #3b82f6; }
        .toggle-password-eye svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .view-section { display: flex; flex-direction: column; flex-grow: 1; overflow: hidden; }
    </style>
</head>
<body>

    <header>
        <div class="branding-container">
            <div class="branding-logo">
                <img src="image/logo.png" alt="Warehouse IMS Logo">
                <h1>Warehouse IMS <span style="font-size: 12px; color: #3b82f6; background: rgba(59,130,246,0.1); padding: 2px 6px; border-radius: 4px; margin-left: 4px;">Accounts</span></h1>
            </div>
            <div class="user-welcome">Welcome, <strong><?php echo $current_username; ?></strong></div>
        </div>
        <div class="nav-links">
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </header>

    <?php if (!empty($success_msg)): ?>
        <div class="banner banner-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="banner banner-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>
    
    <div id="section-users" class="view-section">
        <div class="toolbar">
            <input type="text" id="userSearch" class="search-bar" placeholder="Search system operators...">
            <button class="btn-add" onclick="openAddUserModal()">+ Register New Operator</button>
        </div>

        <div class="table-container">
            <table id="usersTable">
                <thead>
                    <tr>
                        <th>Account ID</th>
                        <th>Username</th>
                        <th>Email Address</th>
                        <th>System Role</th>
                        <th>Login Status</th>
                        <th>Account Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($system_users as $user): ?>
                    <tr>
                        <td><span class="sku-tag">#USR-<?php echo str_pad($user['id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                        <td style="font-weight: 600; color: #ffffff;"><span class="user-tag"><?php echo htmlspecialchars($user['username']); ?></span></td>
                        <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="role-tag <?php echo ($user['role'] ?? 'manager') === 'admin' ? 'role-admin' : ''; ?>">
                                <?php echo htmlspecialchars($user['role'] ?? 'manager'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $user['status'] === 'inactive' ? 'status-inactive' : 'status-active'; ?>">
                                <?php echo htmlspecialchars($user['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td style="color: #64748b; font-size: 14px; font-family: monospace;"><?php echo htmlspecialchars($user['created_at']); ?></td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($user['id'] === (int)$current_user_id): ?>
                                    <span style="font-size: 13px; color: #64748b; font-style: italic; padding: 8px;">Your Active Session</span>
                                <?php else: ?>
                                    <?php if ($user['status'] === 'inactive'): ?>
                                        <form action="admin_dashboard.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="target_status" value="active">
                                            <button type="submit" class="btn-edit btn-activate">Reactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn-delete" onclick="openToggleUserModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')">Suspend Access</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="addUserModal" class="modal">
        <div class="modal-content" style="border-color: #3b82f6;">
            <h3 style="color: #3b82f6;">Register New Warehouse Operator</h3>
            <form action="admin_dashboard.php" method="POST" id="regUserForm">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label>Operator Username</label>
                    <input type="text" name="username" required autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label>System Privilege Assignment Role</label>
                    <select name="role" required>
                        <option value="manager" selected>Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <div class="password-input-container">
                        <input type="password" id="regPassword" name="password" required>
                        <button type="button" class="toggle-password-eye" onclick="togglePasswordVisibility('regPassword', this)">
                            <svg class="eye-icon" viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="password-input-container">
                        <input type="password" id="regConfirmPassword" name="confirm_password" required>
                        <button type="button" class="toggle-password-eye" onclick="togglePasswordVisibility('regConfirmPassword', this)">
                            <svg class="eye-icon" viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                    <span id="passSymmetryError" style="color: #f43f5e; font-size: 12px; margin-top: 4px; display: none;">Passwords do not match.</span>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toggleUserModal" class="modal">
        <div class="modal-content" style="max-width: 420px; border-color: #f43f5e;">
            <h3 style="color: #f43f5e;">Suspend System Access</h3>
            <p style="color: #94a3b8; font-size: 15px; margin-bottom: 12px;">Are you sure you want to change the status of <strong id="toggleUsernameDisplay" style="color: #ffffff;"></strong> to inactive?</p>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 24px;">This keeps their history records linked safely, but will lock them out from logging in immediately via login.php.</p>
            <form action="admin_dashboard.php" method="POST">
                <input type="hidden" name="action" value="toggle_user_status">
                <input type="hidden" name="target_status" value="inactive">
                <input type="hidden" id="toggleUserId" name="user_id">
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeToggleUserModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-delete">Confirm Suspension</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // VISIBILITY TOGGLE HANDLER: Swaps structural context values between input states dynamically
        function togglePasswordVisibility(inputId, buttonEl) {
            const targetInput = document.getElementById(inputId);
            if (targetInput.type === 'password') {
                targetInput.type = 'text';
                // Add a visual slash line over the eye icon to represent "hidden state broken"
                buttonEl.innerHTML = `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                        <line x1="1" y1="1" x2="23" y2="23"></line>
                    </svg>
                `;
                buttonEl.style.color = '#3b82f6';
            } else {
                targetInput.type = 'password';
                // Reset back to standard plain open eye icon
                buttonEl.innerHTML = `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                `;
                buttonEl.style.color = '#64748b';
            }
        }

        // CLIENT-SIDE PASSPHRASE VERIFIER: Realtime matching interface warning
        const regPassword = document.getElementById('regPassword');
        const regConfirmPassword = document.getElementById('regConfirmPassword');
        const symmetryError = document.getElementById('passSymmetryError');

        function evaluatePasswords() {
            if (regConfirmPassword.value === '') {
                symmetryError.style.display = 'none';
                regConfirmPassword.setCustomValidity('');
            } else if (regPassword.value !== regConfirmPassword.value) {
                symmetryError.style.display = 'block';
                regConfirmPassword.setCustomValidity("Mismatch");
            } else {
                symmetryError.style.display = 'none';
                regConfirmPassword.setCustomValidity('');
            }
        }
        regPassword.addEventListener('change', evaluatePasswords);
        regConfirmPassword.addEventListener('keyup', evaluatePasswords);

        // INSTANT SEARCH FILTER: Hides table rows immediately if they don't match the search input
        document.getElementById('userSearch').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#usersTable tbody tr');
            rows.forEach(row => {
                let name = row.cells[1].innerText.toLowerCase();
                let email = row.cells[2].innerText.toLowerCase();
                row.style.display = (name.includes(filter) || email.includes(filter)) ? '' : 'none';
            });
        });

        // INTERFACE MODAL UTILITIES: Controls visibility states of popups
        function openAddUserModal() { document.getElementById('addUserModal').style.display = 'flex'; }
        function closeAddUserModal() { 
            document.getElementById('addUserModal').style.display = 'none';
            document.getElementById('regUserForm').reset();
            symmetryError.style.display = 'none';
            
            // Clean up look adjustments if closed mid-toggle execution
            document.getElementById('regPassword').type = 'password';
            document.getElementById('regConfirmPassword').type = 'password';
            const buttons = document.querySelectorAll('.toggle-password-eye');
            buttons.forEach(btn => {
                btn.style.color = '#64748b';
                btn.innerHTML = `
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                `;
            });
        }

        function openToggleUserModal(id, username) {
            document.getElementById('toggleUserId').value = id;
            document.getElementById('toggleUsernameDisplay').innerText = "'" + username + "'";
            document.getElementById('toggleUserModal').style.display = 'flex';
        }
        function closeToggleUserModal() { document.getElementById('toggleUserModal').style.display = 'none'; }
    </script>
</body>
</html>