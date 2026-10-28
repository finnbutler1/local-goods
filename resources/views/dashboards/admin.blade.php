<x-app-shell title="Platform admin" active="dashboard" context="Local Goods platform">
    <div class="space-y-8">
        <header>
            <x-eyebrow class="text-accent-ink">Platform admin</x-eyebrow>
            <h1 class="mt-1 text-title font-semibold text-primary">Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                Every login is created by an admin; there is no public sign-up.
                You can also open fraud review to approve or deny held redemptions.
            </p>
            <p class="mt-3 flex flex-wrap gap-x-6 gap-y-2 font-semibold">
                <a href="{{ route('admin.reviewers.index') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Invite reviewers</a>
                <a href="{{ route('admin.feedback.index') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Feedback inbox</a>
                <a href="{{ route('admin.changelog.index') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Changelog for reviewers</a>
                <a href="{{ route('admin.reconciliation') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Stripe reconciliation</a>
                <a href="{{ route('bid-admin.corrections') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Corrections</a>
                <a href="{{ route('bid-admin.fees') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Fee settings</a>
                <a href="{{ route('bid-admin.applications') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Merchant applications</a>
                <a href="{{ route('bid-admin.partnership') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">BID partnership</a>
                <a href="{{ route('bid-admin.impact') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">BID impact</a>
                <a href="{{ route('business-model') }}" class="tap-target text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Business model</a>
            </p>
        </header>

        <section aria-labelledby="accounts-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="accounts-heading" class="text-2xl font-semibold text-primary">Accounts</h2>
                @if ($showRevoked)
                    <a href="{{ route('admin.dashboard') }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Hide revoked</a>
                @elseif ($revokedCount > 0)
                    <a href="{{ route('admin.dashboard', ['revoked' => 1]) }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show revoked ({{ $revokedCount }})</a>
                @endif
            </div>
            <p class="mt-1 text-sm text-muted">
                {{ $showRevoked ? 'Showing every account, revoked ones included.' : 'Revoked accounts are hidden.' }}
            </p>
            <x-rtable class="mt-3" label="Accounts table">
                <table data-rtable="stack" class="w-full text-left">
                    <caption class="sr-only">Accounts</caption>
                    <thead class="border-b border-line text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Name</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Email</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Role</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($users as $user)
                            <tr>
                                <td data-primary class="px-5 py-3 font-semibold">{{ $user->adminLabel() }}</td>
                                <td data-label="Email" class="px-5 py-3">@if ($user->adminEmail())<x-breakable :value="$user->adminEmail()" />@else—@endif</td>
                                <td data-label="Role" class="px-5 py-3"><x-badge tone="primary">{{ $user->role->label() }}</x-badge></td>
                                <td data-label="Status" class="px-5 py-3">
                                    @if ($user->isRevoked())
                                        <x-badge tone="neutral">Revoked</x-badge>
                                    @else
                                        <x-badge tone="ok">Active</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-rtable>
        </section>
    </div>
</x-app-shell>
