<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * "Security & Trust": a plain-English list of the protections that exist, each
 * with the file or setting that backs it, and an honest list of what isn't
 * done. Public. Only claim what the code, config or DNS shows: when a
 * protection changes, change this list with it (SecurityPageTest checks the
 * files exist).
 */
class SecurityController extends Controller
{
    /**
     * group => [title, intro, items of [what we do, evidence label, evidence path or null]].
     *
     * @var array<string, array{title: string, intro: string, items: list<array{0: string, 1: string, 2: ?string}>}>
     */
    public const GROUPS = [
        'money' => [
            'title' => 'Money',
            'intro' => 'Payments run in Stripe test mode, so no real money is at stake. These checks are built for when it is.',
            'items' => [
                ['Every money movement is written to a ledger that can only be added to. An entry can\'t be edited after it is written.', 'The ledger model', 'app/Models/CreditLedgerEntry.php'],
                ['A solvency check refuses any money step that would leave Local Goods owing more than it holds, and a public page shows the result.', 'Solvency guard', 'app/Services/Solvency/SolvencyGuard.php'],
                ['Where the Money Is shows where the funds sit and whether the records agree. Judges see words and counts, not balances.', 'The page', 'resources/views/money'],
                ['Stripe runs in test mode only. Live keys are refused, and with no keys the app simulates payments instead.', 'Stripe mode', 'app/Support/StripeMode.php'],
                ['A payment the fraud check holds can never be paid out until a person approves it.', 'Transfer gate', 'app/Services/Payments/TransferGate.php'],
            ],
        ],
        'access' => [
            'title' => 'Access',
            'intro' => 'Who can see and do what.',
            'items' => [
                ['Judges are read-only. They can view the role screens, but a write is refused unless it is feedback or signing out.', 'Judge write block', 'app/Http/Middleware/BlockJudgeWrites.php'],
                ['Each role (employee, employer, merchant, BID admin, platform admin) only opens its own pages (judges can view them, read-only).', 'Role check', 'app/Http/Middleware/EnsureUserHasRole.php'],
                ['Sign-in allows 5 tries a minute, and payments, feedback and other write actions have their own limits. Tests send one request too many.', 'Rate-limit tests', 'tests/Feature/RateLimitsTest.php'],
                ['Judge sign-in links are stored only as a hash, so a database copy doesn\'t reveal a working link.', 'Reviewer links', 'app/Services/ReviewerLinks.php'],
            ],
        ],
        'data' => [
            'title' => 'Data',
            'intro' => 'We keep and show as little about people as we can.',
            'items' => [
                ['Merchants see an employee\'s initials, never a full name. The BID sees totals only. A test opens every page as every role and looks for names.', 'Name sweep test', 'tests/Feature/EmployeeNamePrivacyTest.php'],
                ['Merchant records are public business information only: no owner names or personal details.', 'Privacy test', 'tests/Feature/MerchantOutputPrivacyTest.php'],
                ['Every commit in the repository has been searched for keys and passwords, and the search runs on every push.', 'History scan', 'scripts/scan-history-for-secrets.sh'],
                ['The demo has no email service, and demo addresses are blocked from ever receiving mail.', 'Mail guard', 'app/Support/DemoMailGuard.php'],
            ],
        ],
        'ai' => [
            'title' => 'AI',
            'intro' => 'The AI helps. People decide.',
            'items' => [
                ['Redemption Fraud Detection fails safe: if the AI errors or replies in an unusable way, the payment is held, never cleared.', 'Fraud check', 'app/Services/RedemptionService.php'],
                ['Only a BID or platform admin can approve or deny a held payment, and must give a reason that is kept.', 'Review service', 'app/Services/RedemptionReviewService.php'],
                ['The fraud check receives anonymous signals only: no names, and no items, tax or corrections.', 'Input tests', 'tests/Feature/FraudInputTaxTest.php'],
                ['Merchant Eligibility Matching only suggests, with its reasoning and a confidence. A person makes every decision.', 'Review decisions', 'app/Models/MerchantReviewDecision.php'],
            ],
        ],
        'infrastructure' => [
            'title' => 'Infrastructure',
            'intro' => 'How the site is hosted and protected.',
            'items' => [
                ['The site is served over HTTPS, and the login cookie is only sent over HTTPS.', 'Production check', 'app/Support/ProductionConfig.php'],
                ['Only CloudFront can reach the server. A request that skips it is refused.', 'Origin lock', 'app/Http/Middleware/RequireCloudFrontOrigin.php'],
                ['The domain is set up so others can\'t send email pretending to be uselocalgoods.com (SPF and DMARC records in DNS).', 'Evidence: public DNS records for uselocalgoods.com', null],
                ['A deploy stops if debug mode is on or the HTTPS and origin settings are missing.', 'Deploy check', 'app/Support/ProductionConfig.php'],
                ['Every push checks the PHP and JavaScript packages for known problems, and a new one fails the build.', 'CI', '.github/workflows/ci.yml'],
                ['Browser security headers are on every page. The content policy is in report-only mode: it reports and blocks nothing yet.', 'Headers', 'app/Http/Middleware/SecurityHeaders.php'],
                ['There is a way to report a problem: a security.txt file and a short policy.', 'Policy', 'SECURITY.md'],
            ],
        ],
    ];

    /** @var list<string> */
    public const NOT_DONE = [
        'No independent security review or penetration test.',
        'No two-factor sign-in.',
        'This is a prototype in Stripe test mode with fictional people and businesses.',
        'The browser content policy only reports for now; it is not enforced.',
        'Rate limits are counted on one server and start again after each deploy.',
        'Package checks cover production packages only, and only known problems.',
    ];

    public function __invoke(): View
    {
        return view('security', ['groups' => self::GROUPS, 'notDone' => self::NOT_DONE]);
    }
}
