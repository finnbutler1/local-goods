@php
    use Illuminate\Support\Carbon;

    $link = 'tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $dc = fn ($at) => Carbon::parse($at)->setTimezone('America/New_York');
    $state = $activity['state'];
    $today = $activity['today'];
    $name = Str::before(auth()->user()->name, ' ');
@endphp

<x-app-shell title="Home" active="home" :context="$platform ? 'Local Goods platform' : 'Golden Triangle BID · Washington, DC'">
    <div class="space-y-8">
        <header class="max-w-3xl">
            <x-eyebrow class="text-accent-ink">{{ $platform ? 'Platform Admin' : 'BID Admin' }}</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">Home</h1>
            <p class="mt-2 text-xl font-semibold text-ink">Welcome back, {{ $name }}.</p>
            <p class="mt-3 text-lg text-muted">
                What needs a person, how the money checks are doing, and what happened lately. Every card links to its page;
                the menu has the rest. Nothing here is decided automatically.
            </p>
        </header>

        @include('partials.waiting-for-a-person')

        <div class="grid gap-4 lg:grid-cols-2">
            <x-card as="section" aria-labelledby="home-solvency-heading" data-home-card="solvency">
                <h2 id="home-solvency-heading" class="text-xl font-semibold text-primary">Solvency Check</h2>
                @if ($run)
                    <p class="mt-2 flex flex-wrap items-center gap-2">
                        <x-badge :tone="$run->passed ? 'ok' : 'danger'">{{ $run->passed ? 'Pass' : 'Fail' }}</x-badge>
                        <span class="text-sm text-muted">
                            Last run {{ $dc($run->created_at)->format('M j, Y, g:i A') }}, {{ $run->created_at->diffForHumans() }}, by {{ $run->triggerLabel() }}.
                        </span>
                    </p>
                    <p class="mt-2 text-sm text-muted">
                        {{ $run->passed
                            ? 'The money held has covered every promise at every moment checked.'
                            : 'At some moment the money held did not cover every promise. The money page names the window.' }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-muted">No check has been recorded yet.</p>
                @endif
                <p class="mt-3"><a href="{{ route('money') }}" class="{{ $link }}">Where the Money Is <span aria-hidden="true">→</span></a></p>
            </x-card>

            @if ($platform)
                <x-card as="section" aria-labelledby="home-reconciliation-heading" data-home-card="reconciliation">
                    <h2 id="home-reconciliation-heading" class="text-xl font-semibold text-primary">Reconciliation</h2>
                    <p class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($today['reconciliation'] === null)
                            <x-badge>Not checked today</x-badge>
                            <span class="text-sm text-muted">The page reads Stripe only when you open it.</span>
                        @else
                            <x-badge :tone="$today['reconciliation'] ? 'ok' : 'danger'">{{ $today['reconciliation'] ? 'Matches' : 'Mismatch' }}</x-badge>
                            <span class="text-sm text-muted">The latest check by the demo activity generator today.</span>
                        @endif
                    </p>
                    <p class="mt-3"><a href="{{ route('admin.reconciliation') }}" class="{{ $link }}">Reconciliation <span aria-hidden="true">→</span></a></p>
                </x-card>
            @endif

            <x-card as="section" aria-labelledby="home-activity-heading" data-home-card="demo-activity">
                <h2 id="home-activity-heading" class="text-xl font-semibold text-primary">Demo Activity</h2>
                <p class="mt-2 flex flex-wrap items-center gap-2">
                    <x-badge :tone="$state === 'on' ? 'ok' : 'neutral'">{{ match ($state) { 'on' => 'On', 'ended' => 'Ended', default => 'Off' } }}</x-badge>
                    @if ($state === 'on')
                        <span class="text-sm text-muted">
                            Today: {{ $today['done'] }} of {{ $today['planned'] }} done@if ($today['failed'] > 0), {{ $today['failed'] }} failed@endif.
                            @if ($today['halted']) The day was stopped.@endif
                        </span>
                    @else
                        <span class="text-sm text-muted">The scheduled generator is not running.</span>
                    @endif
                </p>
                <p class="mt-3"><a href="{{ route('bid-admin.demo-activity') }}" class="{{ $link }}">Demo Activity <span aria-hidden="true">→</span></a></p>
            </x-card>

            @if ($platform)
                <x-card as="section" aria-labelledby="home-feedback-heading" data-home-card="feedback">
                    <h2 id="home-feedback-heading" class="text-xl font-semibold text-primary">Feedback</h2>
                    <p class="mt-2 text-sm text-muted">{{ $unreadFeedback === 0 ? 'No new feedback.' : $unreadFeedback.' new '.Str::plural('item', $unreadFeedback).' from reviewers.' }}</p>
                    <p class="mt-3"><a href="{{ route('admin.feedback.index') }}" class="{{ $link }}">Feedback <span aria-hidden="true">→</span></a></p>
                </x-card>
            @endif
        </div>

        <section aria-labelledby="home-recent-heading" data-home-card="recent">
            <h2 id="home-recent-heading" class="text-2xl font-semibold text-primary">Recent Activity</h2>
            @if ($recent->isEmpty())
                <p class="mt-2 text-muted">Nothing has been decided or asked for yet.</p>
            @else
                <ul class="mt-3 divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
                    @foreach ($recent as $row)
                        <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 py-3">
                            <a href="{{ $row['url'] }}" class="{{ $link }}">{{ $row['text'] }}</a>
                            <time datetime="{{ $row['at']->toIso8601String() }}" class="text-sm text-muted">{{ $dc($row['at'])->format('M j, g:i A') }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-shell>
