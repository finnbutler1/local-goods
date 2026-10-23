<x-guest-shell title="Choose a new password">
    <h1 class="text-3xl font-semibold text-primary">Choose a new password</h1>

    <form method="POST" action="{{ route('password.update') }}" data-busy="Saving…" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-field name="email" label="Email">
            <input id="email" name="email" type="email" autocomplete="username" required
                   value="{{ old('email', $request->email) }}"
                   class="block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary @error('email') border-hold @else border-muted @enderror"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        </x-field>

        <x-field name="password" label="New password" hint="At least 8 characters.">
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus
                   class="block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary @error('password') border-hold @else border-muted @enderror"
                   aria-describedby="password-hint @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
        </x-field>

        <x-field name="password_confirmation" label="Confirm new password">
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                   class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 focus-visible:border-primary">
        </x-field>

        <x-button type="submit" size="lg" class="w-full">Save new password</x-button>
    </form>
</x-guest-shell>
