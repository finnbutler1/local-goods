@props(['current'])

{{-- In-page links between the two demo views and back home. --}}
<nav aria-label="Demo pages" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-semibold">
    <a href="{{ route('home') }}" class="underline underline-offset-4 hover:no-underline">
        <span aria-hidden="true">←</span> Home
    </a>
    @if ($current === 'employee')
        <a href="{{ route('demo.bid-admin') }}" class="underline underline-offset-4 hover:no-underline">
            See the BID admin view <span aria-hidden="true">→</span>
        </a>
    @else
        <a href="{{ route('demo.employee') }}" class="underline underline-offset-4 hover:no-underline">
            See the employee wallet <span aria-hidden="true">→</span>
        </a>
    @endif
</nav>
