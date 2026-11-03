@php
    $user = auth()->user();
    $role = $user->role;
    $isJudge = $role === \App\Enums\Role::Judge;
@endphp

<x-app-shell title="What we store about you" active="account.data">
<div class="max-w-3xl space-y-8">
    <header>
        <x-eyebrow class="text-accent-ink">Your account</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">What we store about you</h1>
        <p class="mt-3 text-lg text-muted">In plain words, for the account you are signed in with. All people and transactions in this demo are fictional.</p>
    </header>

    <x-card as="section" aria-labelledby="data-account-heading">
        <h2 id="data-account-heading" class="text-2xl font-semibold text-primary">Your account</h2>
        <ul class="mt-3 list-disc space-y-1.5 pl-5">
            <li>Your name and email address, and your role ({{ $role->label() }}).</li>
            @if ($isJudge)
                <li>No password you know. Reviewers sign in with their link, and the account holds a random password nobody knows.</li>
            @else
                <li>A scrambled copy of your password. We never keep the password itself, and nobody here can read it.</li>
            @endif
            <li>When you last visited, and the visit before that, so pages can say what is new since you were here. The app does not record which pages you open.</li>
            @if ($isJudge)
                <li>When you first opened your link.</li>
            @endif
        </ul>
    </x-card>

    @if ($isJudge)
        <x-card as="section" aria-labelledby="data-link-heading">
            <h2 id="data-link-heading" class="text-2xl font-semibold text-primary">Your magic link</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5">
                <li>A scrambled copy (a hash) of your link's secret part. The link itself is not stored, so we cannot show it again.</li>
                <li>The date the account was set up, and the date the link was revoked, if it was.</li>
                <li>Your reviewer type (for example BID, contest judge or personal contact), which decides what you can open and whether your votes are counted in the headline.</li>
                <li>While you are signed in, your browser keeps a fingerprint of the link you used, so a new link ends the old session. Your session lives in a cookie in your browser.</li>
                <li>Your link stops working at the end of judging, or when an admin revokes it.</li>
            </ul>
        </x-card>
        <x-card as="section" aria-labelledby="data-votes-heading">
            <h2 id="data-votes-heading" class="text-2xl font-semibold text-primary">Votes in the independent check</h2>
            <p class="mt-3">If you vote in the blind check of the AI, we keep each vote (the label you chose and your reason), whether you had seen the evaluation before, which cases were assigned to you and when you submitted.</p>
        </x-card>
    @endif

    @if ($role === \App\Enums\Role::Employee)
        <x-card as="section" aria-labelledby="data-work-heading">
            <h2 id="data-work-heading" class="text-2xl font-semibold text-primary">Your credits and spending</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5">
                <li>Your staff profile: your display name and which employer you belong to.</li>
                <li>Each credit you were given and each payment you made: the amount, the shop, the date and the items you chose.</li>
                <li>Pay codes you asked for, for ten minutes each. The code is stored encrypted, plus a scrambled copy used to look it up.</li>
                <li>Any refund request you sent, and the answer.</li>
            </ul>
            <p class="mt-3">Other people see only your initials and a staff number, never your full name.</p>
        </x-card>
    @elseif ($role === \App\Enums\Role::Merchant)
        <x-card as="section" aria-labelledby="data-work-heading">
            <h2 id="data-work-heading" class="text-2xl font-semibold text-primary">Your shop</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5">
                <li>Your shop's public business details: name, category, address and map point.</li>
                <li>Your menu, and your sales, payouts and refunds. Customers show as initials.</li>
                <li>Your Stripe connection status. The bank details you gave Stripe stay with Stripe, not here.</li>
            </ul>
        </x-card>
    @elseif ($role === \App\Enums\Role::EmployerAdmin)
        <x-card as="section" aria-labelledby="data-work-heading">
            <h2 id="data-work-heading" class="text-2xl font-semibold text-primary">Your employer's program</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5">
                <li>Your employer's credit purchases, allowances and budgets.</li>
                <li>Which staff member received which credits. Staff show as initials and a staff number.</li>
            </ul>
        </x-card>
    @elseif ($role === \App\Enums\Role::BidAdmin || $role === \App\Enums\Role::PlatformAdmin)
        <x-card as="section" aria-labelledby="data-work-heading">
            <h2 id="data-work-heading" class="text-2xl font-semibold text-primary">Decisions you make</h2>
            <p class="mt-3">The decisions you record (approvals, denials, reasons) are kept with your name and role, and other pages show you as initials. They are never edited or deleted, so there is a lasting record of who decided what.</p>
        </x-card>
    @endif

    <x-card as="section" aria-labelledby="data-feedback-heading">
        <h2 id="data-feedback-heading" class="text-2xl font-semibold text-primary">Your feedback</h2>
        <p class="mt-3">Each note you send through the Feedback button: the page you were on, your category, rating, severity and comment, and its status. You can <a href="{{ route('account') }}#feedback" class="font-semibold underline">see and edit it here</a>.</p>
    </x-card>

    <x-card as="section" aria-labelledby="data-not-heading">
        <h2 id="data-not-heading" class="text-2xl font-semibold text-primary">What we do not store</h2>
        <ul class="mt-3 list-disc space-y-1.5 pl-5">
            <li>Card numbers or bank details. Those stay with Stripe.</li>
            <li>Your location, or which pages you browse.</li>
            <li>Anything sent to the AI that names you. The fraud check sees anonymous signals only.</li>
        </ul>
        <p class="mt-3">Money records are append-only by design, so a payment line cannot be erased.</p>
    </x-card>
</div>
</x-app-shell>
