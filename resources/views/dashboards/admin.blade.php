<x-app-shell title="Platform admin" active="dashboard" context="Local Goods platform">
    <div class="space-y-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
            <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1>
            <p class="mt-3 max-w-2xl text-lg text-muted">
                Every login is created by an admin; there is no public sign-up.
                You can also open fraud review to approve or reject held redemptions.
            </p>
        </header>

        <section aria-labelledby="accounts-heading">
            <h2 id="accounts-heading" class="text-2xl font-semibold text-forest">Accounts</h2>
            <x-card class="mt-3 overflow-x-auto" padding="p-0">
                <table class="w-full text-left">
                    <thead class="border-b border-line text-sm text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Name</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Email</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Role</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-5 py-3 font-semibold">{{ $user->name }}</td>
                                <td class="px-5 py-3 break-all">{{ $user->email }}</td>
                                <td class="px-5 py-3"><x-badge tone="forest">{{ $user->role->label() }}</x-badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </section>
    </div>
</x-app-shell>
