@php use App\Support\PasswordResets; @endphp

<x-guest-shell title="Forgot password">
    <h1 class="text-3xl font-semibold text-primary">Forgot password</h1>

    @if (PasswordResets::byEmail())
        <p class="mt-1 text-muted">Enter the email you log in with and we'll send you a link to choose a new password.</p>

        @if (session('status'))
            <p role="status" class="mt-5 rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" data-busy="Sending…" class="mt-6 space-y-5">
            @csrf
            <x-field name="email" label="Email">
                <input id="email" name="email" type="email" autocomplete="username" required autofocus
                       value="{{ old('email') }}"
                       class="block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary @error('email') border-hold @else border-muted @enderror"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-field>
            <x-button type="submit" size="lg" class="w-full">Email me a reset link</x-button>
        </form>
    @else
        <p class="mt-1 text-muted">Contact the admin to reset your password.</p>
    @endif

    <p class="mt-6 text-sm">
        <a href="{{ route('login') }}" class="font-semibold text-primary underline decoration-line decoration-2 underline-offset-4 hover:decoration-primary">Back to log in</a>
    </p>
</x-guest-shell>
