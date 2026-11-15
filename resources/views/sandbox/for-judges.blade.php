{{-- "For judges": what the live demo has that a sandbox doesn't (ADR 0045).
     Inside a sandbox the button is the existing Exit (it deletes the sandbox);
     on the /sandbox start page it is a plain link to the live demo. --}}
@props(['inSandbox' => false])
<section data-for-judges aria-labelledby="for-judges-heading" class="rounded-2xl border-2 border-edge bg-surface p-4 sm:p-5">
    <h2 id="for-judges-heading" class="font-display text-lg font-extrabold">For judges</h2>
    <p class="mt-1 text-muted">The live demo has a few things a sandbox leaves out:</p>
    <ul class="mt-2 list-disc space-y-1 pl-5">
        <li>The AI evaluation, with the cases the AI never saw.</li>
        <li>Where the Money Is, on the live ledger.</li>
        <li>The Local Goods admin views.</li>
        <li>The Feedback button: feedback is left from the live demo.</li>
    </ul>
    @if ($inSandbox)
        <form method="POST" action="{{ route('sandbox.exit') }}" data-busy="Leaving…" class="mt-4">
            @csrf
            <x-button type="submit" variant="secondary">Exit to the live demo</x-button>
        </form>
        <p class="mt-2 text-sm text-muted">Exiting deletes this sandbox.</p>
    @else
        @php $liveDemo = app(\App\Support\PublicJudgeLink::class)->url(); @endphp
        <x-button variant="secondary" class="mt-4" rel="nofollow" :href="$liveDemo ?: route('home')">Explore the live demo (read-only)</x-button>
    @endif
</section>
