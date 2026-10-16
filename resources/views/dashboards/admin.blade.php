<x-app-shell title="Platform admin" active="dashboard" context="Local Goods platform">
    <div class="space-y-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                Every login is created by an admin; there is no public sign-up.
                You can also open fraud review to approve or reject held redemptions.
            </p>
            <p class="mt-3 flex flex-wrap gap-x-6 gap-y-2 font-semibold">
                <a href="{{ route('admin.reviewers.index') }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Invite reviewers</a>
                <a href="{{ route('admin.feedback.index') }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Feedback inbox</a>
                <a href="{{ route('admin.changelog.index') }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Changelog for reviewers</a>
                <a href="{{ route('admin.reconciliation') }}" class="text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Stripe reconciliation</a>
            </p>
        </header>

        <section aria-labelledby="accounts-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="accounts-heading" class="text-2xl font-semibold text-forest">Accounts</h2>
                @if ($showRevoked)
                    <a href="{{ route('admin.dashboard') }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Hide revoked</a>
                @elseif ($revokedCount > 0)
                    <a href="{{ route('admin.dashboard', ['revoked' => 1]) }}" class="font-semibold text-forest underline decoration-amber decoration-2 underline-offset-4 hover:decoration-forest">Show revoked ({{ $revokedCount }})</a>
                @endif
            </div>
            <p class="mt-1 text-sm text-muted">
                {{ $showRevoked ? 'Showing every account, revoked ones included.' : 'Revoked accounts are hidden.' }}
            </p>
            <x-card class="mt-3 overflow-x-auto" padding="p-0">
                <table class="w-full text-left">
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
                                <td class="px-5 py-3 font-semibold">{{ $user->name }}</td>
                                <td class="px-5 py-3 break-all">{{ $user->email }}</td>
                                <td class="px-5 py-3"><x-badge tone="forest">{{ $user->role->label() }}</x-badge></td>
                                <td class="px-5 py-3">
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
            </x-card>
        </section>
    </div>
</x-app-shell>
