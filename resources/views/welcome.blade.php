@extends('layouts.app')

@section('title', 'Local Goods: Spend local. Prove it.')

@section('content')
    <section class="mx-auto max-w-3xl px-4 py-16 sm:py-24">
        <p class="text-sm font-semibold uppercase tracking-widest text-muted">Golden Triangle · Washington, DC</p>
        <h1 class="mt-3 text-5xl sm:text-6xl font-semibold tracking-tight">Spend local. Prove it.</h1>
        <p class="mt-6 text-lg sm:text-xl leading-relaxed text-muted max-w-2xl">
            Give your team a perk they'll actually use: monthly credits that only work at the independent
            restaurants, shops and studios around your office, so people get out of the building and into the neighborhood.
        </p>
        <div class="mt-10 flex flex-col sm:flex-row gap-3">
            <a href="{{ route('demo.employee') }}"
               class="inline-flex justify-center rounded-lg bg-amber px-5 py-3 font-semibold text-forest shadow-sm hover:brightness-95">
                See an employee's wallet
            </a>
            <a href="{{ route('demo.bid-admin') }}"
               class="inline-flex justify-center rounded-lg border-2 border-forest px-5 py-3 font-semibold text-forest hover:bg-forest hover:text-cream">
                See the BID admin view
            </a>
        </div>
    </section>
@endsection
