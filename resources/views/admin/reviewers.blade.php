@php
    $input = 'block w-full rounded-xl border-2 bg-paper px-3 py-3 focus-visible:border-forest';
    $when = fn ($t) => $t ? $t->setTimezone('America/New_York')->format('M j, g:i A') : '—';
    $status = fn ($reviewer) => match (true) {
        $reviewer->isRevoked() => ['neutral', 'Revoked'],
        $ended => ['neutral', 'Ended'],
        $reviewer->invite_token_hash === null => ['amber', 'No link yet'],
        $reviewer->first_login_at !== null => ['ok', 'Active'],
        default => ['amber', 'Link ready'],
    };
@endphp

<x-app-shell title="Reviewers" active="reviewers" context="Local Goods platform">
<div class="space-y-8">
    <header class="max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-widest text-amber-ink">Platform admin</p>
        <h1 class="mt-1 text-4xl font-semibold text-forest sm:text-5xl">Reviewers</h1>
        <p class="mt-3 text-lg text-muted">
            Invite contest judges, HR managers, merchants, BID staff and friends. Each gets a personal magic link
            that signs them straight into a read-only reviewer view: no password, no account to set up.
            Links work as often as needed until judging ends on {{ $endsAt->format('M j, Y \a\t g:i A') }} Eastern
            (the <code class="font-mono text-base">JUDGING_ENDS_AT</code> setting).
            <strong class="font-semibold text-ink">New link</strong> switches the old one off at once, and
            <strong class="font-semibold text-ink">Revoke</strong> ends their access; either way their open session ends on its next click.
        </p>
        @if ($ended)
            <p class="mt-3 rounded-2xl border-2 border-hold bg-hold-bg p-4 font-semibold text-hold">
                Judging has ended: every reviewer link now shows "This link has ended". Move JUDGING_ENDS_AT later to reopen them.
            </p>
        @endif
    </header>

    <div aria-live="polite">
        @if (session('reviewer_link'))
            <x-card class="border-2 border-ok">
                <p class="font-semibold text-ok">Magic link for {{ session('reviewer_link')['name'] }}. Copy it now: only a fingerprint of it is kept, so it can't be shown again.</p>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <label for="reviewer-link" class="sr-only">Magic link</label>
                    <input id="reviewer-link" type="text" readonly value="{{ session('reviewer_link')['url'] }}"
                           class="{{ $input }} border-muted font-mono text-sm" onfocus="this.select()">
                    <x-button data-copy="#reviewer-link" class="shrink-0">Copy link</x-button>
                </div>
            </x-card>
        @endif
        @if (session('status'))
            <p class="rounded-2xl border-2 border-ok bg-ok-bg p-4 font-semibold text-ok">{{ session('status') }}</p>
        @endif
    </div>

    <x-card as="section" aria-labelledby="public-heading">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 id="public-heading" class="text-2xl font-semibold text-forest">Public judge access</h2>
                <p class="mt-2 text-muted">
                    One shared, generic read-only reviewer for the README's "For judges" link. Anyone with the link uses it,
                    so its feedback is anonymous, it is rate-limited more tightly, and it can't approve or reject anything.
                    Named reviewers get their own links below so their feedback is attributed.
                </p>
            </div>
            @if ($public)
                @php [$tone, $label] = $status($public); @endphp
                <x-badge :tone="$tone">{{ $label }}</x-badge>
            @endif
        </div>
        @if ($public)
            <p class="mt-3 text-sm text-muted">Last seen {{ $when($public->last_seen_at) }}.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.reviewers.link', $public) }}"
                      onsubmit="return confirm('Make a new public link? The current one, including the link in the README, stops working at once.');">
                    @csrf
                    <x-button type="submit" size="sm" variant="secondary">
                        {{ $public->isRevoked() ? 'Restore with new public link' : 'New public link' }}
                    </x-button>
                </form>
                @unless ($public->isRevoked())
                    <form method="POST" action="{{ route('admin.reviewers.revoke', $public) }}"
                          onsubmit="return confirm('Switch off public judge access? The README link stops working and open sessions end.');">
                        @csrf
                        <x-button type="submit" size="sm" variant="danger">Switch off</x-button>
                    </form>
                @endunless
            </div>
        @else
            <p class="mt-3 font-semibold text-hold">Not set up yet. Run <code class="font-mono">php artisan demo:seed-accounts</code> (the deploy does this).</p>
        @endif
    </x-card>

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
                <x-button type="submit">Create reviewer and magic link</x-button>
            </div>
        </form>
    </x-card>

    <section aria-labelledby="list-heading">
        <h2 id="list-heading" class="text-2xl font-semibold text-forest">Named reviewers</h2>
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
                            <th scope="col" class="px-4 py-3 font-semibold">Added</th>
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
                                    @php [$tone, $label] = $status($reviewer); @endphp
                                    <x-badge :tone="$tone">{{ $label }}</x-badge>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->invited_at) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->first_login_at) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $when($reviewer->last_seen_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.reviewers.link', $reviewer) }}">
                                            @csrf
                                            <x-button type="submit" size="sm" variant="secondary" aria-label="New magic link for {{ $reviewer->name }} (the old one stops working)">
                                                {{ $reviewer->isRevoked() ? 'Restore with new link' : 'New link' }}
                                            </x-button>
                                        </form>
                                        @unless ($reviewer->isRevoked())
                                            <form method="POST" action="{{ route('admin.reviewers.revoke', $reviewer) }}"
                                                  onsubmit="return confirm('Revoke {{ e(addslashes($reviewer->name)) }}? Their link stops working and any open session ends.');">
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
