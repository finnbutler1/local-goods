<?php

namespace App\Http\Controllers\Districts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Who does what" for BIDs (ADR 0031): the eight steps from a shop applying
 * to the BID's report, and for each one who handles it (the BID, Local
 * Goods or Stripe), what Local Goods' software does, what the BID does and
 * the real screen. Public; no fee or revenue figures.
 */
class WhoDoesWhatController extends Controller
{
    public const BID = 'BID';

    public const LOCAL_GOODS = 'Local Goods';

    public const STRIPE = 'Stripe';

    /**
     * key => title, who handles it, what our software does, what the BID
     * does, the screen (route name), who can open that screen.
     *
     * @var array<string, array{title: string, who: list<string>, software: string, bid: string, route: string, screen: string, access: string}>
     */
    public const STEPS = [
        'apply' => [
            'title' => 'Apply', 'who' => [self::LOCAL_GOODS],
            'software' => 'A public form with no account needed. It is rate-limited and has a spam trap, and it gives the shop a private link to follow its application.',
            'bid' => 'Points independent shops in the district to the form.',
            'route' => 'apply', 'screen' => 'Apply to join', 'access' => 'Anyone',
        ],
        'check' => [
            'title' => 'Check', 'who' => [self::LOCAL_GOODS, self::BID],
            'software' => 'Looks the address up in DC\'s Master Address Repository and checks it against the BID boundary DC publishes. It asks Merchant Eligibility Matching (AI) for a suggestion with reasons and a confidence.',
            'bid' => 'Presses "Run the eligibility check" and reads the result. The AI only suggests.',
            'route' => 'bid-admin.applications', 'screen' => 'Applications queue', 'access' => 'BID admin (judges can view)',
        ],
        'approve' => [
            'title' => 'Approve', 'who' => [self::BID],
            'software' => 'Refuses an address outside the boundary and never lets the AI approve. It keeps every decision with who, when and why, and shows the shop the reason.',
            'bid' => 'Decides: approves or declines each shop, with a reason.',
            'route' => 'bid-admin.applications', 'screen' => 'Applications queue', 'access' => 'BID admin (judges can view)',
        ],
        'onboard' => [
            'title' => 'Onboard', 'who' => [self::STRIPE, self::LOCAL_GOODS],
            'software' => 'Creates the shop\'s sign-in from its status link and opens Stripe Express. Stripe collects the bank and identity details; Local Goods never sees them.',
            'bid' => 'Nothing to do. It may answer the shop\'s questions.',
            'route' => 'merchant.dashboard', 'screen' => 'Merchant dashboard', 'access' => 'The shop\'s own sign-in (judges can view the demo shop)',
        ],
        'live' => [
            'title' => 'Live', 'who' => [self::LOCAL_GOODS],
            'software' => 'Once Stripe confirms the account can receive transfers, the shop goes on the district map and into employees\' wallets. Credits can\'t be spent at any shop that isn\'t approved and live in the district.',
            'bid' => 'Tells employers and staff about the new shop.',
            'route' => 'district', 'screen' => 'District map', 'access' => 'Anyone',
        ],
        'pay' => [
            'title' => 'Pay', 'who' => [self::LOCAL_GOODS, self::STRIPE, self::BID],
            'software' => 'Employees pay in the app. Redemption Fraud Detection checks every payment and holds anything unusual. Stripe transfers a cleared payment to the shop; a held one moves no money.',
            'bid' => 'Reviews held payments and approves or denies each one, with a reason.',
            'route' => 'bid-admin.dashboard', 'screen' => 'Fraud review', 'access' => 'BID admin (judges can view)',
        ],
        'reconcile' => [
            'title' => 'Reconcile', 'who' => [self::LOCAL_GOODS, self::STRIPE],
            'software' => 'Matches Local Goods\' ledger against Stripe\'s records: payments, transfers, reversals and refunds. It flags anything that doesn\'t match.',
            'bid' => 'Nothing. Local Goods\' platform admin does this, not the BID.',
            'route' => 'admin.reconciliation', 'screen' => 'Stripe reconciliation', 'access' => 'Local Goods platform admin only',
        ],
        'report' => [
            'title' => 'Report', 'who' => [self::LOCAL_GOODS, self::BID],
            'software' => 'Counts spending, visits, merchants reached and participating employers from the ledger, net of refunds. Employees are never named. The BID can export its district\'s aggregate data at any time.',
            'bid' => 'Uses the report with its members and the city, and downloads the CSV whenever it likes.',
            'route' => 'bid-admin.impact', 'screen' => 'BID impact', 'access' => 'BID admin (judges can view)',
        ],
    ];

    public function __invoke(Request $request): View
    {
        $step = array_key_exists((string) $request->query('step'), self::STEPS) ? (string) $request->query('step') : array_key_first(self::STEPS);

        return view('districts.who-does-what', ['steps' => self::STEPS, 'step' => $step]);
    }
}
