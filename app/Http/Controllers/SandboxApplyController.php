<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\MerchantApplication;
use App\Services\Districts\MerchantApplications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "A new shop joins", inside a sandbox only (ADR 0045 addendum): the
 * sandbox's own Apply to join page. It saves through the same
 * MerchantApplications::submit as the public form, into the sandbox's file.
 * No contact email is asked for (a fixed fictional one is stored, so no
 * visitor's address is kept), nothing is emailed, and there is no status
 * link: the BID admin's Merchant Applications page shows every step.
 * Its routes sit in the sandbox:active group, so they 404 everywhere else.
 */
class SandboxApplyController extends Controller
{
    /** Stored on every sandbox application. Never a login: in a sandbox the shop gets none. */
    public const CONTACT_EMAIL = 'new-shop@example.com';

    public const HONEYPOT = Districts\ApplyController::HONEYPOT;

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('sandbox.home');
        }

        return view('sandbox.apply', ['district' => District::default(), 'application' => null]);
    }

    public function store(Request $request, MerchantApplications $applications): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('sandbox.home');
        }
        // A bot filled the hidden field: answer as if all went well, store nothing.
        if (filled($request->input(self::HONEYPOT))) {
            return view('sandbox.apply', ['district' => District::default(), 'application' => null, 'sent' => true]);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(MerchantApplication::CATEGORIES))],
            'independence_answer' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'independence_answer.min' => 'Tell us a little more: at least 20 characters.',
        ]);

        [$application] = $applications->submit([...$data, 'contact_email' => self::CONTACT_EMAIL]);

        return view('sandbox.apply', ['district' => District::default(), 'application' => $application, 'sent' => true]);
    }
}
