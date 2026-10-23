<x-guest-shell title="Confirm your password">
    <h1 class="text-3xl font-semibold text-primary">Confirm your password</h1>
    <p class="mt-1 text-muted">This is a protected step. Enter your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm') }}" data-busy="Checking…" class="mt-6 space-y-5">
        @csrf
        <x-field name="password" label="Password">
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus
                   class="block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary @error('password') border-hold @else border-muted @enderror"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        </x-field>
        <x-button type="submit" size="lg" class="w-full">Confirm</x-button>
    </form>
</x-guest-shell>
