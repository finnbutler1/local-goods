@php use App\Support\PasswordResets; @endphp

<x-guest-shell title="Log in">
    <h1 class="text-3xl font-semibold text-forest">Log in</h1>
    <p class="mt-1 text-muted">Accounts are created by the Local Goods team. There is no public sign-up.</p>
    <p class="mt-3 rounded-xl bg-cream-deep p-3 text-sm">
        <span class="font-semibold">Reviewing Local Goods?</span>
        Open the personal link Finn sent you, or the public judge link in the README. No password needed.
    </p>

    @if (session('status'))
        <p role="status" class="mt-5 rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf
        <x-field name="email" label="Email">
            <input id="email" name="email" type="email" autocomplete="username" required autofocus
                   value="{{ old('email') }}"
                   class="block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest @error('email') border-hold @else border-muted @enderror"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        </x-field>

        <x-field name="password" label="Password">
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   class="block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest @error('password') border-hold @else border-muted @enderror"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        </x-field>

        <label class="flex items-center gap-2">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-5 accent-forest">
            Keep me logged in on this device
        </label>

        <x-button type="submit" size="lg" class="w-full">Log in</x-button>
    </form>

    <p class="mt-6 text-sm text-muted">
        @if (PasswordResets::byEmail())
            <a href="{{ route('password.request') }}" class="font-semibold text-forest underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">Forgot password?</a>
        @else
            Forgot your password? Contact the admin to reset your password.
        @endif
    </p>
</x-guest-shell>
