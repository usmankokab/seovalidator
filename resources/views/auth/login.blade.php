<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SEO Workbook Verifier</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.95;
            font-weight: 300;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background-color: #f8f9ff;
        }

        .form-group input::placeholder {
            color: #999;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .alert-error {
            background-color: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background-color: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .alert-warning {
            background-color: #ffe;
            color: #963;
            border: 1px solid #ffc;
        }

        .alert-close {
            float: right;
            cursor: pointer;
            font-weight: bold;
            opacity: 0.7;
        }

        .alert-close:hover {
            opacity: 1;
        }

        .login-button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .login-button:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.4);
        }

        .login-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .remember-me label {
            margin-left: 8px;
            font-size: 13px;
            color: #666;
            cursor: pointer;
            text-transform: none;
            font-weight: 400;
        }

        .demo-credentials {
            background: #f8f9ff;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 25px;
            font-size: 12px;
            color: #666;
        }

        .demo-credentials strong {
            color: #333;
            display: block;
            margin-top: 10px;
            margin-bottom: 5px;
            font-size: 12px;
        }

        .demo-credentials div {
            margin-bottom: 8px;
            padding: 6px 0;
            border-bottom: 1px solid #e0e7ff;
        }

        .demo-credentials div:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .credential-item {
            font-family: 'Courier New', monospace;
            background: white;
            padding: 8px 10px;
            border-radius: 4px;
            margin-top: 4px;
            word-break: break-all;
        }

        .role-badge {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
            text-transform: uppercase;
        }

        .role-badge.user {
            background: #764ba2;
        }

        .errors {
            display: none;
        }

        .errors.show {
            display: block;
        }

        .error-item {
            background: #fee;
            color: #c33;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 3px solid #c33;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .login-container {
                border-radius: 0;
            }

            .login-header {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .login-body {
                padding: 30px 20px;
            }

            body {
                padding: 0;
            }
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            right: 12px;
            top: 38px;
            color: #999;
            pointer-events: none;
        }

        .form-hidden {
            margin-bottom: 0;
        }

        .form-hidden input {
            display: none;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .login-button.loading {
            animation: pulse 1.5s infinite;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🔐 Login</h1>
            <p>SEO Workbook Verifier</p>
        </div>

        <div class="login-body">
            <!-- Error Messages -->
            @if ($errors->any())
                <div class="alert alert-error">
                    <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-error">
                    <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
                    {{ session('error') }}
                </div>
            @endif

            @if (session('warning'))
                <div class="alert alert-warning">
                    <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
                    {{ session('warning') }}
                </div>
            @endif

            <!-- Login Form -->
            <form id="loginForm" method="POST" action="{{ route('auth.login') }}" autocomplete="off">
                @csrf

                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <div class="input-group">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            placeholder="Enter your username or email"
                            value="{{ old('username') }}"
                            required
                            autocomplete="off"
                        >
                        <span class="input-icon">👤</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password"
                            required
                            autocomplete="off"
                        >
                        <span class="input-icon">🔒</span>
                    </div>
                </div>

                <button type="submit" class="login-button">Login</button>
            </form>
        </div>
    </div>

    <script>
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });

        // Add loading state to button
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = this.querySelector('.login-button');
            button.disabled = true;
            button.classList.add('loading');
            button.textContent = 'Logging in...';
        });
    </script>
</body>
</html>
