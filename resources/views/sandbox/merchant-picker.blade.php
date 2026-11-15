{{-- Sandbox only (ADR 0045 addendum): which merchant you are viewing as, and a
     labelled list to change it. A <details> disclosure, so it works without JavaScript.
     Posts to the same sandbox.role switch as before; pickable() is the server-side check. --}}
@php
    $choices = app(\App\Services\Sandbox\SandboxMerchant::class)->pickable();
@endphp
<div data-sandbox-merchant-header class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
    <p class="font-semibold">Viewing as {{ $merchant->name }}</p>
    @if ($choices->count() > 1)
        <details data-sandbox-merchant-picker class="w-full sm:w-auto">
            <summary class="tap-target cursor-pointer font-semibold text-primary underline decoration-2 underline-offset-4">Change merchant</summary>
            <form method="POST" action="{{ route('sandbox.role') }}" data-busy="Switching…" class="mt-2 rounded-xl border-2 border-edge bg-surface p-3">
                @csrf
                <input type="hidden" name="role" value="merchant">
                <fieldset>
                    <legend class="font-semibold">Choose which merchant to view as</legend>
                    <ul class="mt-2 grid max-h-64 gap-1 overflow-y-auto sm:grid-cols-2">
                        @foreach ($choices as $choice)
                            <li>
                                <label class="flex min-h-11 items-center gap-2">
                                    <input type="radio" name="merchant_id" value="{{ $choice->id }}" class="size-5 accent-primary" @checked($choice->id === $merchant->id) required>
                                    {{ $choice->name }}
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
                <x-button type="submit" size="sm" class="mt-3">Switch merchant</x-button>
            </form>
        </details>
    @endif
</div>
