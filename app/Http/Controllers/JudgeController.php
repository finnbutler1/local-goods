<?php

namespace App\Http\Controllers;

use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\ChangelogEntry;
use App\Models\Merchant;
use App\Models\User;
use App\Support\ViewAs;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class JudgeController extends Controller
{
    /**
     * The guided tour on the judge landing page, in order. Set 'available'
     * to true once a screen is really built; until then it says
     * "Coming in a later build".
     *
     * @return list<array{title: string, look_for: string, url: string, available: bool}>
     */
    public static function tour(): array
    {
        return [
            [
                'title' => 'Employee wallet',
                'look_for' => 'Your phone is the wallet: a balance funded by the employer, spent only at independent merchants in the BID. Confirming is switched off for reviewers; step 2 shows redemptions the AI already held. Try paying in person: open the wallet\'s Pay in person tab, then view as the merchant and enter the code. (Reviewers see a sample code and a disabled code field; the demo employee and merchant logins do it for real.)',
                'url' => route('judge.view-as', Role::Employee->value),
                'available' => true,
            ],
            [
                'title' => 'See tax at checkout',
                'look_for' => 'Order from a menu in the wallet. Each item shows its DC sales tax, and the total adds it up before paying; the merchant dashboard shows the tax collected, for the shop\'s own records.',
                'url' => ($grind = Merchant::where('name', config('demo.accounts.merchant.business'))->value('id'))
                    ? route('employee.wallet', ['merchant' => $grind]).'#redeem'
                    : route('judge.view-as', Role::Employee->value),
                'available' => true,
            ],
            [
                'title' => 'Fraud Review',
                'look_for' => 'Every redemption scored by Redemption Fraud Detection, with the anonymous signals it saw, its explanation and risk score, and the separate final decision a person made.',
                'url' => route('judge.view-as', Role::BidAdmin->value),
                'available' => true,
            ],
            [
                'title' => 'AI Eval',
                'look_for' => 'How often the AI agrees with hand-labeled cases, including cases it never saw while the prompt was written, and every disagreement.',
                'url' => route('bid-admin.eval'),
                'available' => true,
            ],
            [
                'title' => 'Merchant Eligibility',
                'look_for' => 'Real businesses in the BID, each marked as a candidate, not a participant. The AI says whether each looks independent, how sure it is and why, judging from its own knowledge (no web search). A BID admin approves, rejects or asks for research. Its eval against hand labels is Merchant Eval, in the AI & Evaluation group of the menu.',
                'url' => route('bid-admin.merchants'),
                'available' => true,
            ],
            [
                'title' => 'Employer dashboard',
                'look_for' => 'What the HR buyer sees: how many of their people got out into the neighborhood, visits, local businesses discovered and dollars spent locally, each with how it is counted. Buying credits, giving them out and program setup are shown with their buttons switched off for reviewers. Credits are paid through Stripe test mode, or simulated when Stripe is off.',
                'url' => route('judge.view-as', Role::EmployerAdmin->value),
                'available' => true,
            ],
            [
                'title' => 'Merchant dashboard',
                'look_for' => 'What a coffee shop sees: redemptions received, what it is owed and its payouts, simulated until Stripe test mode. Only cleared redemptions are owed; held ones wait for review.',
                'url' => route('judge.view-as', Role::Merchant->value),
                'available' => true,
            ],
            [
                'title' => 'Open Corrections to see how mistakes get fixed',
                'look_for' => 'Employees, merchants and HR ask for a refund or a payment they never got; the platform admin decides, always with a reason the requester sees. Every fix is a new ledger line backed by a Stripe test-mode action (a transfer reversal or a card refund), confirmed by Stripe\'s webhook, and reconciliation still matches. A seeded partial refund (with its tax) and a rejected request are there to look at; reviewers can\'t approve or ask.',
                'url' => route('bid-admin.corrections'),
                'available' => true,
            ],
        ];
    }

    /**
     * Changelog entries and the judge's own feedback marked fixed since their
     * previous visit. On a first visit: the latest few entries and every fix.
     *
     * @return array{since: ?CarbonInterface, entries: Collection, fixed: Collection}
     */
    public static function whatsNew(User $user): array
    {
        $since = $user->previous_visit_at;

        $entries = ChangelogEntry::latest('published_at')->latest('id')
            ->when($since, fn ($q) => $q->where('published_at', '>', $since), fn ($q) => $q->take(3))
            ->get();

        $fixed = $user->feedback()->where('status', FeedbackStatus::Fixed)
            ->when($since, fn ($q) => $q->where('status_changed_at', '>', $since))
            ->latest('status_changed_at')->get();

        return ['since' => $since, 'entries' => $entries, 'fixed' => $fixed];
    }

    public function dashboard(Request $request): View
    {
        // Landing here means the judge is back in their own view.
        $request->session()->forget(ViewAs::SESSION_KEY);

        return view('dashboards.judge', [
            'tour' => self::tour(),
            ...self::whatsNew($request->user()),
        ]);
    }

    /** Switch which role the judge is viewing the app as, then open that role's home page. */
    public function viewAs(Request $request, ?string $role = null): RedirectResponse
    {
        if ($role === null) {
            $request->session()->forget(ViewAs::SESSION_KEY);

            return redirect()->route('judge.dashboard');
        }

        $role = Role::tryFrom($role);
        abort_unless(in_array($role, ViewAs::ROLES, true), 404);

        $request->session()->put(ViewAs::SESSION_KEY, $role->value);

        return redirect()->route($role->dashboardRoute());
    }
}
