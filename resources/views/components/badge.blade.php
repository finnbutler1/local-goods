@props(['tone' => 'neutral', 'wrap' => false])

@php
    $tones = [
        'neutral' => 'bg-band text-ink',
        'primary' => 'bg-primary text-on-primary',
        'ok' => 'bg-ok-bg text-ok',
        'hold' => 'bg-hold-bg text-hold',
        'danger' => 'bg-danger-bg text-danger',
        'accent' => 'bg-accent text-on-accent',
    ];
@endphp

{{-- Short labels stay on one line; :wrap="true" lets a long one wrap on a narrow phone. --}}
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold', $wrap ? 'whitespace-normal' : 'whitespace-nowrap', $tones[$tone]]) }}>{{ $slot }}</span>
