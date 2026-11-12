@php
    use Laravel\Fortify\Fortify;

    $user = auth()->user();
    $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-3 font-mono text-lg tracking-widest focus-visible:border-primary';
    $settingUp = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
    $isOn = $user->hasEnabledTwoFactorAuthentication();
    // Recovery codes are shown once: only on the page right after they are made.
    $showCodes = $isOn && in_array(session('status'), [Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED, Fortify::RECOVERY_CODES_GENERATED], true);
    $setupKey = $settingUp ? trim(chunk_split(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), 4, ' ')) : null;
    $codeError = $errors->confirmTwoFactorAuthentication->first('code') ?: null;
@endphp

<x-app-shell title="Two-Step Sign-In" active="account">
<div class="max-w-3xl space-y-8">
    <header>
        <x-station-sign as="p">Your account</x-station-sign>
        <h1 class="page-title">Two-Step Sign-In</h1>
        <p class="page-lede text-muted">Optional. After your password, sign-in also asks for a six-digit code from an authenticator app on your phone.</p>
    </header>

    @if ($isOn)
        <x-card as="section" aria-labelledby="two-step-status-heading">
            <h2 id="two-step-status-heading" class="text-2xl font-semibold text-primary">Two-step sign-in is on</h2>
            <p class="mt-2">Turned on {{ $user->two_factor_confirmed_at->copy()->timezone('America/New_York')->format('F j, Y') }}. Each time you log in with your password, you'll be asked for a code from your app.</p>
        </x-card>

        @if ($showCodes)
            <x-card as="section" aria-labelledby="recovery-codes-heading" class="rule-top">
                <h2 id="recovery-codes-heading" class="text-2xl font-semibold text-primary">Your recovery codes</h2>
                <p class="mt-2">If you lose your phone, each of these codes lets you log in once instead of a code from the app. <strong>Save them somewhere safe now: they won't be shown again.</strong></p>
                <ul aria-label="Recovery codes" class="mt-4 grid gap-2 font-mono text-lg sm:grid-cols-2">
                    @foreach ($user->recoveryCodes() as $code)
                        <li class="wrap-anywhere-safe rounded-lg bg-band px-3 py-2">{{ $code }}</li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <x-card as="section" aria-labelledby="two-step-manage-heading">
            <h2 id="two-step-manage-heading" class="text-2xl font-semibold text-primary">Recovery codes and turning it off</h2>
            <p class="mt-2">New recovery codes replace the old ones, which stop working.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" data-busy="Making new codes…">
                    @csrf
                    <x-button type="submit" variant="secondary">Get new recovery codes</x-button>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}" data-busy="Turning off…">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="secondary">Turn off two-step sign-in</x-button>
                </form>
            </div>
        </x-card>
    @elseif ($settingUp)
        <x-card as="section" aria-labelledby="two-step-setup-heading">
            <h2 id="two-step-setup-heading" class="text-2xl font-semibold text-primary">Finish setting up</h2>
            <p class="mt-2">Two-step sign-in is not on yet. It starts only after you enter a code from your app below.</p>

            <h3 class="mt-6 text-lg font-bold">1. Add Local Goods to your authenticator app</h3>
            <p class="mt-1">Scan this QR code with the app, or type the setup key instead.</p>
            <div class="mt-4 flex flex-wrap items-start gap-6">
                <div role="img" aria-label="QR code for your authenticator app. The setup key next to it holds the same information." class="w-fit rounded-lg bg-white p-4 [&_svg]:size-48">
                    {!! $user->twoFactorQrCodeSvg() !!}
                </div>
                <div>
                    <p class="font-bold">Setup key</p>
                    <p class="wrap-anywhere-safe mt-1 font-mono text-lg">{{ $setupKey }}</p>
                    <p class="mt-1 text-sm text-muted">Keep it to yourself: anyone with it can make your codes.</p>
                </div>
            </div>

            <h3 class="mt-6 text-lg font-bold">2. Enter the code the app shows</h3>
            <form method="POST" action="{{ route('two-factor.confirm') }}" data-busy="Checking…" class="mt-3 max-w-sm space-y-5">
                @csrf
                <x-field name="code" label="Code from your app" hint="Six digits. It changes every 30 seconds." :error="$codeError">
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
                           aria-describedby="code-hint{{ $codeError ? ' code-error' : '' }}" @if ($codeError) aria-invalid="true" @endif
                           class="{{ $input }} {{ $codeError ? 'border-hold' : 'border-muted' }}">
                </x-field>
                <x-button type="submit">Turn on two-step sign-in</x-button>
            </form>

            <form method="POST" action="{{ route('two-factor.disable') }}" data-busy="Cancelling…" class="mt-6">
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="ghost">Cancel setup</x-button>
            </form>
        </x-card>
    @else
        <x-card as="section" aria-labelledby="two-step-start-heading">
            <h2 id="two-step-start-heading" class="text-2xl font-semibold text-primary">Two-step sign-in is off</h2>
            <ul class="mt-3 list-disc space-y-1.5 pl-5">
                <li>You need an authenticator app on your phone (any app that makes six-digit sign-in codes).</li>
                <li>Nothing changes until you scan the code and enter one from the app.</li>
                <li>You'll get recovery codes to use if you lose your phone.</li>
            </ul>
            <form method="POST" action="{{ route('two-factor.enable') }}" data-busy="Starting…" class="mt-5">
                @csrf
                <x-button type="submit">Set up two-step sign-in</x-button>
            </form>
        </x-card>
    @endif

    <p><a href="{{ route('account') }}" class="tap-target font-semibold text-primary underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">Back to your account</a></p>
</div>
</x-app-shell>
