<?php

use App\Http\Controllers\Demo\EmployeeWalletController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/demo/employee', [EmployeeWalletController::class, 'show'])->name('demo.employee');
Route::post('/demo/employee/redeem', [EmployeeWalletController::class, 'redeem'])->name('demo.employee.redeem');

Route::view('/demo/bid-admin', 'demo.placeholder', ['heading' => 'BID admin'])->name('demo.bid-admin');
