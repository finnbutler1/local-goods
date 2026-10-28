<?php

use App\Http\Controllers\AdjudicateController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AdjudicationController;
use App\Http\Controllers\Admin\ChangelogController;
use App\Http\Controllers\Admin\FeedbackInboxController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ReviewerController;
use App\Http\Controllers\BidAdmin\BidAdminController;
use App\Http\Controllers\BidAdmin\CollusionExampleController;
use App\Http\Controllers\BidAdmin\CorrectionsController;
use App\Http\Controllers\BidAdmin\ImpactController;
use App\Http\Controllers\BidAdmin\ImpactExportController;
use App\Http\Controllers\BidAdmin\MerchantEligibilityController;
use App\Http\Controllers\BidAdmin\ApplicationController;
use App\Http\Controllers\BidAdmin\MerchantFeeController;
use App\Http\Controllers\BidAdmin\PartnershipController;
use App\Http\Controllers\BidAdmin\ParticipationController;
use App\Http\Controllers\BidAdmin\ResetDemoController;
use App\Http\Controllers\BidAdmin\ReviewRedemptionController;
use App\Http\Controllers\CorrectionRequestController;
use App\Http\Controllers\Districts\ApplyController;
use App\Http\Controllers\Districts\DistrictMapController;
use App\Http\Controllers\Districts\WhoDoesWhatController;
use App\Http\Controllers\Employee\EmployeeWalletController;
use App\Http\Controllers\Employee\PayCodeController;
use App\Http\Controllers\Employer\EmployerDashboardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FraudEvalController;
use App\Http\Controllers\JudgeController;
use App\Http\Controllers\JudgeLinkController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Merchant\MenuController;
use App\Http\Controllers\Merchant\MerchantDashboardController;
use App\Http\Controllers\Merchant\PayCodeController as MerchantPayCodeController;
use App\Http\Controllers\Merchant\RedemptionsExportController;
use App\Http\Controllers\Merchant\StripeOnboardingController;
use App\Http\Controllers\MerchantEvalController;
use App\Http\Controllers\Projections\BusinessModelController;
use App\Http\Controllers\Projections\ProjectionController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// Public: the landing page links here.
Route::get('/eval', [FraudEvalController::class, 'public'])->name('eval');
// Public, but business names only for named reviewers and admins (see the controller).
Route::get('/eval/merchants', MerchantEvalController::class)->name('eval.merchants');
// Public: district numbers only, labelled "Projection, not results" (ADR 0030).
Route::get('/projection', ProjectionController::class)->name('projection');
// The district map and address checker (ADR 0031). Checking an address is throttled; the map alone is not.
Route::get('/district', DistrictMapController::class)->middleware('throttle:district-check')->name('district');
// Who does what, for BIDs (ADR 0031): public, linked from the landing page.
Route::get('/who-does-what', WhoDoesWhatController::class)->name('who-does-what');
// Apply to join (ADR 0031): no account needed. Sending is limited per IP and guarded by a honeypot.
Route::get('/apply', [ApplyController::class, 'create'])->name('apply');
Route::post('/apply', [ApplyController::class, 'store'])->middleware('throttle:'.config('applications.per_ten_minutes', 3).',10,apply.store')->name('apply.store');
Route::get('/apply/status', [ApplyController::class, 'status'])->name('apply.status');
Route::post('/apply/status', [ApplyController::class, 'open'])->middleware('throttle:10,1,apply.status.open')->name('apply.status.open');
Route::post('/apply/status/sign-in', [ApplyController::class, 'signIn'])->middleware('throttle:10,1,apply.status.sign-in')->name('apply.status.sign-in');

// The pre-login demo pages now live behind login. Old links go to Log in.
Route::redirect('/demo/{any?}', '/login')->where('any', '.*');

// Login, logout and password reset routes come from Laravel Fortify
// (see App\Providers\FortifyServiceProvider). There is no registration.

