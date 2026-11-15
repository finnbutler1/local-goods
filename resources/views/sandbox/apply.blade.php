@php
    use App\Http\Controllers\SandboxApplyController;
    use App\Models\MerchantApplication;
    use App\Services\Sandbox\SandboxRoutes;

    $input = 'block w-full rounded-xl border-2 border-edge bg-surface px-3 py-2.5 focus-visible:border-primary';
    $described = fn (string $id) => "{$id}-hint".($errors->has(str_replace('apply-', '', $id)) ? " {$id}-error" : '');
    $sent ??= false;
@endphp

<x-app-shell title="Apply as a New Shop" active="sandbox">
<div class="max-w-3xl space-y-8">
    <header>
        <x-station-sign as="p">Your sandbox</x-station-sign>
        <h1 class="page-title">Apply as a New Shop</h1>
        <p class="page-lede text-muted">
            Play an independent shop asking to join the {{ $district->name }}. Make one up: it stays in this sandbox and is deleted with it.
            A person at the BID decides; Merchant Eligibility Matching (AI) only suggests.
        </p>
    </header>

    @if ($sent)
        <x-card as="section" aria-labelledby="sent-heading" class="space-y-4" data-sandbox-application-sent>
            <h2 id="sent-heading" class="font-display text-xl font-extrabold">
                Application sent{{ $application ? ' for '.$application->business_name : '' }}.
            </h2>
            <p>Next, as the BID admin: open it in Merchant Applications, run the eligibility check, then approve or decline it with a reason.</p>
            <form method="POST" action="{{ route('sandbox.role') }}" data-busy="Opening…">
                @csrf
                <input type="hidden" name="role" value="bid_admin">
                <input type="hidden" name="step" value="{{ SandboxRoutes::JOIN_STEP }}">
                <x-button type="submit">Go as BID admin: Merchant Applications</x-button>
            </form>
        </x-card>
    @else
        <x-card as="section" aria-labelledby="form-heading">
            <h2 id="form-heading" class="font-display text-xl font-extrabold">The shop</h2>
            <form method="POST" action="{{ route('sandbox.apply.store') }}" data-busy="Sending the application…" class="mt-6 grid gap-6" novalidate>
                @csrf
                <x-field name="business_name" id="apply-business_name" label="Business name" hint="A made-up name.">
                    <input id="apply-business_name" name="business_name" type="text" required maxlength="120" autocomplete="off" value="{{ old('business_name') }}" class="{{ $input }}"
                           aria-describedby="{{ $described('apply-business_name') }}" @error('business_name') aria-invalid="true" @enderror>
                </x-field>
                <x-field name="address" id="apply-address" label="Shop address in DC"
                         hint="A real DC street address inside the district, like 1700 K St NW. It is looked up in DC's Master Address Repository ({{ config('sandbox.address_lookups_per_sandbox') }} lookups per sandbox). An address outside the district can't be approved.">
                    <input id="apply-address" name="address" type="text" required maxlength="200" autocomplete="off" value="{{ old('address') }}" class="{{ $input }}"
                           aria-describedby="{{ $described('apply-address') }}" @error('address') aria-invalid="true" @enderror>
                </x-field>
                <x-field name="category" id="apply-category" label="Category" hint="The closest fit.">
                    <select id="apply-category" name="category" required class="{{ $input }}" aria-describedby="{{ $described('apply-category') }}" @error('category') aria-invalid="true" @enderror>
                        <option value="">Choose one</option>
                        @foreach (MerchantApplication::CATEGORIES as $value => $label)
                            <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="independence_answer" id="apply-independence_answer" label="What makes you independent?" hint="Who owns it, how many locations, any franchise or parent company. 20 to 1,000 characters.">
                    <textarea id="apply-independence_answer" name="independence_answer" rows="4" required minlength="20" maxlength="1000" class="{{ $input }}"
                              aria-describedby="{{ $described('apply-independence_answer') }}" @error('independence_answer') aria-invalid="true" @enderror>{{ old('independence_answer') }}</textarea>
                </x-field>

                {{-- Honeypot: hidden from people and screen readers; only a bot fills it in. --}}
                <div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true">
                    <label for="apply-{{ SandboxApplyController::HONEYPOT }}">Leave this empty</label>
                    <input id="apply-{{ SandboxApplyController::HONEYPOT }}" name="{{ SandboxApplyController::HONEYPOT }}" type="text" tabindex="-1" autocomplete="off" value="">
                </div>

                <section aria-labelledby="privacy-heading" class="rule-heavy bg-band px-5 pb-4 text-sm" data-privacy-note>
                    <h3 id="privacy-heading" class="text-base font-bold">What happens to it</h3>
                    <ul class="mt-2 [&>li]:border-b [&>li]:border-line [&>li]:py-2 [&>li:last-child]:border-b-0">
                        <li>It is saved in this sandbox only, never on the live demo, and deleted with the sandbox.</li>
                        <li>No contact email is asked for and nothing is emailed.</li>
                        <li>When the BID admin runs the eligibility check, the business name, address and category go to an AI model (Anthropic's Claude) for a suggestion. That counts toward this sandbox's AI checks. Your answer about independence doesn't go to the AI.</li>
                    </ul>
                </section>

                <div><x-button type="submit">Send the application</x-button></div>
            </form>
        </x-card>
    @endif
</div>
</x-app-shell>
