{{-- A reviewer's magic link lands here. The part of the link after # never
     reaches the server; resources/js/app.js moves it into this form, clears
     it from the address bar and submits. No password, no account setup. --}}
<x-guest-shell title="Signing you in">
    <h1 class="text-3xl font-semibold text-primary">Signing you in…</h1>
    <p class="mt-2 text-muted" id="magic-link-status" aria-live="polite">
        Opening your read-only reviewer view of Local Goods. This takes a second.
    </p>

    <p id="magic-link-missing" hidden class="mt-5 rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">
        This link looks incomplete. Copy the whole link from Finn's message, including everything after the # sign, and open it again.
    </p>

    <noscript>
        <p class="mt-5 rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">
            Your reviewer link needs JavaScript to sign you in. Turn JavaScript on for this site and open the link again.
        </p>
    </noscript>

    <form method="POST" action="{{ route('judge-access.store') }}" data-magic-link class="mt-6">
        @csrf
        <input type="hidden" name="link" value="">
        <x-button type="submit" size="lg" class="w-full">Continue</x-button>
    </form>
</x-guest-shell>
