@php
    use Illuminate\Support\Carbon;

    $link = 'tap-target font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    // The status word in each column: large, bold, in the status's own color, always with its words.
    $status = fn (?bool $good) => 'money text-4xl '.match ($good) { true => 'text-ok', false => 'text-danger', default => 'text-ink' };
    $dc = fn ($at) => Carbon::parse($at)->setTimezone('America/New_York');
    $state = $activity['state'];
    $today = $activity['today'];
    // The stored check is an array, and the demo activity page needs all of it. Here only its result matters.
    $reconciled = $today['reconciliation'] === null ? null : (bool) ($today['reconciliation']['matches'] ?? false);
    $name = Str::before(auth()->user()->name, ' ');
@endphp

<x-app-shell title="Home" active="home" :context="$platform ? 'Local Goods platform' : 'Golden Triangle BID · Washington, DC'">
<div class="admin-desk space-y-12">
    <header>
        <x-station-sign as="p">{{ $platform ? 'Platform Admin' : 'BID Admin' }}</x-station-sign>
        <h1 class="page-title">Home</h1>
        <p class="mt-3 text-2xl font-bold tracking-tight text-ink">Welcome back, {{ $name }}.</p>
        <p class="page-lede">
            What needs a person, how the money checks are doing, and what happened lately. Every card links to its page;
            the menu has the rest. Nothing here is decided automatically.
        </p>
    </header>

    @include('partials.waiting-for-a-person')

    <div @class(['grid gap-x-8 gap-y-10 sm:grid-cols-2', 'xl:grid-cols-4' => $platform])>
        <section aria-labelledby="home-solvency-heading" class="rule-top flex flex-col" data-home-card="solvency">
            <h2 id="home-solvency-heading" class="figure-label">Solvency Check</h2>
            @if ($run)
                <p class="mt-2"><span class="{{ $status($run->passed) }}">{{ $run->passed ? 'Pass' : 'Fail' }}</span></p>
                <p class="mt-2 text-sm text-muted">
                    Last run <span class="tabular">{{ $dc($run->created_at)->format('M j, Y, g:i A') }}</span>, {{ $run->created_at->diffForHumans() }}, by {{ $run->triggerLabel() }}.
                </p>
                <p class="mt-2 text-sm">
                    {{ $run->passed
                        ? 'The money held has covered every promise at every moment checked.'
                        : 'At some moment the money held did not cover every promise. The money page names the window.' }}
                </p>
            @else
                <p class="mt-2 text-sm text-muted">No check has been recorded yet.</p>
            @endif
            <p class="mt-auto pt-3"><a href="{{ route('money') }}" class="{{ $link }}">Where the Money Is <span aria-hidden="true">→</span></a></p>
        </section>

        @if ($platform)
            <section aria-labelledby="home-reconciliation-heading" class="rule-top flex flex-col" data-home-card="reconciliation">
                <h2 id="home-reconciliation-heading" class="figure-label">Reconciliation</h2>
                @if ($reconciled === null)
                    <p class="mt-2"><span class="{{ $status(null) }}">Not checked today</span></p>
                    <p class="mt-2 text-sm text-muted">The page reads Stripe only when you open it.</p>
                @else
                    <p class="mt-2"><span class="{{ $status($reconciled) }}">{{ $reconciled ? 'Matches' : 'Mismatch' }}</span></p>
                    <p class="mt-2 text-sm text-muted">The latest check by the demo activity generator today.</p>
                @endif
                <p class="mt-auto pt-3"><a href="{{ route('admin.reconciliation') }}" class="{{ $link }}">Reconciliation <span aria-hidden="true">→</span></a></p>
            </section>
        @endif

        <section aria-labelledby="home-activity-heading" class="rule-top flex flex-col" data-home-card="demo-activity">
            <h2 id="home-activity-heading" class="figure-label">Demo Activity</h2>
            <p class="mt-2"><span class="{{ $status($state === 'on' ? true : null) }}">{{ match ($state) { 'on' => 'On', 'ended' => 'Ended', default => 'Off' } }}</span></p>
            @if ($state === 'on')
                <p class="mt-2 text-sm text-muted">
                    Today: {{ $today['done'] }} of {{ $today['planned'] }} done@if ($today['failed'] > 0), {{ $today['failed'] }} failed@endif.
                    @if ($today['halted']) The day was stopped.@endif
                </p>
            @else
                <p class="mt-2 text-sm text-muted">The scheduled generator is not running.</p>
            @endif
            <p class="mt-auto pt-3"><a href="{{ route('bid-admin.demo-activity') }}" class="{{ $link }}">Demo Activity <span aria-hidden="true">→</span></a></p>
        </section>

        @if ($platform)
            <section aria-labelledby="home-feedback-heading" class="rule-top flex flex-col" data-home-card="feedback">
                <h2 id="home-feedback-heading" class="figure-label">Feedback</h2>
                <p class="mt-2"><span class="{{ $status(null) }}">{{ $unreadFeedback }}</span></p>
                <p class="mt-2 text-sm text-muted">{{ $unreadFeedback === 0 ? 'No new feedback.' : $unreadFeedback.' new '.Str::plural('item', $unreadFeedback).' from reviewers.' }}</p>
                <p class="mt-auto pt-3"><a href="{{ route('admin.feedback.index') }}" class="{{ $link }}">Feedback <span aria-hidden="true">→</span></a></p>
            </section>
        @endif
    </div>

    @if ($platform)
        <section aria-labelledby="home-public-link-heading" class="rule-top" data-home-card="public-judge-link">
            <h2 id="home-public-link-heading" class="figure-label">Public judge link</h2>
            @if ($publicJudgeUrl)
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <label for="public-judge-link" class="sr-only">Public judge link</label>
                    <input id="public-judge-link" type="text" readonly value="{{ $publicJudgeUrl }}"
                           class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 font-mono focus-visible:border-primary" onfocus="this.select()">
                    <x-button data-copy="#public-judge-link" data-copy-status="#public-judge-link-status" class="shrink-0">Copy</x-button>
                </div>
                <p id="public-judge-link-status" role="status" class="sr-only"></p>
                <p class="mt-2 text-sm text-muted">Read-only access for judges and guests. Anyone with this link can look around but can't change anything.</p>
            @else
                <p class="mt-2 text-sm text-muted">Not set: add PUBLIC_JUDGE_URL in the Beanstalk environment properties.</p>
            @endif
        </section>
    @endif

    <section aria-labelledby="home-recent-heading" data-home-card="recent">
        <x-station-sign heading-id="home-recent-heading">Recent Activity</x-station-sign>
        @if ($recent->isEmpty())
            <x-empty-state class="mt-4">Nothing has been decided or asked for yet.</x-empty-state>
        @else
            <ul class="mt-4 border-t border-ink">
                @foreach ($recent as $row)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-line py-2.5">
                        <a href="{{ $row['url'] }}" class="{{ $link }}">{{ $row['text'] }}</a>
                        <time datetime="{{ $row['at']->toIso8601String() }}" class="tabular text-sm text-muted">{{ $dc($row['at'])->format('M j, g:i A') }}</time>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
</x-app-shell>
