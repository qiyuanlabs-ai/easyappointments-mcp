<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/oauth/consent', [ConsentController::class, 'approve'])->name('consent.approve');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/calls.csv', [DashboardController::class, 'export'])->name('calls.export');
    Route::patch('/grants/{grant}', [DashboardController::class, 'updateGrant'])->name('grants.update');
    Route::delete('/grants/{grant}', [DashboardController::class, 'revokeGrant'])->name('grants.revoke');
});
