<x-guest-shell title="Set your password">
    <h1 class="text-3xl font-semibold text-forest">Welcome, {{ Str::before($user->name, ' ') }}</h1>
    <p class="mt-2 text-muted">
        Choose a password for your Local Goods reviewer login. You'll use it with
        <span class="font-semibold text-ink break-all">{{ $user->email }}</span>.
        This link works once.
    </p>

    <form method="POST" action="{{ $action }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="email" value="{{ $user->email }}" autocomplete="username">

        <x-field name="password" label="Password" hint="At least 8 characters.">
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus
                   class="block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest @error('password') border-hold @else border-muted @enderror"
                   aria-describedby="password-hint @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
        </x-field>

        <x-field name="password_confirmation" label="Confirm password">
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                   class="block w-full rounded-xl border-2 border-muted bg-paper px-3 py-3 focus-visible:border-forest">
        </x-field>

        <x-button type="submit" size="lg" class="w-full">Save password and start</x-button>
    </form>
</x-guest-shell>
