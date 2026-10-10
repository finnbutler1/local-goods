<?php

use App\Http\Controllers\Demo\BidAdminController;
use App\Http\Controllers\Demo\EmployeeWalletController;
use App\Http\Controllers\Demo\ResetDemoController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/demo/employee', [EmployeeWalletController::class, 'show'])->name('demo.employee');
Route::post('/demo/employee/redeem', [EmployeeWalletController::class, 'redeem'])->name('demo.employee.redeem');

Route::get('/demo/bid-admin', BidAdminController::class)->name('demo.bid-admin');
Route::post('/demo/reset', ResetDemoController::class)->name('demo.reset');
