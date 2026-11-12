@php
    $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-3 font-mono text-lg tracking-widest focus-visible:border-primary';
    $codeError = $errors->first('code') ?: null;
    $recoveryError = $errors->first('recovery_code') ?: null;
@endphp

<x-guest-shell title="Enter your sign-in code">
    <h1 class="guest-title">Enter your sign-in code</h1>
    <p class="mt-3 text-muted">Two-step sign-in is on for this account. Open your authenticator app and enter the code it shows for Local Goods.</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}" data-busy="Checking…" class="mt-8 space-y-5">
        @csrf
        <x-field name="code" label="Code from your app" hint="Six digits. It changes every 30 seconds." :error="$codeError">
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
                   @unless ($recoveryError) autofocus @endunless
                   aria-describedby="code-hint{{ $codeError ? ' code-error' : '' }}" @if ($codeError) aria-invalid="true" @endif
                   class="{{ $input }} {{ $codeError ? 'border-hold' : 'border-edge' }}">
        </x-field>
        <x-button type="submit" size="lg" class="w-full">Log in</x-button>
    </form>

    <details class="mt-8" @if ($recoveryError) open @endif>
        <summary class="cursor-pointer font-semibold text-primary">Use a recovery code instead</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}" data-busy="Checking…" class="mt-4 space-y-5">
            @csrf
            <x-field name="recovery_code" label="Recovery code" hint="One of the codes you saved when you turned on two-step sign-in. Each works once." :error="$recoveryError">
                <input id="recovery_code" name="recovery_code" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" required
                       @if ($recoveryError) autofocus aria-invalid="true" @endif
                       aria-describedby="recovery_code-hint{{ $recoveryError ? ' recovery_code-error' : '' }}"
                       class="{{ $input }} {{ $recoveryError ? 'border-hold' : 'border-edge' }}">
            </x-field>
            <x-button type="submit" variant="secondary" class="w-full">Log in with a recovery code</x-button>
        </form>
    </details>

    <p class="mt-6 text-sm text-muted">Lost your phone and your recovery codes? Contact the admin.</p>
</x-guest-shell>
