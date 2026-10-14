<x-guest-shell title="Link ended">
    <h1 class="text-3xl font-semibold text-forest">This link has ended</h1>
    <p class="mt-2 text-muted">
        Thanks for reviewing Local Goods. Reviewer links stop working when judging closes
        ({{ app(\App\Services\ReviewerLinks::class)->endsAt()->format('F j, Y \a\t g:i A') }} Eastern),
        or when Finn replaces or switches off a link. If you still need access, ask Finn for a new one.
    </p>
    <x-button :href="route('home')" size="lg" class="mt-6 w-full">Go to the public site</x-button>
</x-guest-shell>
