@extends('layouts.app')

@section('title', 'Apply to join · Local Goods')

@php
    use App\Http\Controllers\Districts\ApplyController;
    use App\Models\MerchantApplication;

    $input = 'block w-full rounded-xl border-2 border-edge bg-surface px-3 py-2.5 focus-visible:border-primary';
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
    $readOnly = auth()->user()?->isReadOnly() ?? false;
    $described = fn (string $id) => "{$id}-hint".($errors->has(str_replace('apply-', '', $id)) ? " {$id}-error" : '');
@endphp

@section('content')
    <div class="wrap grid-12 pt-6 pb-12">
        <div class="col-span-12">@include('apply.partials.prototype-notice')</div>

        <header class="page-head col-span-12 pt-8 lg:col-span-8">
            <x-station-sign as="p">Apply to join</x-station-sign>
            <h1 class="page-title">Independent shops in the {{ $district->name }}</h1>
            <p class="page-lede">
                Local Goods credits are spent only at independent businesses inside the district. Tell us about your shop. A person at
                the BID decides; an AI check only suggests. No account needed.
            </p>
            <ol class="tour-line mt-8">
                @foreach ([
                    'You apply. You get a private link to follow your application.',
                    null,
                    'A BID admin approves or declines, with a reason you can see.',
                    'You sign in from your link and set up payouts with Stripe (test mode).',
                    'You\'re live: employees can spend credits at your shop.',
                ] as $i => $text)
                    <li class="!py-3">
                        <x-stop-number :n="$i + 1" />
                        <p class="self-center">
                            @if ($text === null)
                                We check your address is inside the <a href="{{ route('district') }}" class="{{ $link }}">district boundary</a> and ask Merchant Eligibility Matching (AI) for a suggestion.
                            @else
                                {{ $text }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ol>
        </header>

        @if ($readOnly)
            <p id="apply-read-only" class="col-span-12 mt-8 border-t-[3px] border-ink bg-band px-4 py-3 text-sm lg:col-span-8"><span class="font-semibold">Read-only.</span> Reviewers can see the form but not send it.</p>
        @endif

        <section aria-labelledby="form-heading" class="col-span-12 mt-12 lg:col-span-8">
            <x-station-sign heading-id="form-heading">Your shop</x-station-sign>
            <form method="POST" action="{{ route('apply.store') }}" data-busy="Sending your application…" class="mt-6 grid gap-6" novalidate>
                @csrf
                <fieldset class="contents" @disabled($readOnly)>
                    <x-field name="business_name" id="apply-business_name" label="Business name" hint="The name on your door.">
                        <input id="apply-business_name" name="business_name" type="text" required maxlength="120" autocomplete="organization" value="{{ old('business_name') }}" class="{{ $input }}"
                               aria-describedby="{{ $described('apply-business_name') }}" @error('business_name') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="address" id="apply-address" label="Shop address in DC" hint="Like 1700 K St NW. We check it against DC's Master Address Repository.">
                        <input id="apply-address" name="address" type="text" required maxlength="200" autocomplete="street-address" value="{{ old('address') }}" class="{{ $input }}"
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
                    <x-field name="contact_email" id="apply-contact_email" label="Contact email" hint="Used as your merchant sign-in if you are approved. We send no email.">
                        <input id="apply-contact_email" name="contact_email" type="email" required maxlength="254" autocomplete="email" value="{{ old('contact_email') }}" class="{{ $input }}"
                               aria-describedby="{{ $described('apply-contact_email') }}" @error('contact_email') aria-invalid="true" @enderror>
                    </x-field>
                    <x-field name="independence_answer" id="apply-independence_answer" label="What makes you independent?" hint="Who owns it, how many locations, any franchise or parent company. 20 to 1,000 characters.">
                        <textarea id="apply-independence_answer" name="independence_answer" rows="4" required minlength="20" maxlength="1000" class="{{ $input }}"
                                  aria-describedby="{{ $described('apply-independence_answer') }}" @error('independence_answer') aria-invalid="true" @enderror>{{ old('independence_answer') }}</textarea>
                    </x-field>

                    {{-- Honeypot: hidden from people and screen readers; only a bot fills it in. --}}
                    <div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true">
                        <label for="apply-{{ ApplyController::HONEYPOT }}">Leave this empty</label>
                        <input id="apply-{{ ApplyController::HONEYPOT }}" name="{{ ApplyController::HONEYPOT }}" type="text" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <section aria-labelledby="privacy-heading" class="rule-heavy bg-band px-5 pb-4 text-sm" data-privacy-note>
                        <h3 id="privacy-heading" class="text-base font-bold">Your information</h3>
                        <ul class="mt-2 [&>li]:border-b [&>li]:border-line [&>li]:py-2 [&>li:last-child]:border-b-0">
                            <li>We store what you type here: business name, address, category, contact email and your answer about independence.</li>
                            <li>The business name, address and category go to an AI model (Anthropic's Claude) for an eligibility suggestion. Your email and your answer don't.</li>
                            <li>Nothing is shared or sold.</li>
                            <li>Applications are deleted on request, or after {{ \Illuminate\Support\Carbon::parse(config('applications.retain_until'))->format('F j, Y') }}.</li>
                        </ul>
                    </section>

                    <div>
                        <label class="flex min-h-11 items-center gap-3 font-semibold">
                            <input type="checkbox" name="prototype" value="1" required class="size-5 accent-primary" @checked(old('prototype'))
                                   @error('prototype') aria-invalid="true" aria-describedby="apply-prototype-error" @enderror>
                            {{ ApplyController::ACKNOWLEDGEMENT }}
                        </label>
                        @error('prototype')<p id="apply-prototype-error" class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-hold"><span aria-hidden="true">⚑</span>{{ $message }}</p>@enderror
                    </div>

                    <div><x-button type="submit" :disabled="$readOnly">Send my application</x-button></div>
                </fieldset>
            </form>
        </section>

        <p class="col-span-12 mt-8 text-sm text-muted lg:col-span-8">Already applied? Open the private link you saved, or <a href="{{ route('apply.status') }}" class="{{ $link }}">paste it here</a>.</p>
    </div>
@endsection
