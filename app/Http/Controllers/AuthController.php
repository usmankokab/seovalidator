<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{
    /**
     * Hardcoded user credentials
     */
    private const USERS = [
        [
            'id' => 1,
            'username' => 'virene@me.com',
            'password' => 'am1Bq$&07}0q',
            'role' => 'admin',
            'name' => 'Admin User'
        ],
        [
            'id' => 2,
            'username' => 'SEO_Agen',
            'password' => 's~<WR?3A1)*9@&m-',
            'role' => 'user',
            'name' => 'SEO Agent'
        ]
    ];

    /**
     * Show login form
     */
    public function showLoginForm()
    {
        if (Session::has('user')) {
            return redirect('/');
        }
        return view('auth.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string'
        ], [
            'username.required' => 'Username or email is required',
            'password.required' => 'Password is required'
        ]);

        $username = $request->input('username');
        $password = $request->input('password');

        // Find user in hardcoded users
        $user = null;
        foreach (self::USERS as $u) {
            if ($u['username'] === $username && $u['password'] === $password) {
                $user = $u;
                break;
            }
        }

        if (!$user) {
            return back()
                ->with('error', 'Invalid credentials. Please check your username and password.')
                ->withInput($request->only('username'));
        }

        // Store in session
        Session::put('user', [
            'id' => $user['id'],
            'username' => $user['username'],
            'name' => $user['name'],
            'role' => $user['role'],
            'logged_in_at' => now()
        ]);

        Session::regenerate();

        return redirect('/')
            ->with('success', 'Welcome ' . $user['name'] . '! You have been logged in successfully.');
    }

    /**
     * Handle logout
     */
    public function logout(Request $request)
    {
        $userName = Session::get('user.name', 'User');
        
        Session::forget('user');
        Session::flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')
            ->with('success', $userName . ' logged out successfully. See you next time!');
    }

    /**
     * Get current user
     */
    public static function getUser()
    {
        return Session::get('user');
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated()
    {
        return Session::has('user');
    }

    /**
     * Check if user has admin role
     */
    public static function isAdmin()
    {
        return Session::get('user.role') === 'admin';
    }
}
