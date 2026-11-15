{{-- On every page inside a "Try it yourself" sandbox (ADR 0045): what this is,
     the role bar (one click, no password) and Start over / Exit. Only rendered
     while Sandbox::active(); the forms' routes are 404 anywhere else. --}}
@php
    $current = \App\Services\Sandbox\SandboxRoutes::keyForRole(auth()->user()?->role);
    // Which merchant to be (ADR 0045 addendum): the merchant login follows where the money went.
    $sandboxMerchants = app(\App\Services\Sandbox\SandboxMerchant::class);
    $merchantChoices = $sandboxMerchants->pickable();
    $merchantNow = $current === 'merchant' ? auth()->user()->merchant?->id : $sandboxMerchants->default()?->id;
@endphp
<div data-sandbox-bar class="border-b-2 border-accent bg-surface">
    <div class="wrap flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
        <p role="note" class="font-semibold">
            <x-badge tone="accent">Sandbox</x-badge>
            @if (\App\Services\Sandbox\SandboxStripe::available())
                Sandbox: your own copy. Payments use a separate Stripe test account: no real money, and nothing reaches the live demo. Deleted after 24 hours.
            @else
                Sandbox: your own copy. Nothing here touches the live demo or Stripe. Deleted after 24 hours.
            @endif
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
                @if ($key === 'merchant' && $merchantChoices->count() > 1)
                    <li>
                        <form method="POST" action="{{ route('sandbox.role') }}" data-busy="Switching…" data-sandbox-merchant-picker class="flex items-center gap-1.5">
                            @csrf
                            <input type="hidden" name="role" value="merchant">
                            <label for="sandbox-merchant-pick" class="sr-only">Choose which merchant to be</label>
                            <select id="sandbox-merchant-pick" name="merchant_id" class="max-w-60">
                                @foreach ($merchantChoices as $choice)
                                    <option value="{{ $choice->id }}" @selected($choice->id === $merchantNow)>{{ $choice->name }}</option>
                                @endforeach
                            </select>
                            <x-button type="submit" size="sm" variant="secondary">Switch<span class="sr-only"> to this merchant</span></x-button>
                        </form>
                    </li>
                @endif
            @endforeach
        </ul>
    </nav>
</div>
