<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('/demo/employee', 'demo.placeholder', ['heading' => 'Employee wallet'])->name('demo.employee');
Route::view('/demo/bid-admin', 'demo.placeholder', ['heading' => 'BID admin'])->name('demo.bid-admin');
