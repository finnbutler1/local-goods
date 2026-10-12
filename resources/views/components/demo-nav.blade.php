@props(['current'])

{{-- In-page links between the two demo views and back home. --}}
<nav aria-label="Demo pages" class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-semibold text-forest">
    <a href="{{ route('home') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
        <span aria-hidden="true">←</span> Home
    </a>
    @if ($current === 'employee')
        <a href="{{ route('demo.bid-admin') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
            See the BID admin view <span aria-hidden="true">→</span>
        </a>
    @else
        <a href="{{ route('demo.employee') }}" class="underline decoration-line decoration-2 underline-offset-4 hover:decoration-forest">
            See the employee wallet <span aria-hidden="true">→</span>
        </a>
    @endif
</nav>
