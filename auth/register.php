<?php
// Start a secure user session at the absolute top entry point of the script execution loop
session_start();

// 1. Hook up the database connection
require_once '../config/database.php';

// If the user is already logged in, bypass registration and send them to the dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit;
}

$error = '';
$success = '';

// 2. Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) { 
        $error = 'Password must be at least 8 characters long.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM accounts WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->rowCount() > 0) {
                $error = 'Username or Email is already taken.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // 🌟 DB ARCHITECTURE MUTATION: Accounts default to an 'inactive' status and receive a 'manager' role
                $insert_stmt = $pdo->prepare("INSERT INTO accounts (username, email, password, role, status) VALUES (?, ?, ?, 'manager', 'inactive')");
                $insert_stmt->execute([$username, $email, $hashed_password]);

                $success = 'Registration submitted! Awaiting administrator approval...';
                header("refresh:3; url=login.php");
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = 'An error occurred during registration.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse IMS - Register</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-image: url('../image/register bg1.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.45);
            z-index: 1;
        }

        /* Split-Pane UI Container Matrix */
        .split-container {
            position: relative;
            z-index: 2;
            display: flex;
            background-color: rgba(20, 20, 22, 0.85); 
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 24px;
            width: 100%;
            max-width: 880px; 
            height: 600px;     
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.8);
        }

        /* Left Side: Interactive Form Module */
        .form-pane {
            flex: 1;
            padding: 30px 45px; 
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* Right Side: Visual Artwork Frame */
        .graphic-pane {
            flex: 1.1;
            background-image: url('../image/warehouse bg3.jpg'); 
            background-size: cover;
            background-position: center;
            position: relative;
            margin: 16px;
            border-radius: 18px;
        }

        .graphic-pane::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(to bottom, rgba(0,0,0,0), rgba(0,0,0,0.4));
            border-radius: 18px;
        }

        h2 {
            font-size: 28px;
            margin-bottom: 4px;
            color: #ffffff;
            font-weight: 600;
        }

        p.subtitle {
            color: #a0a0ab;
            font-size: 14px;
            margin-bottom: 12px; 
        }

        /* Message Box Spacer Container: Dynamic conditional sizing */
        .message-box-spacer {
            display: flex;
            align-items: center;
            height: 0; 
            margin-bottom: 0; 
            overflow: hidden;
            transition: height 0.2s ease, margin-bottom 0.2s ease;
        }

        /* Uses CSS :has element selector to show up only if a child element exists */
        .message-box-spacer:has(.error-msg), 
        .message-box-spacer:has(.success-msg) {
            height: 54px; 
            margin-bottom: 12px; 
        }

        .error-msg, .success-msg {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            animation: fadeIn 0.2s ease-in-out;
        }

        .error-msg {
            background-color: rgba(207, 102, 121, 0.15);
            color: #ff798e;
            border: 1px solid rgba(207, 102, 121, 0.25);
        }

        .success-msg {
            background-color: rgba(3, 218, 198, 0.15);
            color: #03dac6;
            border: 1px solid rgba(3, 218, 198, 0.25);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-3px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #a0a0a0;
            font-size: 13px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        input {
            width: 100%;
            padding: 11px 16px;
            background-color: #1e1e22;
            border: 1px solid #2d2d32;
            border-radius: 10px;
            color: #ffffff;
            font-size: 15px;
            outline: none;
            transition: all 0.25s ease;
        }

        input:focus {
            border-color: #bb86fc;
            box-shadow: 0 0 0 4px rgba(187, 134, 252, 0.15);
            background-color: #222226;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            cursor: pointer;
            color: #72727a;
            transition: color 0.2s;
            font-size: 15px;
        }

        .toggle-password:hover {
            color: #bb86fc;
        }

        button {
            width: 100%;
            padding: 13px;
            background-color: #bb86fc;
            color: #0c0c0e;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            margin-top: 10px;
        }

        button:hover {
            background-color: #a370db;
        }

        button:active {
            transform: scale(0.99);
        }

        .footer-links {
            text-align: center;
            margin-top: 16px;
            font-size: 13px;
            color: #8e8e93;
        }

        .footer-links a {
            color: #bb86fc;
            text-decoration: none;
            font-weight: 600;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="split-container">
    <div class="form-pane">
        <h2>Create Account</h2>
        <p class="subtitle">Join the inventory management system today</p>

        <div class="message-box-spacer">
            <?php if (!empty($error)): ?>
                <div class="error-msg"><i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="success-msg"><i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
        </div>

        <form action="register.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required autocomplete="username" 
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <input type="email" id="email" name="email" required autocomplete="email" 
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                        oninvalid="this.setCustomValidity('invalid email address')"
                        oninput="this.setCustomValidity('')">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required>
                    <i class="fa-solid fa-eye toggle-password" data-target="password"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <i class="fa-solid fa-eye toggle-password" data-target="confirm_password"></i>
                </div>
            </div>

            <button type="submit">Register</button>
        </form>

        <div class="footer-links">
            Already have an account? <a href="login.php">Log In</a>
        </div>
    </div>

    <div class="graphic-pane"></div>
</div>

<script>
    const toggles = document.querySelectorAll('.toggle-password');
    
    toggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const inputField = document.getElementById(targetId);
            
            const type = inputField.getAttribute('type') === 'password' ? 'text' : 'password';
            inputField.setAttribute('type', type);
            
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    });
</script>

</body>
</html>