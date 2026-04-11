<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\AuthController;

// ============================================
// Authentication Routes (No Middleware Needed)
// ============================================
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('auth.login.form');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

// ============================================
// Protected Routes (Require Authentication)
// ============================================
Route::middleware(\App\Http\Middleware\CheckAuthenticated::class)->group(function () {
    Route::get('/', [VerificationController::class, 'index'])->name('verification.index');
    Route::post('/run', [VerificationController::class, 'run'])->name('verification.run');

    // Job status tracking (for AJAX polling)
    Route::get('/status', [VerificationController::class, 'status'])->name('verification.status.api');

    // Job status page (human readable)
    Route::get('/job/{job_id}', [VerificationController::class, 'showStatus'])->name('verification.status');

    // Results page (after job completion)
    Route::get('/results', [VerificationController::class, 'results'])->name('verification.results');

    // Cancel job
    Route::post('/cancel', [VerificationController::class, 'cancelJob'])->name('verification.cancel');

    // Download files
    Route::get('/download/{format}', [VerificationController::class, 'download'])->name('verification.download');

    // Cache management
    Route::get('/clear-cache', [VerificationController::class, 'clearCache'])->name('verification.clearCache');
    Route::post('/clear-worksheet-cache', [VerificationController::class, 'clearWorksheetCache'])->name('verification.clearWorksheetCache');
});
