@php $input = 'block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest'; @endphp

<x-app-shell title="Account" active="account">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Your account</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">{{ auth()->user()->name }}</h1>
        <p class="mt-3 text-lg text-muted break-all">{{ auth()->user()->email }}</p>
    </header>

    <x-card as="section" aria-labelledby="password-heading" class="max-w-xl">
        <h2 id="password-heading" class="text-2xl font-semibold text-forest">Change your password</h2>
        <div aria-live="polite">
            @if (session('status') === 'password-updated')
                <p class="mt-3 rounded-xl bg-ok-bg p-3 font-semibold text-ok">Your password was changed.</p>
            @endif
        </div>
        <form method="POST" action="{{ route('user-password.update') }}" class="mt-4 space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="email" value="{{ auth()->user()->email }}" autocomplete="username">

            <x-field name="current_password" label="Current password" :error="$errors->updatePassword->first('current_password') ?: null">
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required
                       class="{{ $input }} {{ $errors->updatePassword->has('current_password') ? 'border-hold' : 'border-muted' }}">
            </x-field>
            <x-field name="password" label="New password" hint="At least 8 characters." :error="$errors->updatePassword->first('password') ?: null">
                <input id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="password-hint"
                       class="{{ $input }} {{ $errors->updatePassword->has('password') ? 'border-hold' : 'border-muted' }}">
            </x-field>
            <x-field name="password_confirmation" label="Confirm new password">
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                       class="{{ $input }} border-muted">
            </x-field>
            <x-button type="submit">Change password</x-button>
        </form>
    </x-card>
</div>
</x-app-shell>
