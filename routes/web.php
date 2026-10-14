<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ChangelogController;
use App\Http\Controllers\Admin\FeedbackInboxController;
use App\Http\Controllers\Admin\ReviewerController;
use App\Http\Controllers\BidAdmin\BidAdminController;
use App\Http\Controllers\BidAdmin\ResetDemoController;
use App\Http\Controllers\BidAdmin\ReviewRedemptionController;
use App\Http\Controllers\Employee\EmployeeWalletController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FraudEvalController;
use App\Http\Controllers\JudgeController;
use App\Http\Controllers\JudgeLinkController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// Public: the landing page links here.
Route::get('/eval', [FraudEvalController::class, 'public'])->name('eval');

// The pre-login demo pages now live behind login. Old links go to Log in.
Route::redirect('/demo/{any?}', '/login')->where('any', '.*');

// Login, logout and password reset routes come from Laravel Fortify
// (see App\Providers\FortifyServiceProvider). There is no registration.

// Reviewers' magic links: /judge-access#<id>.<token> signs them straight in,
// read-only, until judging ends. Rate-limited per visitor IP.
Route::get('/judge-access', [JudgeLinkController::class, 'show'])->middleware('throttle:20,1')->name('judge-access.show');
Route::post('/judge-access', [JudgeLinkController::class, 'store'])->middleware('throttle:10,1')->name('judge-access.store');
// The old single-use password invite links no longer work.
Route::get('/invite/{any}', [JudgeLinkController::class, 'ended'])->where('any', '.*');

Route::middleware('auth')->group(function () {
    // Where Fortify sends people after logging in: each role's own dashboard.
    Route::get('/dashboard', fn (Request $request) => redirect()->route($request->user()->role->dashboardRoute()))
        ->name('dashboard');

    // Every role: change your own password (the form posts to Fortify's user-password.update).
    Route::get('/account', fn (Request $request) => view('account.show', [
        'feedback' => $request->user()->feedback()->latest()->latest('id')->get(),
    ]))->name('account');

    // The Feedback button on every signed-in page, and editing your own items.
    // Rate-limited, more tightly for the shared public reviewer (AppServiceProvider).
    Route::middleware('throttle:feedback')->group(function () {
        Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
        Route::patch('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('feedback.update');
    });

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
    Route::middleware('role:judge')->group(function () {
        Route::get('/judge', [JudgeController::class, 'dashboard'])->name('judge.dashboard');
        // Read-only "view as" switcher. With no role, goes back to the judge's own view.
        Route::get('/judge/view-as/{role?}', [JudgeController::class, 'viewAs'])->name('judge.view-as');
    });
    Route::middleware('role:platform_admin')->group(function () {
        Route::get('/admin', AccountController::class)->name('admin.dashboard');

        Route::prefix('admin/reviewers')->name('admin.reviewers.')->group(function () {
            Route::get('/', [ReviewerController::class, 'index'])->name('index');
            Route::post('/', [ReviewerController::class, 'store'])->name('store');
            Route::post('/{user}/link', [ReviewerController::class, 'link'])->name('link');
            Route::post('/{user}/revoke', [ReviewerController::class, 'revoke'])->name('revoke');
        });

        Route::get('/admin/feedback', [FeedbackInboxController::class, 'index'])->name('admin.feedback.index');
        Route::patch('/admin/feedback/{feedback}', [FeedbackInboxController::class, 'update'])->name('admin.feedback.update');
        Route::get('/admin/changelog', [ChangelogController::class, 'index'])->name('admin.changelog.index');
        Route::post('/admin/changelog', [ChangelogController::class, 'store'])->name('admin.changelog.store');
    });
});

// Design system reference. Returns 404 outside the local environment.
Route::get('/styleguide', StyleguideController::class)->name('styleguide');
