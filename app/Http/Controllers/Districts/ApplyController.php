<?php

namespace App\Http\Controllers\Districts;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\MerchantApplication;
use App\Services\Districts\MerchantApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * "Apply to join" for independent shops (ADR 0031): no account needed,
 * limited per IP and guarded by a honeypot field. The private status link
 * is shown once, on the page after applying; we send no email. The status
 * page opens from that link (the token is after the #, so it never reaches
 * a server log) and keeps only the application id in the session.
 */
class ApplyController extends Controller
{
    public const PROTOTYPE_NOTICE = 'Local Goods is a student prototype in a contest, running in Stripe test mode. '
        .'Applying does not enroll your business in a real program and no money will move.';

    public const ACKNOWLEDGEMENT = 'I understand this is a prototype';

    /** A field people never see; only a bot fills it in. */
    public const HONEYPOT = 'website';

    public const SESSION_KEY = 'merchant_application_id';

    public const LINK_NOT_VALID = 'That status link isn\'t valid. Copy the whole link from the page you saw after applying, including everything after the # sign.';

    public function create(): View
    {
        return view('apply.create', ['district' => District::default()]);
    }

    public function store(Request $request, MerchantApplications $applications): View
    {
        // A bot filled the hidden field: answer as if all went well, store nothing.
        if (filled($request->input(self::HONEYPOT))) {
            return view('apply.sent', ['link' => null]);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(MerchantApplication::CATEGORIES))],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'independence_answer' => ['required', 'string', 'min:20', 'max:1000'],
            'prototype' => ['accepted'],
        ], [
            'prototype.accepted' => 'Tick "'.self::ACKNOWLEDGEMENT.'" to apply.',
            'independence_answer.min' => 'Tell us a little more: at least 20 characters.',
        ]);

        [$application, $token] = $applications->submit($data);

        return view('apply.sent', ['link' => MerchantApplications::statusLink($application, $token), 'application' => $application]);
    }

    public function status(Request $request): View
    {
        $application = MerchantApplication::with(['events.actorUser', 'district'])->find($request->session()->get(self::SESSION_KEY));

        return view('apply.status', ['application' => $application]);
    }

    /** The link's key ("<id>.<token>", or the whole link pasted) opens the status page for this browser session. */
    public function open(Request $request): RedirectResponse
    {
        $link = (string) $request->validate(['link' => ['required', 'string', 'max:500']])['link'];
        $application = MerchantApplications::forKey(str_contains($link, '#') ? substr($link, strrpos($link, '#') + 1) : $link);

        if ($application === null) {
            throw ValidationException::withMessages(['link' => self::LINK_NOT_VALID]);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $application->id);

        return redirect()->route('apply.status');
    }

    /** Step 4: sign in as the new merchant and go on to Stripe. Only from the open status page. */
    public function signIn(Request $request, MerchantApplications $applications): RedirectResponse
    {
        $application = MerchantApplication::find($request->session()->get(self::SESSION_KEY)) ?? abort(404);

        try {
            $user = $applications->startOnboarding($application);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('apply.status')->with('error', $e->getMessage());
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $application->id);

        return redirect()->route('merchant.dashboard')->with('status', 'Signed in as '.$application->business_name.'. Next: set up payouts with Stripe (test mode).');
    }
}
