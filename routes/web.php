<?php

use App\Http\Controllers\BidAdmin\BidAdminController;
use App\Http\Controllers\BidAdmin\ResetDemoController;
use App\Http\Controllers\BidAdmin\ReviewRedemptionController;
use App\Http\Controllers\Employee\EmployeeWalletController;
use App\Http\Controllers\FraudEvalController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\StyleguideController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// Public: the landing page links here.
Route::get('/eval', [FraudEvalController::class, 'public'])->name('eval');

// The pre-login demo pages now live behind login. Old links go to Log in.
Route::redirect('/demo/{any?}', '/login')->where('any', '.*');

// Login, logout and password reset routes come from Laravel Fortify
// (see App\Providers\FortifyServiceProvider). There is no registration.

Route::middleware('auth')->group(function () {
    // Where Fortify sends people after logging in: each role's own dashboard.
    Route::get('/dashboard', fn (Request $request) => redirect()->route($request->user()->role->dashboardRoute()))
        ->name('dashboard');

    Route::middleware('role:employee')->group(function () {
        Route::get('/employee', [EmployeeWalletController::class, 'show'])->name('employee.wallet');
        Route::post('/employee/redeem', [EmployeeWalletController::class, 'redeem'])->name('employee.redeem');
    });

    Route::middleware('role:bid_admin,platform_admin')->prefix('bid-admin')->name('bid-admin.')->group(function () {
        Route::get('/', BidAdminController::class)->name('dashboard');
        Route::get('/eval', [FraudEvalController::class, 'admin'])->name('eval');
        Route::post('/redemptions/{redemption}/approve', [ReviewRedemptionController::class, 'approve'])->name('approve');
        Route::post('/redemptions/{redemption}/reject', [ReviewRedemptionController::class, 'reject'])->name('reject');
        Route::post('/reset', ResetDemoController::class)->name('reset');
    });

    Route::view('/employer', 'dashboards.employer')->middleware('role:employer_admin')->name('employer.dashboard');
    Route::view('/merchant', 'dashboards.merchant')->middleware('role:merchant')->name('merchant.dashboard');
    Route::view('/judge', 'dashboards.judge')->middleware('role:judge')->name('judge.dashboard');
    Route::get('/admin', fn () => view('dashboards.admin', ['users' => User::orderBy('id')->get()]))
        ->middleware('role:platform_admin')->name('admin.dashboard');
});

// Design system reference. Returns 404 outside the local environment.
Route::get('/styleguide', StyleguideController::class)->name('styleguide');
