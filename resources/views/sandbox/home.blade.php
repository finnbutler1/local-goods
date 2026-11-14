<x-app-shell title="Try This" active="sandbox">
<div class="max-w-3xl space-y-6">
    <header>
        <x-station-sign as="p">Your sandbox</x-station-sign>
        <h1 class="page-title">Try This</h1>
        <p class="page-lede text-muted">
            Seven steps through the whole money loop. Each button switches you to the right role and opens the page.
            Your sandbox is deleted {{ $expiresAt ? 'at '.$expiresAt->timezone('America/New_York')->format('g:i a \o\n M j').' (DC time)' : 'after 24 hours' }}.
        </p>
    </header>

    <ol class="space-y-3" aria-label="Try this checklist">
        @foreach (\App\Services\Sandbox\SandboxRoutes::CHECKLIST as $index => [$title, $how, $role])
            <li>
                <x-card as="section" aria-labelledby="step-{{ $index }}-heading" padding="p-4 sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h2 id="step-{{ $index }}-heading" class="font-display text-lg font-extrabold">{{ $index + 1 }}. {{ $title }}</h2>
                            <p class="mt-1 text-muted">{{ $how }}</p>
                        </div>
                        <form method="POST" action="{{ route('sandbox.role') }}" class="shrink-0" data-busy="Opening…">
                            @csrf
                            <input type="hidden" name="role" value="{{ $role }}">
                            <input type="hidden" name="step" value="{{ $index }}">
                            <x-button type="submit" size="sm" variant="secondary">
                                Go as {{ \App\Services\Sandbox\SandboxRoutes::ROLES[$role][0] }}<span class="sr-only">: {{ $title }}</span>
                            </x-button>
                        </form>
                    </div>
                </x-card>
            </li>
        @endforeach
    </ol>

    <p class="text-sm text-muted">
        AI checks left in this sandbox: {{ $aiLeft }} of {{ config('sandbox.ai_calls_per_sandbox') }}.
        After that (or if the day's sandbox checks run out), payments are held for a person to review, as when the AI can't be reached.
    </p>
</div>
</x-app-shell>
