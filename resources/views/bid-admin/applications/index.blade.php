@php
    use App\Models\MerchantApplication;

    $link = 'font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary';
    $tone = fn (MerchantApplication $a) => match ($a->status) {
        MerchantApplication::LIVE, MerchantApplication::BID_APPROVED, MerchantApplication::STRIPE_ONBOARDING => 'ok',
        MerchantApplication::DECLINED => 'neutral',
        default => 'hold',
    };
@endphp

<x-app-shell title="Applications" active="applications" context="Golden Triangle BID · Washington, DC">
<div class="space-y-8">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Applications</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Shops asking to join</h1>
        <p class="mt-3 text-lg text-muted">
            Independent shops apply on the public <a href="{{ route('apply') }}" class="{{ $link }}">Apply to join</a> page. For each one,
            run the eligibility check (address and AI suggestion), then approve or decline with a reason the shop will see.
            The AI never decides.
        </p>
        <p class="mt-3 text-sm text-muted">{{ MerchantApplication::APPLICANT_LABEL }} Shops become participants only once approved and set up with Stripe.</p>
    </header>

    <p class="text-lg"><span class="font-semibold tabular">{{ $waiting }}</span> waiting for the BID.</p>

    @if ($applications->isEmpty())
        <x-card><p class="text-muted">No applications yet. Shops apply at <a href="{{ route('apply') }}" class="{{ $link }}">/apply</a>.</p></x-card>
    @else
        <x-rtable label="Applications table">
            <table data-rtable="stack" class="w-full text-left">
                <caption class="sr-only">Applications, waiting first</caption>
                <thead class="border-b border-line/70 text-sm text-muted">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-semibold sm:px-6">Shop</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Address</th>
                        <th scope="col" class="px-5 py-3 font-semibold">AI suggestion</th>
                        <th scope="col" class="px-5 py-3 font-semibold">Applied (DC time)</th>
                        <th scope="col" class="px-5 py-3 font-semibold"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/70">
                    @foreach ($applications as $application)
                        <tr data-application-row="{{ $application->status }}">
                            <td data-primary class="px-5 py-3 sm:px-6">
                                <span class="font-semibold">{{ $application->business_name }}</span>
                                <span class="block text-sm text-muted">{{ $application->categoryLabel() }}</span>
                                @if ($application->is_sample) <x-badge :wrap="true">{{ MerchantApplication::SAMPLE_LABEL }}</x-badge> @endif
                            </td>
                            <td data-label="Status" class="px-5 py-3"><x-badge :tone="$tone($application)" :wrap="true">{{ $application->statusLabel() }}</x-badge></td>
                            <td data-label="Address" class="px-5 py-3">
                                {{ $application->address }}
                                <span class="block text-sm text-muted">{{ match ($application->inside_district) { true => 'Inside the district', false => 'Outside the district', default => $application->status === MerchantApplication::APPLIED ? 'Not checked yet' : 'Not found' } }}</span>
                            </td>
                            <td data-label="AI suggestion" class="px-5 py-3">{{ $application->ai_classification ? str_replace('_', ' ', ucfirst($application->ai_classification)) : 'Not asked yet' }}</td>
                            <td data-label="Applied" class="px-5 py-3 tabular">{{ $application->created_at->setTimezone(config('fraud.timezone'))->format('M j, Y') }}</td>
                            <td data-label="" class="px-5 py-3"><a href="{{ route('bid-admin.applications.show', $application) }}" class="tap-target {{ $link }}">Open<span class="sr-only"> {{ $application->business_name }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-rtable>
    @endif
</div>
</x-app-shell>
