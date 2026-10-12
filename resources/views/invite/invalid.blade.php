<x-guest-shell title="Link not valid">
    <h1 class="text-3xl font-semibold text-forest">This link has expired or was already used</h1>
    <p class="mt-2 text-muted">
        Invite links work once and only for 7 days. Ask Finn for a new one.
        If you already set your password, you can log in instead.
    </p>
    <x-button :href="route('login')" size="lg" class="mt-6 w-full">Log in</x-button>
</x-guest-shell>
