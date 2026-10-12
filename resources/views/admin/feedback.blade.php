@php
    use App\Enums\FeedbackCategory;
    use App\Enums\FeedbackSeverity;
    use App\Enums\FeedbackStatus;
    use App\Enums\ReviewerType;
    $select = 'block w-full rounded-xl border-2 border-muted bg-paper px-3 py-2.5 focus-visible:border-forest';
    $severityTone = ['blocker' => 'hold', 'should_fix' => 'amber', 'nice_to_have' => 'neutral'];
@endphp

<x-app-shell title="Feedback" active="feedback" context="Local Goods platform">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Feedback inbox</h1>
        <p class="mt-3 text-lg text-muted">
            Everything reviewers sent from the Feedback button. Mark items fixed with the commit that fixed them:
            reviewers see their fixed items under "What's new" on their next visit.
        </p>
    </header>

    <div aria-live="polite">
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">Nothing saved: {{ $errors->first() }}</p>
        @endif
    </div>

    <section aria-labelledby="averages-heading">
        <h2 id="averages-heading" class="text-2xl font-semibold text-forest">Average rating by rubric category</h2>
        <dl class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            @foreach (FeedbackCategory::cases() as $category)
                @php $row = $averages[$category->value] ?? null; @endphp
                <x-card padding="p-4" data-category="{{ $category->value }}">
                    <dt class="text-sm font-semibold text-muted">{{ $category->label() }}</dt>
                    <dd class="tabular mt-1 font-display text-3xl font-semibold text-forest">{{ $row ? number_format($row->average, 1) : '—' }}<span class="text-base text-muted">{{ $row ? ' / 5' : '' }}</span></dd>
                    <dd class="text-sm text-muted">{{ $row ? $row->reviews.' '.Str::plural('review', $row->reviews) : 'No reviews yet' }}</dd>
                </x-card>
            @endforeach
        </dl>
    </section>

    <section aria-labelledby="items-heading" class="space-y-4">
        <h2 id="items-heading" class="text-2xl font-semibold text-forest">Items <span class="text-muted">({{ $items->count() }})</span></h2>

        <form method="GET" action="{{ route('admin.feedback.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end" aria-label="Filter feedback">
            <x-field name="category" label="Category">
                <select id="category" name="category" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach (FeedbackCategory::cases() as $c)<option value="{{ $c->value }}" @selected(($filters['category'] ?? '') === $c->value)>{{ $c->label() }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="severity" label="Severity">
                <select id="severity" name="severity" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach (FeedbackSeverity::cases() as $c)<option value="{{ $c->value }}" @selected(($filters['severity'] ?? '') === $c->value)>{{ $c->label() }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="status" label="Status">
                <select id="status" name="status" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach (FeedbackStatus::cases() as $c)<option value="{{ $c->value }}" @selected(($filters['status'] ?? '') === $c->value)>{{ $c->label() }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="reviewer" label="Reviewer">
                <select id="reviewer" name="reviewer" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach ($reviewers as $r)<option value="{{ $r->id }}" @selected((string) ($filters['reviewer'] ?? '') === (string) $r->id)>{{ $r->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="reviewer_type" label="Reviewer type">
                <select id="reviewer_type" name="reviewer_type" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach (ReviewerType::cases() as $c)<option value="{{ $c->value }}" @selected(($filters['reviewer_type'] ?? '') === $c->value)>{{ $c->label() }}</option>@endforeach
                </select>
            </x-field>
            <div class="flex gap-2">
                <x-button type="submit">Filter</x-button>
                <x-button variant="ghost" :href="route('admin.feedback.index')">Clear</x-button>
            </div>
        </form>

        @if ($items->isEmpty())
            <p class="text-muted">No feedback matches.</p>
        @else
            <ul class="space-y-3">
                @foreach ($items as $item)
                    <x-card as="li" id="feedback-{{ $item->id }}" @class(['scroll-mt-24 border-l-4', 'border-l-amber' => $item->status === FeedbackStatus::New, 'border-l-line' => $item->status !== FeedbackStatus::New])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-semibold">
                                    {{ $item->category->label() }} · <span class="tabular">{{ $item->rating }}/5</span>
                                    <x-badge :tone="$severityTone[$item->severity->value]">{{ $item->severity->label() }}</x-badge>
                                    @if ($item->status === FeedbackStatus::New)<x-badge tone="amber">New</x-badge>@endif
                                </p>
                                <p class="text-sm text-muted">
                                    {{ $item->user->name }}{{ $item->user->reviewer_type ? ' · '.$item->user->reviewer_type->label() : ' · '.$item->user->role->label() }}
                                    · {{ $item->created_at->setTimezone('America/New_York')->format('M j, g:i A') }}
                                </p>
                                <p class="mt-1 text-sm break-all">
                                    @if ($item->safePageUrl())
                                        <a href="{{ $item->safePageUrl() }}" class="text-forest underline underline-offset-4">{{ $item->page_url }}</a>
                                    @else
                                        {{ $item->page_url }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <p class="mt-3 whitespace-pre-line">{{ $item->comment }}</p>

                        <form method="POST" action="{{ route('admin.feedback.update', $item) }}" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line/70 pt-4">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="status-{{ $item->id }}" class="block text-sm font-semibold">Status</label>
                                <select id="status-{{ $item->id }}" name="status" class="mt-1 {{ $select }}">
                                    @foreach (FeedbackStatus::cases() as $c)<option value="{{ $c->value }}" @selected($item->status === $c)>{{ $c->label() }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="sha-{{ $item->id }}" class="block text-sm font-semibold">Fixed in commit</label>
                                <input id="sha-{{ $item->id }}" name="fixed_commit_sha" type="text" value="{{ $item->fixed_commit_sha }}" placeholder="e.g. 4c6ac43"
                                       maxlength="40" autocomplete="off" spellcheck="false" class="mt-1 {{ $select }} font-mono">
                            </div>
                            <x-button type="submit" size="sm" variant="secondary">Save</x-button>
                        </form>
                    </x-card>
                @endforeach
            </ul>
        @endif
    </section>
</div>
</x-app-shell>
