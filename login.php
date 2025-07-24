<?php

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = 'Both email and password are required';
    } else {
        $result = loginUser($email, $password);
        
        if ($result['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>

<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="brand-icon">
                💰
            </div>
            <h1 class="login-title">SmartSave Sacco</h1>
            <p class="login-subtitle">Welcome back! Please sign in to your account</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert-modern">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php" data-ajax="true" id="loginForm">
                <div class="form-floating">
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="name@example.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <label for="email">Email Address</label>
                </div>
                
                <div class="form-floating" style="position: relative;">
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Password" required>
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        <i class="fas fa-eye" id="passwordIcon"></i>
                    </button>
                </div>
                
                <div class="remember-forgot">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn login-btn" id="loginButton">
                    <span class="loading-spinner"></span>
                    <span class="btn-text">Sign In</span>
                </button>
            </form>
            
            <div class="divider">
                <span>New to SmartSave?</span>
            </div>
        </div>
        
        <div class="register-link">
            Don't have an account? <a href="register.php">Create Account</a>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const passwordIcon = document.getElementById('passwordIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}

// Enhanced form handling
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const button = document.getElementById('loginButton');
    const spinner = button.querySelector('.loading-spinner');
    const btnText = button.querySelector('.btn-text');
    
    // Show loading state
    button.disabled = true;
    spinner.style.display = 'inline-block';
    btnText.textContent = 'Signing In...';
    
    // If using AJAX, handle the response
    // Otherwise, let the form submit normally
    if (!this.dataset.ajax) {
        return true;
    }
    
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btnText.textContent = 'Success!';
            setTimeout(() => {
                window.location.href = data.redirect || 'dashboard.php';
            }, 500);
        } else {
            throw new Error(data.message || 'Login failed');
        }
    })
    .catch(error => {
        // Reset button state
        button.disabled = false;
        spinner.style.display = 'none';
        btnText.textContent = 'Sign In';
        
        // Show error
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert-modern';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${error.message}`;
        
        const existingError = document.querySelector('.alert-modern');
        if (existingError) {
            existingError.remove();
        }
        
        this.insertBefore(errorDiv, this.firstChild);
    });
});

// Add floating label animation
document.querySelectorAll('.form-floating .form-control').forEach(input => {
    input.addEventListener('focus', function() {
        this.parentElement.classList.add('focused');
    });
    
    input.addEventListener('blur', function() {
        if (!this.value) {
            this.parentElement.classList.remove('focused');
        }
    });
    
    // Check if input has value on load
    if (input.value) {
        input.parentElement.classList.add('focused');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
