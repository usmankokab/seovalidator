<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VerificationController;

Route::get('/', [VerificationController::class, 'index'])->name('verification.index');
Route::post('/run', [VerificationController::class, 'run'])->name('verification.run');
Route::get('/download/{format}', [VerificationController::class, 'download'])->name('verification.download');
Route::post('/clear-cache', [VerificationController::class, 'clearCache'])->name('verification.clearCache');
Route::post('/clear-worksheet-cache', [VerificationController::class, 'clearWorksheetCache'])->name('verification.clearWorksheetCache');
