<?php

use App\Http\Controllers\Demo\BidAdminController;
use App\Http\Controllers\Demo\EmployeeWalletController;
use App\Http\Controllers\Demo\FraudEvalController;
use App\Http\Controllers\Demo\ResetDemoController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Signed-in area. Real login arrives in a later step; for now it shows a demo persona.
Route::view('/dashboard', 'dashboard')->name('dashboard');

Route::get('/demo/employee', [EmployeeWalletController::class, 'show'])->name('demo.employee');
Route::post('/demo/employee/redeem', [EmployeeWalletController::class, 'redeem'])->name('demo.employee.redeem');

Route::get('/demo/bid-admin', BidAdminController::class)->name('demo.bid-admin');
Route::get('/demo/bid-admin/eval', FraudEvalController::class)->name('demo.bid-admin.eval');
Route::post('/demo/reset', ResetDemoController::class)->name('demo.reset');
