<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SEO Workbook Verifier')</title>
    @yield('styles')
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        /* Navbar */
        .navbar {
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 3px solid #667eea;
        }

        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 70px;
        }

        .navbar-brand {
            font-size: 22px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-brand:hover {
            opacity: 0.8;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 15px;
            background: #f8f9ff;
            border-radius: 8px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }

        .user-role {
            font-size: 11px;
            color: #8899aa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 50px;
            text-align: center;
        }

        .logout-btn {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(245, 87, 108, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 87, 108, 0.4);
        }

        .logout-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(245, 87, 108, 0.3);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border-left: 4px solid #3c3;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .alert-warning {
            background: #ffe;
            color: #963;
            border-left: 4px solid #963;
        }

        .alert-close {
            cursor: pointer;
            font-size: 20px;
            opacity: 0.6;
            transition: opacity 0.2s ease;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar-container {
                height: 60px;
            }

            .navbar-brand {
                font-size: 18px;
            }

            .navbar-right {
                gap: 15px;
            }

            .user-info {
                flex-direction: column;
                gap: 6px;
                padding: 6px 10px;
            }

            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }

            .user-name {
                font-size: 12px;
            }

            .user-role {
                font-size: 10px;
            }

            .role-badge {
                font-size: 9px;
                padding: 3px 8px;
            }

            .logout-btn {
                padding: 8px 12px;
                font-size: 11px;
            }

            .main-container {
                padding: 15px 10px;
            }
        }

        @media (max-width: 480px) {
            .navbar-right {
                gap: 10px;
            }

            .user-details {
                display: none;
            }

            .user-info {
                flex-direction: row;
                padding: 4px 8px;
            }
        }

        /* Content */
        .content {
            @yield('content-styles')
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="/" class="navbar-brand">
                📊 SEO Workbook Verifier
            </a>

            <div class="navbar-right">
                <!-- User Info & Logout -->
                @if (Session::has('user'))
                    <div class="user-info">
                        <div class="user-avatar">
                            {{ strtoupper(substr(Session::get('user.name'), 0, 1)) }}
                        </div>
                        <div class="user-details">
                            <span class="user-name">{{ Session::get('user.name') }}</span>
                            <span class="user-role">{{ Session::get('user.role') }}</span>
                        </div>
                        <span class="role-badge">
                            {{ strtoupper(Session::get('user.role')) }}
                        </span>
                    </div>

                    <form method="POST" action="{{ route('auth.logout') }}" style="margin: 0;">
                        @csrf
                        <button type="submit" class="logout-btn">
                            🚪 Logout
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Session Alerts -->
        @if (session('success'))
            <div class="alert alert-success">
                <span>✅ {{ session('success') }}</span>
                <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
            </div>
        @endif

        @if (session('warning'))
            <div class="alert alert-warning">
                <span>⚠️ {{ session('warning') }}</span>
                <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">
                <span>❌ {{ session('error') }}</span>
                <span class="alert-close" onclick="this.parentElement.style.display='none';">×</span>
            </div>
        @endif

        <!-- Page Content -->
        <div class="content">
            @yield('content')
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
    </script>

    @yield('scripts')
</body>
</html>
