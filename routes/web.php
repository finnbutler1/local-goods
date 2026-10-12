<?php

use App\Http\Controllers\Demo\BidAdminController;
use App\Http\Controllers\Demo\EmployeeWalletController;
use App\Http\Controllers\Demo\FraudEvalController;
use App\Http\Controllers\Demo\ResetDemoController;
use App\Http\Controllers\Demo\ReviewRedemptionController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// Signed-in area. Real login arrives in a later step; for now it shows a demo persona.
Route::view('/dashboard', 'dashboard')->name('dashboard');

Route::get('/demo/employee', [EmployeeWalletController::class, 'show'])->name('demo.employee');
Route::post('/demo/employee/redeem', [EmployeeWalletController::class, 'redeem'])->name('demo.employee.redeem');

Route::get('/demo/bid-admin', BidAdminController::class)->name('demo.bid-admin');
Route::get('/demo/bid-admin/eval', FraudEvalController::class)->name('demo.bid-admin.eval');
Route::post('/demo/bid-admin/redemptions/{redemption}/approve', [ReviewRedemptionController::class, 'approve'])->name('demo.bid-admin.approve');
Route::post('/demo/bid-admin/redemptions/{redemption}/reject', [ReviewRedemptionController::class, 'reject'])->name('demo.bid-admin.reject');
Route::post('/demo/reset', ResetDemoController::class)->name('demo.reset');

// Design system reference. Returns 404 outside the local environment.
Route::get('/styleguide', StyleguideController::class)->name('styleguide');
