@php $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary'; @endphp

<x-app-shell title="Changelog" active="feedback" context="Local Goods platform">
<div class="space-y-8">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Platform admin</x-eyebrow>
        <h1 class="mt-1 text-title font-semibold text-primary">Changelog for reviewers</h1>
        <p class="mt-3 text-lg text-muted">
            Short notes on what changed. Reviewers see entries published since their last visit on their home page,
            next to their own feedback you marked fixed. Keep it to synthetic, demo-safe details.
        </p>
    </header>

    <x-card as="section" aria-labelledby="new-heading" class="max-w-2xl">
        <h2 id="new-heading" class="text-2xl font-semibold text-primary">New entry</h2>
        <form method="POST" action="{{ route('admin.changelog.store') }}" data-busy="Saving…" class="mt-4 space-y-4">
            @csrf
            <x-field name="title" label="Title">
                <input id="title" name="title" type="text" required maxlength="150" value="{{ old('title') }}"
                       class="{{ $input }} @error('title') border-hold @else border-muted @enderror" @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
            </x-field>
            <x-field name="body" label="What changed">
                <textarea id="body" name="body" rows="4" required maxlength="5000"
                          class="{{ $input }} @error('body') border-hold @else border-muted @enderror" @error('body') aria-invalid="true" aria-describedby="body-error" @enderror>{{ old('body') }}</textarea>
            </x-field>
            <x-button type="submit">Publish</x-button>
        </form>
    </x-card>

    <section aria-labelledby="published-heading">
        <h2 id="published-heading" class="text-2xl font-semibold text-primary">Published</h2>
        @if ($entries->isEmpty())
            <p class="mt-2 text-muted">Nothing yet.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($entries as $entry)
                    <x-card as="li">
                        <p class="text-sm text-muted">{{ $entry->published_at->setTimezone('America/New_York')->format('M j, Y g:i A') }}</p>
                        <h3 class="mt-1 text-xl font-semibold">{{ $entry->title }}</h3>
                        <p class="mt-1 whitespace-pre-line">{{ $entry->body }}</p>
                    </x-card>
                @endforeach
            </ul>
        @endif
    </section>
</div>
</x-app-shell>
