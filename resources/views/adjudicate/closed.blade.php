{{-- The blind adjudication was retired (ADR 0041). Every old voting link lands here. --}}
<x-app-shell title="Voting has closed" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
<div class="max-w-3xl space-y-8">
    <header>
        <h1 class="page-title">Voting has closed</h1>
        <p class="page-lede">The independent check of the AI has ended, so there is nothing left to do here. Thank you for looking.</p>
    </header>

    {{-- Personal contacts can't open the judge home (ADR 0019), so no link for them. --}}
    @unless (auth()->user()->isPersonalContact())
        <p><a href="{{ route('judge.dashboard') }}" class="font-semibold text-primary underline underline-offset-4">Back to reviewer home</a></p>
    @endunless
</div>
</x-app-shell>
