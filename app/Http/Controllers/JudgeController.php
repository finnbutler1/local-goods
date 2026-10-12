<?php

namespace App\Http\Controllers;

use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Models\ChangelogEntry;
use App\Models\User;
use App\Support\ViewAs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'look_for' => 'The phone is the card: a balance funded by the employer, spent only at independent merchants in the BID. Confirming is switched off for reviewers; step 2 shows redemptions the AI already held.',
                'url' => route('judge.view-as', Role::Employee->value),
                'available' => true,
            ],
            [
                'title' => 'BID fraud review',
                'look_for' => 'Every redemption scored by Redemption Fraud Detection, with the anonymous signals it saw, its explanation and risk score, and the separate final decision a person made.',
                'url' => route('judge.view-as', Role::BidAdmin->value),
                'available' => true,
            ],
            [
                'title' => 'AI eval results',
                'look_for' => 'How often the AI agrees with hand-labeled cases, including cases it never saw while the prompt was written, and every disagreement.',
                'url' => route('bid-admin.eval'),
                'available' => true,
            ],
            [
                'title' => 'Employer dashboard',
                'look_for' => 'What the HR buyer sees: credits funded, spent locally and employees taking part.',
                'url' => route('judge.view-as', Role::EmployerAdmin->value),
                'available' => false,
            ],
            [
                'title' => 'Merchant dashboard',
                'look_for' => 'What a coffee shop or salon sees: Local Goods sales, and payouts once Stripe Connect is built.',
                'url' => route('judge.view-as', Role::Merchant->value),
                'available' => false,
            ],
        ];
    }

    /**
     * Changelog entries and the judge's own feedback marked fixed since their
     * previous visit. On a first visit: the latest few entries and every fix.
     *
     * @return array{since: ?\Carbon\CarbonInterface, entries: \Illuminate\Support\Collection, fixed: \Illuminate\Support\Collection}
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

        return view('dashboards.judge', ['tour' => self::tour(), ...self::whatsNew($request->user())]);
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
