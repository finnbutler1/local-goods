@php
    $input = 'block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest';
    $when = fn ($t) => $t ? $t->setTimezone('America/New_York')->format('M j, g:i A') : '—';
@endphp

<x-app-shell title="Reviewers" active="reviewers" context="Local Goods platform">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Reviewers</h1>
        <p class="mt-3 text-lg text-muted">
            Invite contest judges, HR managers, merchants, BID staff and friends. Each gets a read-only login.
            You get a link to copy and send yourself: it works once and expires in {{ \App\Services\ReviewerInvites::LINK_DAYS }} days.
            The reviewer chooses their own password; nobody else ever sees it.
        </p>
    </header>

    <div aria-live="polite">
        @if (session('invite_link'))
            <x-card class="border-2 border-ok">
                <p class="font-semibold text-ok">Invite link for {{ session('invite_link')['name'] }}. Copy it now: it won't be shown again.</p>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <label for="invite-link" class="sr-only">Invite link</label>
                    <input id="invite-link" type="text" readonly value="{{ session('invite_link')['url'] }}"
                           class="{{ $input }} border-muted font-mono text-sm" onfocus="this.select()">
                    <x-button data-copy="#invite-link" class="shrink-0">Copy link</x-button>
                </div>
            </x-card>
        @endif
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
    </div>

    <x-card as="section" aria-labelledby="invite-heading">
        <h2 id="invite-heading" class="text-2xl font-semibold text-forest">Invite a reviewer</h2>
        <form method="POST" action="{{ route('admin.reviewers.store') }}" class="mt-4 grid gap-4 md:grid-cols-3">
            @csrf
            <x-field name="name" label="Name">
                <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name') }}" autocomplete="off"
                       class="{{ $input }} @error('name') border-hold @else border-muted @enderror" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
            </x-field>
            <x-field name="email" label="Email">
                <input id="email" name="email" type="email" required value="{{ old('email') }}" autocomplete="off"
                       class="{{ $input }} @error('email') border-hold @else border-muted @enderror" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-field>
            <x-field name="reviewer_type" label="Reviewer type">
                <select id="reviewer_type" name="reviewer_type" required
                        class="{{ $input }} @error('reviewer_type') border-hold @else border-muted @enderror">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('reviewer_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </x-field>
            <div class="md:col-span-3">
                <x-button type="submit">Create login and link</x-button>
            </div>
        </form>
    </x-card>

    <section aria-labelledby="list-heading">
        <h2 id="list-heading" class="text-2xl font-semibold text-forest">Everyone invited</h2>
        @if ($reviewers->isEmpty())
            <p class="mt-3 text-muted">No reviewers yet.</p>
        @else
            <x-card class="mt-3 overflow-x-auto" padding="p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Reviewer</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Invited</th>
                            <th scope="col" class="px-4 py-3 font-semibold">First login</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Last seen</th>
                            <th scope="col" class="px-4 py-3 font-semibold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($reviewers as $reviewer)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold">{{ $reviewer->name }}</p>
                                    <p class="break-all text-muted">{{ $reviewer->email }}</p>
                                </td>
                                <td class="px-4 py-3">{{ $reviewer->reviewer_type?->label() ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($reviewer->isRevoked())
                                        <x-badge tone="neutral">Revoked</x-badge>
                                    @elseif ($reviewer->first_login_at)
                                        <x-badge tone="ok">Active</x-badge>
                                    @else
                                        <x-badge tone="amber">Invited</x-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->invited_at) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->first_login_at) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->last_seen_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.reviewers.link', $reviewer) }}">
                                            @csrf
                                            <x-button type="submit" size="sm" variant="secondary" aria-label="New invite link for {{ $reviewer->name }}">
                                                {{ $reviewer->isRevoked() ? 'Restore with new link' : 'New link' }}
                                            </x-button>
                                        </form>
                                        @unless ($reviewer->isRevoked())
                                            <form method="POST" action="{{ route('admin.reviewers.revoke', $reviewer) }}"
                                                  onsubmit="return confirm('Revoke {{ e(addslashes($reviewer->name)) }}? They will be logged out and can no longer log in.');">
                                                @csrf
                                                <x-button type="submit" size="sm" variant="danger" aria-label="Revoke access for {{ $reviewer->name }}">Revoke</x-button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    </section>
</div>
</x-app-shell>