// Rate limits: "throttle:N,1,<route name>" gives each route its own counter.
// Without the third part every throttle:N,1 route shares one counter per
// person, so one busy button would lock out the others (RateLimitsTest).

// Reviewers' magic links: /judge-access#<id>.<token> signs them straight in,
// read-only, until judging ends. Rate-limited per visitor IP.
Route::get('/judge-access', [JudgeLinkController::class, 'show'])->middleware('throttle:20,1,judge-access.show')->name('judge-access.show');
Route::post('/judge-access', [JudgeLinkController::class, 'store'])->middleware('throttle:10,1,judge-access.store')->name('judge-access.store');
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
        // One Redemption Fraud Detection call to Claude each, so rate-limited (AppServiceProvider).
        Route::post('/employee/redeem', [EmployeeWalletController::class, 'redeem'])->middleware('throttle:redeem')->name('employee.redeem');
        // Pay in person (ADR 0026): a one-time code for the merchant to confirm. Getting one moves nothing.
        Route::post('/employee/pay-code', [PayCodeController::class, 'store'])->middleware('throttle:10,1,employee.pay-code.store')->name('employee.pay-code.store');
        Route::post('/employee/pay-code/{payCode}/cancel', [PayCodeController::class, 'cancel'])->middleware('throttle:20,1,employee.pay-code.cancel')->name('employee.pay-code.cancel');
        Route::get('/employee/pay-code/{payCode}/status', [PayCodeController::class, 'status'])->middleware('throttle:90,1,employee.pay-code.status')->name('employee.pay-code.status');
        // Request a correction (ADR 0028): moves no money, a platform admin decides.
        Route::post('/employee/redemptions/{redemption}/correction', [CorrectionRequestController::class, 'employeeRedemption'])->middleware('throttle:5,1,employee.correction.store')->name('employee.correction.store');
    });

    Route::middleware('role:bid_admin,platform_admin')->prefix('bid-admin')->name('bid-admin.')->group(function () {
        Route::get('/', BidAdminController::class)->name('dashboard');
        Route::get('/eval', [FraudEvalController::class, 'admin'])->name('eval');
        Route::get('/participation', ParticipationController::class)->name('participation');
        Route::get('/impact', ImpactController::class)->name('impact');
        // The district's aggregate data, any time (ADR 0031: the partnership's data terms).
        Route::get('/impact.csv', ImpactExportController::class)->middleware('throttle:30,1,bid-admin.impact.csv')->name('impact.csv');
        Route::post('/redemptions/{redemption}/approve', [ReviewRedemptionController::class, 'approve'])->middleware('throttle:30,1,bid-admin.approve')->name('approve');
        Route::post('/redemptions/{redemption}/deny', [ReviewRedemptionController::class, 'deny'])->middleware('throttle:30,1,bid-admin.deny')->name('deny');
        // Admins only, never judges: a fresh synthetic collusion example through the real AI check (ADR 0016).
        Route::post('/collusion-example', CollusionExampleController::class)->middleware('throttle:5,1,bid-admin.collusion-example')->name('collusion-example');
        Route::post('/reset', ResetDemoController::class)->middleware('throttle:5,1,bid-admin.reset')->name('reset');

        // Corrections (ADR 0028): the BID admin and judges look; only the platform admin decides (decide-corrections).
        Route::get('/corrections', [CorrectionsController::class, 'index'])->name('corrections');
        Route::post('/corrections/{correction}/approve', [CorrectionsController::class, 'approve'])->middleware('throttle:30,1,bid-admin.corrections.approve')->name('corrections.approve');
        Route::post('/corrections/{correction}/reject', [CorrectionsController::class, 'reject'])->middleware('throttle:30,1,bid-admin.corrections.reject')->name('corrections.reject');
        Route::post('/corrections/{correction}/retry', [CorrectionsController::class, 'retry'])->middleware('throttle:20,1,bid-admin.corrections.retry')->name('corrections.retry');
        Route::post('/corrections/{correction}/close', [CorrectionsController::class, 'close'])->middleware('throttle:20,1,bid-admin.corrections.close')->name('corrections.close');

        // Fee settings (ADR 0029): the BID admin and judges look; only the platform admin changes it (change-merchant-fee).
        Route::get('/fees', [MerchantFeeController::class, 'index'])->name('fees');
        Route::post('/fees/review', [MerchantFeeController::class, 'review'])->middleware('throttle:20,1,bid-admin.fees.review')->name('fees.review');
        Route::post('/fees', [MerchantFeeController::class, 'store'])->middleware('throttle:10,1,bid-admin.fees.store')->name('fees.store');
        // Merchant applications (ADR 0031). The check asks the AI for a suggestion; a person decides.
        Route::get('/applications', [ApplicationController::class, 'index'])->name('applications');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereNumber('application')->name('applications.show');
        Route::post('/applications/{application}/check', [ApplicationController::class, 'check'])->whereNumber('application')->middleware('throttle:10,1,bid-admin.applications.check')->name('applications.check');
        Route::post('/applications/{application}/approve', [ApplicationController::class, 'approve'])->whereNumber('application')->middleware('throttle:30,1,bid-admin.applications.approve')->name('applications.approve');
        Route::post('/applications/{application}/decline', [ApplicationController::class, 'decline'])->whereNumber('application')->middleware('throttle:30,1,bid-admin.applications.decline')->name('applications.decline');
        // The BID partnership (ADR 0031): judges read it; a BID admin or the platform admin changes it in two steps.
        Route::get('/partnership', [PartnershipController::class, 'index'])->name('partnership');
        Route::post('/partnership/review', [PartnershipController::class, 'review'])->middleware('throttle:20,1,bid-admin.partnership.review')->name('partnership.review');
        Route::post('/partnership', [PartnershipController::class, 'store'])->middleware('throttle:10,1,bid-admin.partnership.store')->name('partnership.store');

        // Merchant Eligibility Matching review queue: real candidates, never participants.
        Route::get('/merchants', [MerchantEligibilityController::class, 'index'])->name('merchants');
        Route::post('/merchants/{candidate}/decisions', [MerchantEligibilityController::class, 'decide'])
            ->where('candidate', 'MC-\d{3}')->middleware('throttle:30,1,bid-admin.merchants.decide')->name('merchants.decide');
    });

    // Employer/HR dashboard. "Buy credits" is one step: Stripe's embedded Checkout on the page
    // (credits locked until the webhook confirms), or paid at once when simulated (ADR 0014, 0015).
    Route::middleware('role:employer_admin')->prefix('employer')->name('employer.')->group(function () {
        Route::get('/', [EmployerDashboardController::class, 'show'])->name('dashboard');
        Route::post('/program', [EmployerDashboardController::class, 'updateProgram'])->name('program.update');
        Route::post('/purchases', [EmployerDashboardController::class, 'storePurchase'])->middleware('throttle:20,1,employer.purchases.store')->name('purchases.store');
        Route::post('/purchases/{purchase}/pay', [EmployerDashboardController::class, 'payPurchase'])->middleware('throttle:20,1,employer.purchases.pay')->name('purchases.pay');
        Route::post('/purchases/{purchase}/checkout', [EmployerDashboardController::class, 'checkout'])->middleware('throttle:20,1,employer.purchases.checkout')->name('purchases.checkout');
        Route::get('/purchases/{purchase}/status', [EmployerDashboardController::class, 'purchaseStatus'])->middleware('throttle:90,1,employer.purchases.status')->name('purchases.status');
        Route::post('/purchases/{purchase}/cancel', [EmployerDashboardController::class, 'cancelPurchase'])->middleware('throttle:10,1,employer.purchases.cancel')->name('purchases.cancel');
        Route::post('/allocations', [EmployerDashboardController::class, 'allocate'])->name('allocations.store');
        // Refund of unspent credits (ADR 0028): a request only; a platform admin decides.
        Route::post('/corrections', [CorrectionRequestController::class, 'employerCredits'])->middleware('throttle:5,1,employer.correction.store')->name('correction.store');
    });
    Route::middleware('role:merchant')->group(function () {
        Route::get('/merchant', MerchantDashboardController::class)->name('merchant.dashboard');
        // Redemptions with subtotal, DC sales tax and total, for the merchant's records (ADR 0027). Reads only.
        Route::get('/merchant/redemptions.csv', RedemptionsExportController::class)->middleware('throttle:30,1,merchant.redemptions.csv')->name('merchant.redemptions.csv');
        // The merchant's own product menu (ADR 0023). Display only; never sent to the fraud AI.
        Route::get('/merchant/menu', [MenuController::class, 'show'])->name('merchant.menu');
        Route::post('/merchant/menu', [MenuController::class, 'store'])->middleware('throttle:20,1,merchant.menu.store')->name('merchant.menu.store');
        Route::post('/merchant/menu/{product}', [MenuController::class, 'update'])->middleware('throttle:30,1,merchant.menu.update')->name('merchant.menu.update');
        // Pay in person (ADR 0026): the customer's pay code. Misses are also limited to 5 a minute (PayCodes).
        // Confirm makes the redemption through RedemptionService, so one Redemption Fraud Detection call each.
        Route::post('/merchant/pay-code', [MerchantPayCodeController::class, 'lookup'])->middleware('throttle:30,1,merchant.pay-code.lookup')->name('merchant.pay-code.lookup');
        Route::post('/merchant/pay-code/clear', [MerchantPayCodeController::class, 'clear'])->middleware('throttle:30,1,merchant.pay-code.clear')->name('merchant.pay-code.clear');
        Route::post('/merchant/pay-code/{payCode}/confirm', [MerchantPayCodeController::class, 'confirm'])->middleware('throttle:20,1,merchant.pay-code.confirm')->name('merchant.pay-code.confirm');
        Route::post('/merchant/pay-code/{payCode}/decline', [MerchantPayCodeController::class, 'decline'])->middleware('throttle:20,1,merchant.pay-code.decline')->name('merchant.pay-code.decline');
        // Request a correction (ADR 0028): "Customer was charged wrongly" and "I wasn't paid". Requests only.
        Route::post('/merchant/redemptions/{redemption}/correction', [CorrectionRequestController::class, 'merchantRedemption'])->middleware('throttle:5,1,merchant.correction.redemption')->name('merchant.correction.redemption');
        Route::post('/merchant/payouts/{merchantPayout}/correction', [CorrectionRequestController::class, 'merchantPayout'])->middleware('throttle:5,1,merchant.correction.payout')->name('merchant.correction.payout');
        Route::post('/merchant/redemptions/{redemption}/cancel', [MerchantPayCodeController::class, 'cancelHeld'])->middleware('throttle:20,1,merchant.redemption.cancel')->name('merchant.redemption.cancel');
        // Stripe Express onboarding, test mode (ADR 0015). Only the merchant's own login; GETs create nothing.
        Route::post('/merchant/stripe/onboard', [StripeOnboardingController::class, 'start'])->middleware('throttle:10,1,merchant.stripe.onboard')->name('merchant.stripe.onboard');
        Route::post('/merchant/stripe/sync', [StripeOnboardingController::class, 'sync'])->middleware('throttle:20,1,merchant.stripe.sync')->name('merchant.stripe.sync');
        Route::get('/merchant/stripe/refresh', [StripeOnboardingController::class, 'refresh'])->name('merchant.stripe.refresh');
        Route::get('/merchant/stripe/return', [StripeOnboardingController::class, 'return'])->middleware('throttle:20,1,merchant.stripe.return')->name('merchant.stripe.return');
    });
    Route::middleware('role:judge')->group(function () {
        Route::get('/judge', [JudgeController::class, 'dashboard'])->name('judge.dashboard');
        // Read-only "view as" switcher. With no role, goes back to the judge's own view.
        Route::get('/judge/view-as/{role?}', [JudgeController::class, 'viewAs'])->name('judge.view-as');

        // "Help check the AI": blind votes on eval cases (ADR 0011). Every judge
        // can open the page; only named BID and contest-judge reviewers can vote.
        Route::get('/adjudicate', [AdjudicateController::class, 'show'])->name('adjudication.show');
        Route::middleware('throttle:60,1,adjudication')->group(function () {
            Route::post('/adjudicate/start', [AdjudicateController::class, 'start'])->name('adjudication.start');
            Route::post('/adjudicate/cases/{adjudicationCase}', [AdjudicateController::class, 'vote'])->name('adjudication.vote');
            Route::post('/adjudicate/submit', [AdjudicateController::class, 'submit'])->name('adjudication.submit');
            Route::post('/adjudicate/more', [AdjudicateController::class, 'more'])->name('adjudication.more');
            Route::post('/adjudicate/finish', [AdjudicateController::class, 'finish'])->name('adjudication.finish');
        });
    });
    // The business model (ADR 0030): reviewers, BID admin and platform admin; never logged-out visitors.
    // Personal contacts are kept to adjudication by KeepPersonalContactsToAdjudication.
    Route::middleware('role:judge,bid_admin,platform_admin')->group(function () {
        Route::get('/business-model', [BusinessModelController::class, 'show'])->name('business-model');
        Route::get('/business-model.csv', [BusinessModelController::class, 'csv'])->middleware('throttle:30,1,business-model.csv')->name('business-model.csv');
    });
    Route::middleware('role:platform_admin')->group(function () {
        Route::get('/admin', AccountController::class)->name('admin.dashboard');

        Route::prefix('admin/reviewers')->name('admin.reviewers.')->group(function () {
            Route::get('/', [ReviewerController::class, 'index'])->name('index');
            Route::post('/', [ReviewerController::class, 'store'])->name('store');
            Route::post('/{user}/link', [ReviewerController::class, 'link'])->name('link');
            Route::post('/{user}/revoke', [ReviewerController::class, 'revoke'])->name('revoke');
        });

        // Ledger next to Stripe, Stripe-funded activity only (ADR 0015).
        Route::get('/admin/reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation');
        Route::post('/admin/reconciliation/retry', [ReconciliationController::class, 'retry'])->middleware('throttle:10,1,admin.reconciliation.retry')->name('admin.reconciliation.retry');
        Route::post('/admin/stripe/demo-merchants', [ReconciliationController::class, 'connectDemoMerchants'])->middleware('throttle:30,1,admin.stripe.demo-merchants')->name('admin.stripe.demo-merchants');

        Route::get('/admin/feedback', [FeedbackInboxController::class, 'index'])->name('admin.feedback.index');
        Route::patch('/admin/feedback/{feedback}', [FeedbackInboxController::class, 'update'])->name('admin.feedback.update');
        Route::get('/admin/changelog', [ChangelogController::class, 'index'])->name('admin.changelog.index');
        Route::post('/admin/changelog', [ChangelogController::class, 'store'])->name('admin.changelog.store');

        // Blind adjudication of the fraud eval (ADR 0011): build, publish, close, results.
        Route::prefix('admin/adjudication')->name('admin.adjudication.')->group(function () {
            Route::get('/', [AdjudicationController::class, 'index'])->name('index');
            Route::post('/', [AdjudicationController::class, 'store'])->name('store');
            Route::post('/cases', [AdjudicationController::class, 'addCase'])->name('cases.store');
            Route::delete('/cases/{adjudicationCase}', [AdjudicationController::class, 'removeCase'])->name('cases.destroy');
            Route::post('/publish', [AdjudicationController::class, 'publish'])->name('publish');
            Route::post('/close', [AdjudicationController::class, 'close'])->name('close');
        });
    });
});

// Design system reference. Returns 404 outside the local environment.
Route::get('/styleguide', StyleguideController::class)->name('styleguide');
