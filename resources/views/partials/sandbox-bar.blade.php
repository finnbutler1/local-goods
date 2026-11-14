{{-- On every page inside a "Try it yourself" sandbox (ADR 0045): what this is,
     the role bar (one click, no password) and Start over / Exit. Only rendered
     while Sandbox::active(); the forms' routes are 404 anywhere else. --}}
@php
    $current = \App\Services\Sandbox\SandboxRoutes::keyForRole(auth()->user()?->role);
@endphp
<div data-sandbox-bar class="border-b-2 border-accent bg-surface">
    <div class="wrap flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
        <p role="note" class="font-semibold">
            <x-badge tone="accent">Sandbox</x-badge>
            Sandbox: your own copy. Nothing here touches the live demo or Stripe. Deleted after 24 hours.
        </p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('sandbox.start') }}" data-busy="Starting over…">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Start over</x-button>
            </form>
            <form method="POST" action="{{ route('sandbox.exit') }}" data-busy="Leaving…">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Exit</x-button>
            </form>
        </div>
    </div>
    <nav aria-label="Sandbox roles" class="wrap pb-3">
        <p id="sandbox-roles-label" class="mb-1.5 text-sm text-muted">Switch role (no password needed):</p>
        <ul class="flex flex-wrap gap-2" aria-labelledby="sandbox-roles-label">
            @foreach (\App\Services\Sandbox\SandboxRoutes::ROLES as $key => [$label])
                <li>
                    <form method="POST" action="{{ route('sandbox.role') }}" data-busy="Switching…">
                        @csrf
                        <input type="hidden" name="role" value="{{ $key }}">
                        <x-button type="submit" size="sm" :variant="$current === $key ? 'primary' : 'secondary'"
                            aria-pressed="{{ $current === $key ? 'true' : 'false' }}">
                            <span class="sr-only">Switch to </span>{{ $label }}@if ($current === $key)<span class="sr-only"> (current)</span>@endif
                        </x-button>
                    </form>
                </li>
            @endforeach
        </ul>
    </nav>
</div>
