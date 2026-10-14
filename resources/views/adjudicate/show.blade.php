@php
    use App\Http\Controllers\AdjudicateController;
    use App\Models\AdjudicationCase;
    $money = fn ($v) => $v === '' || $v === null ? 'None yet' : '$'.number_format((float) $v, 2);
    $total = isset($cases) ? $cases->count() : 0;
    $vote = isset($case, $votes) && $case ? $votes->get($case->id) : null;
    $radio = 'size-5 accent-forest';
@endphp

<x-app-shell title="Help check the AI" active="dashboard" context="CUA Busch School AI Vibe Coding Contest">
<div class="max-w-3xl space-y-8">
    <header>
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Blind adjudication</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Help check the AI</h1>
        <p class="mt-3 text-lg text-muted">
            Our fraud check was tested against cases a person labeled by hand. Where the person and the AI disagree,
            the person may be the one who got it wrong. You'll see synthetic redemptions one at a time, with only the facts,
            and decide whether each looks <strong class="text-ink">normal</strong> or <strong class="text-ink">suspicious</strong>,
            for example a way to turn credits into cash. It takes about 15 minutes. Some are cases where the person and the AI disagreed and some are not;
            you won't be told which.
        </p>
    </header>

    <div aria-live="polite">
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">Not saved: {{ $errors->first() }}</p>
        @endif
    </div>

    @switch ($state)
        @case('none')
            <x-card>
                <h2 class="text-2xl font-semibold text-forest">Nothing to check right now</h2>
                <p class="mt-2 text-muted">There is no set of cases open for voting. Thank you for looking.</p>
            </x-card>
            @break

        @case('closed')
            <x-card>
                <h2 class="text-2xl font-semibold text-forest">Voting has closed</h2>
                <p class="mt-2 text-muted">Votes you saved but didn't submit are not counted. The results are on the <a href="{{ route('eval') }}" class="font-semibold text-forest underline underline-offset-4">AI eval page</a>.</p>
            </x-card>
            @break

        @case('submitted')
            <x-card class="border-2 border-ok">
                <h2 class="text-2xl font-semibold text-forest">Thank you, your votes are in</h2>
                <p class="mt-2">
                    You voted on all {{ $total }} cases. Votes are locked now.
                    You can open the <a href="{{ route('eval') }}" class="font-semibold text-forest underline underline-offset-4">AI eval page</a>
                    to see the person's labels and the AI's calls. Results from adjudicators appear there as people submit.
                </p>
            </x-card>
            @break

        @case('question')
            <x-card as="section" aria-labelledby="question-heading">
                <h2 id="question-heading" class="text-2xl font-semibold text-forest">Before you start</h2>
                <p class="mt-2 text-muted">
                    Please don't open the AI eval page until you've submitted: it shows the answers.
                    This question helps us report results fairly; any answer is fine.
                </p>
                <form method="POST" action="{{ route('adjudication.start') }}" class="mt-4 space-y-4">
                    @csrf
                    <fieldset>
                        <legend class="font-semibold">Have you looked at the Local Goods eval results page before today?</legend>
                        <div class="mt-2 flex gap-6">
                            @foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label)
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="seen_eval_before" value="{{ $value }}" required class="{{ $radio }}"
                                           @checked(old('seen_eval_before', $participant ? ($participant->seen_eval_before ? 'yes' : 'no') : null) === $value)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-button type="submit">{{ $participant ? 'Save and go back' : 'Start' }}</x-button>
                </form>
            </x-card>
            @break

        @default
            {{-- voting, or view-only for reviewers who can look but not vote --}}
            @if ($state === 'view-only')
                <p class="rounded-2xl border-2 border-line bg-cream-deep p-4">
                    <strong>You can look, but not vote.</strong> Votes come only from named BID staff and contest judges who
                    signed in with their own link, so every vote can be attributed. The form below is switched off.
                </p>
            @endif

            <nav aria-label="Cases" class="space-y-2">
                <p class="font-semibold">
                    @if ($case)
                        <span class="tabular text-2xl text-forest">{{ $n }} of {{ $total }}</span>
                    @else
                        <span class="text-2xl text-forest">Review and submit</span>
                    @endif
                    @if ($state === 'voting')
                        <span class="ml-2 text-muted">· {{ $answered }} answered</span>
                    @endif
                </p>
                <ol class="flex flex-wrap gap-1.5">
                    @foreach ($cases as $i => $c)
                        <li>
                            <a href="{{ route('adjudication.show', ['n' => $i + 1]) }}" @if ($case && $c->id === $case->id) aria-current="step" @endif
                               @class(['tabular grid size-9 place-items-center rounded-full text-sm font-semibold ring-1 ring-line aria-[current=step]:ring-2 aria-[current=step]:ring-forest',
                                       'bg-forest text-cream' => $votes->has($c->id), 'bg-paper text-ink' => ! $votes->has($c->id)])>
                                {{ $i + 1 }}<span class="sr-only">{{ $votes->has($c->id) ? ', answered' : ', not answered' }}</span>
                            </a>
                        </li>
                    @endforeach
                    @if ($state === 'voting')
                        <li><a href="{{ route('adjudication.show', ['n' => $total + 1]) }}" class="grid h-9 place-items-center rounded-full px-3 text-sm font-semibold text-forest ring-1 ring-line">Submit</a></li>
                    @endif
                </ol>
            </nav>

            @if ($case)
                <x-card as="section" aria-labelledby="case-heading">
                    <h2 id="case-heading" class="text-2xl font-semibold text-forest">Redemption {{ $n }}</h2>
                    <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        @foreach (AdjudicationCase::FACTS as $key => $label)
                            @php $value = $case->facts[$key] ?? ''; @endphp
                            <div @class(['sm:col-span-2' => $key === 'recent_history'])>
                                <dt class="text-sm font-semibold text-muted">{{ $label }}</dt>
                                <dd class="tabular mt-0.5 text-lg">
                                    @switch ($key)
                                        @case('amount') @case('balance_before') @case('median_past_amount') {{ $money($value) }} @break
                                        @case('category') {{ ucfirst($value) }} @break
                                        @default {{ $value }}
                                    @endswitch
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <form method="POST" action="{{ route('adjudication.vote', $case) }}" class="mt-6 space-y-4 border-t border-line/70 pt-5">
                        @csrf
                        <input type="hidden" name="n" value="{{ $n }}">
                        <fieldset @disabled($state !== 'voting') class="space-y-4">
                            <fieldset>
                                <legend class="font-semibold">Does this redemption look normal or suspicious?</legend>
                                <div class="mt-2 flex gap-6">
                                    @foreach (['normal' => 'Normal', 'suspicious' => 'Suspicious'] as $value => $label)
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="label" value="{{ $value }}" required class="{{ $radio }}" @checked(old('label', $vote?->label) === $value)>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <x-field name="reason" label="Why? (a sentence is enough)">
                                <textarea id="reason" name="reason" rows="3" required maxlength="{{ AdjudicateController::REASON_MAX }}"
                                          @if ($errors->has('reason')) aria-invalid="true" aria-describedby="reason-error" @endif
                                          class="block w-full rounded-xl border-2 border-muted bg-paper px-3 py-2.5 focus-visible:border-forest">{{ old('reason', $vote?->reason) }}</textarea>
                            </x-field>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-button type="submit">{{ $n < $total ? 'Save and next' : 'Save' }}</x-button>
                                @if ($n > 1)
                                    <x-button variant="ghost" :href="route('adjudication.show', ['n' => $n - 1])">Previous</x-button>
                                @endif
                                @if ($state === 'voting' || $n < $total)
                                    <x-button variant="ghost" :href="route('adjudication.show', ['n' => $n + 1])">{{ $n < $total ? 'Skip for now' : 'Go to submit' }}</x-button>
                                @endif
                            </div>
                        </fieldset>
                    </form>
                </x-card>
            @else
                @php $missing = $cases->keys()->filter(fn ($i) => ! $votes->has($cases[$i]->id))->map(fn ($i) => $i + 1); @endphp
                <x-card as="section" aria-labelledby="submit-heading">
                    <h2 id="submit-heading" class="text-2xl font-semibold text-forest">Submit all</h2>
                    @if ($missing->isNotEmpty())
                        <p class="mt-2">Still to answer: {{ $missing->implode(', ') }}. You can submit once every case has an answer.</p>
                    @else
                        <p class="mt-2">You've answered all {{ $total }}. You can still go back and change any of them. Once you submit, your votes are locked.</p>
                    @endif
                    <form method="POST" action="{{ route('adjudication.submit') }}" class="mt-4">
                        @csrf
                        <x-button type="submit" variant="accent" :disabled="$missing->isNotEmpty()">Submit all</x-button>
                    </form>
                    <p class="mt-4 text-sm text-muted">
                        You said you {{ $participant->seen_eval_before ? 'had' : 'had not' }} looked at the eval results page before today.
                        <a href="{{ route('adjudication.show', ['start' => 1]) }}" class="font-semibold text-forest underline underline-offset-4">Change</a>
                    </p>
                </x-card>
            @endif
    @endswitch
</div>
</x-app-shell>
