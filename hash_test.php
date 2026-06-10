<?php
// hash_test.php - Password Hash Generator
// Place this in your project root folder

$generated_hash = '';
$input_password = '';
$verify_result = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_password = $_POST['password'];
    
    if (!empty($input_password)) {
        // Generate the hash
        $generated_hash = password_hash($input_password, PASSWORD_DEFAULT);
        
        // Verify it works
        if (password_verify($input_password, $generated_hash)) {
            $verify_result = '✅ SUCCESS: Hash is valid!';
        } else {
            $verify_result = '❌ ERROR: Hash verification failed!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Generator</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        label {
            font-weight: bold;
            display: block;
            margin-top: 20px;
            margin-bottom: 5px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            font-family: monospace;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 30px;
            margin-top: 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #0056b3;
        }
        .hash-box {
            background: #f8f9fa;
            border: 2px solid #28a745;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            word-break: break-all;
        }
        .hash-value {
            font-family: monospace;
            font-size: 14px;
            background: white;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            margin-top: 10px;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #007bff;
            margin: 20px 0;
        }
        .copy-btn {
            background: #28a745;
            margin-top: 10px;
            padding: 8px 20px;
        }
        .copy-btn:hover {
            background: #218838;
        }
        hr {
            margin: 30px 0;
        }
        .sql-box {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .sql-box pre {
            margin: 0;
            color: #2ecc71;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Password Hash Generator</h1>
        <p>Generate bcrypt password hashes for your admin users</p>
        
        <div class="info">
            <strong>ℹ️ Info:</strong> This creates a secure bcrypt hash (60 characters) that works with PHP's password_verify()
        </div>
        
        <form method="POST">
            <label for="password">Enter Password:</label>
            <input type="password" name="password" id="password" 
                   placeholder="Type your password here..." 
                   value="<?php echo htmlspecialchars($input_password); ?>"
                   required autofocus>
            
            <button type="submit">🔨 Generate Hash</button>
        </form>
        
        <?php if ($generated_hash): ?>
            <div class="hash-box">
                <strong>✅ Generated Hash:</strong>
                <div class="hash-value" id="hashValue"><?php echo $generated_hash; ?></div>
                <button class="copy-btn" onclick="copyToClipboard()">📋 Copy Hash</button>
                <div class="verify-result" style="margin-top: 10px;">
                    <?php echo $verify_result; ?>
                </div>
            </div>
            
            <hr>
            
            <h3>📦 SQL Insert Statement:</h3>
            <div class="sql-box">
                <pre><?php 
                echo "INSERT INTO admins (username, password, email, full_name) VALUES (\n";
                echo "    'admin',\n";
                echo "    '" . $generated_hash . "',\n";
                echo "    'admin@example.com',\n";
                echo "    'Administrator'\n";
                echo ");";
                ?></pre>
            </div>
            
            <div class="sql-box" style="margin-top: 10px;">
                <pre><?php 
                echo "UPDATE admins SET password = '" . $generated_hash . "' WHERE username = 'admin';";
                ?></pre>
            </div>
            
            <hr>
            
            <h3>🔧 PHP Code to Use:</h3>
            <div class="sql-box">
                <pre><?php 
                echo "// To verify password in your login script:\n";
                echo "\$hashed_password = '" . $generated_hash . "';\n";
                echo "if (password_verify(\$user_input_password, \$hashed_password)) {\n";
                echo "    // Login successful\n";
                echo "}";
                ?></pre>
            </div>
            
            <hr>
            
            <h3>✅ Quick SQL Command (Copy this whole line):</h3>
            <div class="sql-box">
                <pre style="white-space: pre-wrap;">UPDATE admins SET password = '<?php echo $generated_hash; ?>' WHERE username = 'admin';</pre>
            </div>
        <?php endif; ?>
        
        <hr>
        
        <h3>💡 Common Passwords & Their Hashes:</h3>
        <div class="info">
            <strong>Admin@123</strong><br>
            <code>$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi</code>
            <br><br>
            <strong>password123</strong><br>
            <code>$2y$10$YourHashWillBeDifferentHere1234567890abcdefghij</code>
        </div>
        
        <div class="info">
            <strong>⚠️ Important:</strong> Each time you generate a hash, it will be different (even for the same password) because of salt. 
            This is normal and secure! Just use the hash you generate.
        </div>
    </div>
    
    <script>
        function copyToClipboard() {
            const hashValue = document.getElementById('hashValue').innerText;
            navigator.clipboard.writeText(hashValue).then(function() {
                alert('✅ Hash copied to clipboard!');
            }, function() {
                alert('❌ Failed to copy. Please copy manually.');
            });
        }
    </script>
</body>
</html>