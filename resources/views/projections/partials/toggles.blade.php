{{-- Scenario and case pickers for a projection page (ADR 0030): plain links, so they work
     without JavaScript; the picked one is aria-current. Needs $route, $scenario and $case. --}}
@php
    $pill = 'tap-target inline-flex min-h-11 items-center rounded-full border-2 px-4 py-2 text-sm font-semibold transition-colors';
    $on = 'border-primary bg-primary text-on-primary';
    $off = 'border-line bg-surface text-primary hover:border-primary hover:bg-primary-tint';
    $groups = [
        ['label' => 'Scenario', 'param' => 'scenario', 'current' => $scenario, 'options' => collect(config('projections.scenarios'))->map(fn ($s) => $s['name'])->all()],
        ['label' => 'Case', 'param' => 'case', 'current' => $case, 'options' => config('projections.cases')],
    ];
@endphp
<div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-8" data-projection-toggles>
    @foreach ($groups as $group)
        <nav aria-label="{{ $group['label'] }}">
            <p class="text-sm font-semibold text-muted">{{ $group['label'] }}</p>
            <ul class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($group['options'] as $value => $name)
                    @php $query = ['scenario' => $scenario, 'case' => $case, $group['param'] => $value]; @endphp
                    <li>
                        <a href="{{ route($route, $query) }}" class="{{ $pill }} {{ $group['current'] === $value ? $on : $off }}"
                           @if ($group['current'] === $value) aria-current="true" @endif>{{ $name }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endforeach
</div>
