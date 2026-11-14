<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxAiBudget;
use App\Services\Sandbox\SandboxFiles;
use App\Services\Sandbox\SandboxRoutes;
use App\Services\Sandbox\SandboxUserProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Try it yourself" (ADR 0045): start a private copy of the demo, switch
 * roles in it, start over, leave. The live site's judge rules are untouched:
 * these pages run on the sandbox's own session (UseSandbox), where no live
 * login exists.
 */
class SandboxController extends Controller
{
    public const PREPARING = 'The sandbox is being prepared — try again in a minute.';

    public function __construct(private Sandbox $sandbox, private SandboxFiles $files) {}

    /** The explainer with the Start button. Inside a sandbox, its checklist instead. */
    public function show(Request $request): View|RedirectResponse
    {
        if (Sandbox::active()) {
            return redirect()->route('sandbox.home');
        }

        return view('sandbox.show', [
            'ended' => $request->boolean('ended'),
            'ready' => $this->files->hasTodaysTemplate(),
        ]);
    }

    /** Start a sandbox, or start over (the old one is deleted: one per browser). */
    public function start(Request $request): RedirectResponse
    {
        // Counted in the live cache store, per visitor IP, so starting over inside a sandbox counts too.
        $limiter = new RateLimiter(Cache::store($this->sandbox->mainCacheStore()));
        $key = 'sandbox-create:'.$request->ip();
        $perHour = (int) config('sandbox.creations_per_hour');
        $back = fn (string $error) => Sandbox::active()
            ? redirect()->route('sandbox.home')->with('error', $error)
            : redirect()->route('sandbox.show')->with('error', $error);

        if ($limiter->tooManyAttempts($key, $perHour)) {
            return $back("You've started {$perHour} sandboxes from here in the last hour. Try again in ".ceil($limiter->availableIn($key) / 60).' minutes.');
        }

        $this->files->prune();
        if (! $this->files->hasTodaysTemplate()) {
            return $back(self::PREPARING);
        }

        $previous = $this->sandbox->id();
        $others = $this->files->liveCount() - ($previous !== null ? 1 : 0);
        if ($others >= (int) config('sandbox.max_live')) {
            return $back('Every sandbox is in use right now. Each one lasts 24 hours; please try again later.');
        }

        $limiter->hit($key, 3600);
        $token = SandboxFiles::newToken();
        $id = SandboxFiles::idFor($token);
        $this->files->createFromTemplate($id);

        if ($previous !== null) {
            $this->sandbox->deactivate();
            $this->files->destroy($previous);
        }

        $this->sandbox->activate($id);
        $this->signInAs('employer');
        $request->session()->regenerate();

        Cookie::queue(Cookie::make(Sandbox::COOKIE, $token, (int) config('sandbox.lifetime_hours') * 60, '/', null, null, true, false, 'lax'));

        return redirect()->route('sandbox.home')->with('status', $previous ? 'Started over with a fresh copy of the demo.' : 'Your sandbox is ready. You are the employer (HR).');
    }

    /** The "Try this" checklist. */
    public function home(Request $request, SandboxAiBudget $budget): View
    {
        if (! $request->user()) {
            $this->signInAs('employer');
        }

        return view('sandbox.home', [
            'expiresAt' => $this->files->expiresAt($this->sandbox->id()),
            'aiLeft' => max(0, (int) config('sandbox.ai_calls_per_sandbox') - $budget->usedInThisSandbox()),
        ]);
    }

    /** The role bar: one click, no password. Only inside a sandbox (route middleware sandbox:active). */
    public function role(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(SandboxRoutes::ROLES))],
            // A checklist step: opens that step's page, which must belong to the same role.
            'step' => ['nullable', 'integer', Rule::in(array_keys(SandboxRoutes::CHECKLIST))],
        ]);
        $this->signInAs($data['role']);
        $request->session()->regenerate();

        [$label, , $landing] = SandboxRoutes::ROLES[$data['role']];
        $step = isset($data['step']) ? SandboxRoutes::CHECKLIST[(int) $data['step']] : null;
        if ($step !== null && $step[2] === $data['role']) {
            $landing = $step[3];
        }

        return redirect()->route($landing)->with('status', "You're now the {$label}.");
    }

    /** Delete this sandbox and go back to the live site, signed in (or not) as before. */
    public function exit(Request $request): RedirectResponse
    {
        Auth::guard(Sandbox::GUARD)->logout();
        $request->session()->invalidate();
        // Deleted as soon as this request is finished with it (UseSandbox).
        $this->sandbox->endAfterRequest();

        Cookie::queue(Cookie::forget(Sandbox::COOKIE));
        Cookie::queue(Cookie::forget(Sandbox::SESSION_COOKIE));

        return redirect()->route('home');
    }

    private function signInAs(string $key): void
    {
        $user = User::where('email', SandboxRoutes::email($key))->first();
        abort_unless(SandboxUserProvider::isSandboxUser($user), 404);
        Auth::guard(Sandbox::GUARD)->login($user);
    }
}
