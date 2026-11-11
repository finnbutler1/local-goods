@extends('layouts.adaptive')

@section('title', 'Security & Trust · Local Goods')

@php
    $link = 'font-semibold text-primary underline decoration-primary decoration-2 underline-offset-4 hover:decoration-primary-hover';
@endphp

@section('content')
    <div class="wrap">
        <header class="page-head">
            <x-station-sign as="p">Security &amp; Trust</x-station-sign>
            <h1 class="page-title">Security &amp; Trust</h1>
            <p class="page-lede">
                What Local Goods does today to protect money, access, data and decisions, in everyday language. Each item says which file or setting it comes from.
                We list what is not done yet too. This is a student prototype in Stripe test mode, and nothing here is a promise that it can't go wrong.
            </p>
        </header>

        <div class="stop-line stop-line-dense">
            @foreach ($groups as $key => $group)
                <section aria-labelledby="{{ $key }}-heading" id="{{ $key }}" class="stop scroll-mt-24">
                    <x-stop-number :n="$loop->iteration" />
                    <x-station-sign heading-id="{{ $key }}-heading">{{ $group['title'] }}</x-station-sign>
                    <p class="mt-4 max-w-[42rem] text-muted">{{ $group['intro'] }}</p>
                    <ul class="mt-4 max-w-[42rem]">
                        @foreach ($group['items'] as [$what, $label, $path])
                            <li class="border-b border-line py-3">
                                <p>{{ $what }}</p>
                                <p class="mt-1 text-sm text-muted">
                                    {{ $label }}@foreach ((array) $path as $p)@if ($loop->first): @else, @endif<a href="https://github.com/finnbutler1/local-goods/blob/main/{{ $p }}" class="{{ $link }}" rel="noopener"><code class="wrap-anywhere-safe">{{ $p }}</code></a>@endforeach
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            <section aria-labelledby="not-done-heading" id="not-done" class="stop scroll-mt-24">
                <x-stop-number :n="count($groups) + 1" />
                <x-station-sign heading-id="not-done-heading">What's not done yet</x-station-sign>
                <ul class="mt-4 max-w-[42rem]">
                    @foreach ($notDone as $item)
                        <li class="border-b border-line py-3">{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        </div>

        <p class="max-w-[42rem] pb-12 text-sm text-muted">
            Found a problem? Email <a href="mailto:butlerf@cua.edu" class="{{ $link }}"><x-breakable value="butlerf@cua.edu" /></a>.
            See the <a href="https://github.com/finnbutler1/local-goods/blob/main/SECURITY.md" class="{{ $link }}" rel="noopener">security policy</a>
            and our <a href="{{ url('/.well-known/security.txt') }}" class="{{ $link }}">security.txt</a>. To check these claims yourself, see
            <a href="https://github.com/finnbutler1/local-goods/blob/main/docs/security-verification.md" class="{{ $link }}" rel="noopener">how to verify</a>.
        </p>
    </div>
@endsection
