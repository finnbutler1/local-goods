<x-app-shell title="Accounts" active="accounts" context="Local Goods platform">
    <div class="admin-desk space-y-12">
        <header>
            <x-station-sign as="p">Platform admin</x-station-sign>
            <h1 class="page-title">Accounts</h1>
            <p class="page-lede">
                Every login is created by an admin; there is no public sign-up.
            </p>
        </header>

        <section aria-labelledby="accounts-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="accounts-heading" class="text-2xl font-semibold text-primary">Every Login</h2>
                @if ($showRevoked)
                    <a href="{{ route('admin.accounts') }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Hide revoked</a>
                @elseif ($revokedCount > 0)
                    <a href="{{ route('admin.accounts', ['revoked' => 1]) }}" class="font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show revoked ({{ $revokedCount }})</a>
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
