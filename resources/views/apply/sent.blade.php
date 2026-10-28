@extends('layouts.app')

@section('title', 'Application sent · Local Goods')

@section('content')
    <div class="mx-auto max-w-3xl space-y-8 px-4 py-12 sm:px-6">
        @include('apply.partials.prototype-notice')

        <x-card as="section" aria-labelledby="sent-heading" class="space-y-4">
            <h1 id="sent-heading" class="text-title font-semibold text-primary" tabindex="-1" data-flash>Application sent</h1>
            @if ($link)
                <p class="text-lg">Thank you. <span class="font-semibold">Step 2 of 5: waiting for the eligibility check.</span></p>
                <div class="rounded-2xl border-2 border-primary p-4">
                    <p class="font-semibold">Save this private link now. It is shown only once and we don't send email.</p>
                    <p class="mt-2 text-sm text-muted">Anyone with the link can see your application and, once it is approved, set up the shop's payouts. Keep it to yourself.</p>
                    <label for="status-link" class="mt-3 block text-sm font-semibold">Your status link</label>
                    <input id="status-link" type="text" readonly value="{{ $link }}" class="mt-1 block w-full rounded-xl border-2 border-muted bg-surface px-3 py-2 font-mono">
                    <p class="mt-3"><a href="{{ $link }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Open my status page</a></p>
                </div>
            @else
                <p class="text-lg">Thank you. If you don't see a status link on this page, please apply again.</p>
            @endif
        </x-card>
    </div>
@endsection
