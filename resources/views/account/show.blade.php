@php $input = 'block w-full rounded-xl border-2 bg-surface px-3 py-3 focus-visible:border-primary'; @endphp

<x-app-shell title="Account" active="account">
<div class="space-y-8">
    <header class="max-w-3xl">
        <x-eyebrow class="text-accent-ink">Your account</x-eyebrow>
        <h1 class="mt-1 text-4xl font-semibold text-primary sm:text-5xl">{{ auth()->user()->name }}</h1>
        <p class="mt-3 text-lg text-muted"><x-breakable :value="auth()->user()->email" /></p>
    </header>

    {{-- Reviewers have no password: they sign in with their magic link. --}}
    @unless (auth()->user()->isReadOnly())
    <x-card as="section" aria-labelledby="password-heading" class="max-w-xl">
        <h2 id="password-heading" class="text-2xl font-semibold text-primary">Change your password</h2>
        <form method="POST" action="{{ route('user-password.update') }}" data-busy="Saving…" class="mt-4 space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="email" value="{{ auth()->user()->email }}" autocomplete="username">

            <x-field name="current_password" label="Current password" :error="$errors->updatePassword->first('current_password') ?: null">
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required
                       @if ($errors->updatePassword->has('current_password')) aria-invalid="true" aria-describedby="current_password-error" @endif
                       class="{{ $input }} {{ $errors->updatePassword->has('current_password') ? 'border-hold' : 'border-muted' }}">
            </x-field>
            <x-field name="password" label="New password" hint="At least 8 characters." :error="$errors->updatePassword->first('password') ?: null">
                <input id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="password-hint{{ $errors->updatePassword->has('password') ? ' password-error' : '' }}"
                       @if ($errors->updatePassword->has('password')) aria-invalid="true" @endif
                       class="{{ $input }} {{ $errors->updatePassword->has('password') ? 'border-hold' : 'border-muted' }}">
            </x-field>
            <x-field name="password_confirmation" label="Confirm new password">
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                       class="{{ $input }} border-muted">
            </x-field>
            <x-button type="submit">Change password</x-button>
        </form>
    </x-card>
    @endunless

    <section aria-labelledby="my-feedback-heading">
        <h2 id="my-feedback-heading" class="text-2xl font-semibold text-primary">Your feedback</h2>
        @if ($feedback->isEmpty())
            <p class="mt-2 text-muted">Nothing yet. Use the Feedback button on any page.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($feedback as $item)
                    <x-card as="li" id="feedback-{{ $item->id }}" class="scroll-mt-24">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">{{ $item->category->label() }} · {{ $item->rating }}/5 · {{ $item->severity->label() }}</p>
                            <x-badge :tone="$item->status === \App\Enums\FeedbackStatus::Fixed ? 'ok' : 'neutral'">{{ $item->status->label() }}</x-badge>
                        </div>
                        <p class="mt-1 text-sm text-muted"><x-breakable :value="$item->page_url" /></p>
                        <p class="mt-2 whitespace-pre-line">{{ $item->comment }}</p>
                        @if ($item->fixed_commit_sha)
                            <p class="mt-2 text-sm text-muted">Fixed in commit <code class="font-mono">{{ $item->fixed_commit_sha }}</code></p>
                        @endif
                        <details class="mt-3" @if ($errors->getBag('feedback-'.$item->id)->any()) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-primary">Edit</summary>
                            <form method="POST" action="{{ route('feedback.update', $item) }}" data-busy="Saving…" class="mt-3 space-y-4">
                                @csrf
                                @method('PATCH')
                                <x-feedback-fields :prefix="'fb'.$item->id" :bag="'feedback-'.$item->id" :feedback="$item" :page-url="$item->page_url" />
                                <x-button type="submit" size="sm">Save changes</x-button>
                            </form>
                        </details>
                    </x-card>
                @endforeach
            </ul>
        @endif
    </section>
</div>
</x-app-shell>
