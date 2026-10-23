{{-- Blind adjudication results (ADR 0011). BID and contest judges make the
     headline; personal contacts (ADR 0019) get their own section below it and
     are never added into it. Needs $adjudication (AdjudicationResults::for),
     $adjudicationSet, $showNames (admin only) and $showCases (admin, or once voting closes). --}}
@php
    $all = $adjudication['all'];
    $unseen = $adjudication['unseen'];
    $personal = $adjudication['personal'] ?? null;
@endphp

<section id="adjudication" aria-labelledby="adjudication-heading" class="scroll-mt-24 space-y-5">
    <div class="max-w-3xl">
        <h2 id="adjudication-heading" class="text-2xl font-semibold text-primary">Blind adjudication of the disagreements</h2>
        <p class="mt-1 text-muted">
            Finn labeled every case, so where Finn and the AI disagree Finn may be the one who is wrong.
            Named BID staff and contest judges voted on those cases without seeing Finn's label, the AI's call or each other's votes.
            Cases where both agreed were mixed in as blind controls. Only adjudicators who submitted all their votes count.
            This is reported on its own: it does not change any label or any number above.
        </p>
        <p class="mt-2 text-sm text-muted">
            Version {{ $adjudicationSet->version }} ·
            {{ count($all['adjudicators']) }} {{ Str::plural('adjudicator', count($all['adjudicators'])) }} voted
            @if ($all['by_type'] !== [])({{ collect($all['by_type'])->map(fn ($n, $type) => "{$n} {$type}")->implode(', ') }})@endif ·
            {{ $adjudicationSet->isOpen() ? 'voting still open' : 'voting closed' }}
        </p>
    </div>

    @if (count($all['adjudicators']) > 0)
        @include('partials.adjudication-group', ['all' => $all, 'unseen' => $unseen, 'noun' => 'Adjudicator', 'plural' => 'adjudicators'])
    @else
        <p class="text-muted">No BID staff or contest judges have submitted yet.</p>
    @endif
</section>

@if ($personal)
    <section id="adjudication-personal" aria-labelledby="adjudication-personal-heading" class="scroll-mt-24 space-y-5 border-t-2 border-line pt-8">
        <div class="max-w-3xl">
            <h2 id="adjudication-personal-heading" class="text-2xl font-semibold text-primary">Personal contacts (know Finn): not counted in the result above</h2>
            <p class="mt-1 text-muted">
                Friends, family and classmates voted on the same cases, just as blind: they saw only the facts, never Finn's label, Finn's note or the AI's call.
                Because they know Finn, their votes are reported here on their own and are never added to the BID and contest-judge result.
            </p>
            <p class="mt-2 text-sm text-muted">
                {{ count($personal['all']['adjudicators']) }} {{ Str::plural('personal contact', count($personal['all']['adjudicators'])) }} voted
            </p>
        </div>
        @include('partials.adjudication-group', ['all' => $personal['all'], 'unseen' => $personal['unseen'], 'noun' => 'Contact', 'plural' => 'personal contacts'])
    </section>
@endif
