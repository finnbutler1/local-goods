{{-- The cashier's "Enter pay code" field (ADR 0026). Never refilled with what was typed (bootstrap/app.php dontFlash). --}}
<div>
    <label for="pay_code" class="block font-semibold">Enter pay code</label>
    <input id="pay_code" name="pay_code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="7"
           inputmode="text" enterkeyhint="go" placeholder="ABC 234" @if ($disabled) disabled @endif
           class="tabular mt-1.5 block w-full rounded-xl border-2 border-muted bg-surface px-3 py-3 font-display text-3xl font-semibold uppercase tracking-[0.12em] text-ink focus-visible:border-primary"
           aria-describedby="pay_code-hint @error('pay_code') pay_code-error @enderror" @error('pay_code') aria-invalid="true" @enderror>
    <p id="pay_code-hint" class="mt-1.5 text-sm text-muted">The 6 characters on the customer's phone. Spaces don't matter.</p>
    @error('pay_code') <p id="pay_code-error" class="mt-1.5 text-sm font-semibold text-hold">{{ $message }}</p> @enderror
</div>
