@extends('layouts.app')

@section('title', 'Try It Yourself · Local Goods')

@section('content')
    <section aria-labelledby="sandbox-heading" class="wrap max-w-3xl py-12">
        <x-station-sign as="p">Your own copy of the demo</x-station-sign>
        <h1 id="sandbox-heading" class="page-title">Try It Yourself</h1>
        <p class="page-lede">
            Get a private copy of the demo and move credits through the real app: buy them as the employer, give them to an
            employee, pay at a merchant, see the payout and the district's impact, and decide a payment the fraud check held.
        </p>

        @if ($ended)
            <p role="status" class="mt-6 rounded-xl bg-band p-4 font-semibold">Your sandbox has ended. You can start a new one.</p>
        @endif

        <ul class="mt-6 list-disc space-y-2 pl-5">
            <li>It is yours alone: nobody else sees it, and nothing in it touches the live demo.</li>
            <li>Payments are simulated: no card is charged and Stripe is not contacted.</li>
            <li>The fraud check is the real one, with up to {{ config('sandbox.ai_calls_per_sandbox') }} AI checks per sandbox. After that, payments are held for a person to review.</li>
            <li>Switch between Employer (HR), Employee, Merchant, BID admin and Local Goods reviewer with one click. No passwords.</li>
            <li>It is deleted after {{ config('sandbox.lifetime_hours') }} hours, or when you press Exit.</li>
        </ul>

        @if ($ready)
            <form method="POST" action="{{ route('sandbox.start') }}" class="mt-8" data-busy="Making your copy…">
                @csrf
                <x-button type="submit" variant="accent" size="lg">Start my sandbox</x-button>
            </form>
        @else
            <p role="status" class="mt-8 rounded-xl bg-band p-4 font-semibold">{{ \App\Http\Controllers\SandboxController::PREPARING }}</p>
        @endif

        <p class="mt-6 text-sm text-muted">All people and businesses in the sandbox are fictional, as in the rest of the demo.</p>
    </section>
@endsection
