{{-- resources/js/app.js moves the part of a status link after # into this field and sends it, so it never reaches a server log. --}}
<form method="POST" action="{{ route('apply.status.open') }}" data-status-link data-busy="Opening…" class="mt-4 space-y-3">
    @csrf
    <x-field name="link" id="status-link" label="Your status link">
        <input id="status-link" name="link" type="text" required maxlength="500" autocomplete="off" value="" class="block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2 font-mono"
               @error('link') aria-invalid="true" aria-describedby="status-link-error" @enderror>
    </x-field>
    <x-button type="submit">Open</x-button>
</form>
