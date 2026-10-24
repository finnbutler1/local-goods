{{-- Floating Feedback button on every signed-in page. Opens a small form that
     records this page's address automatically. --}}
@php $hasErrors = $errors->getBag('feedback')->any(); @endphp

{{-- Phones and tablets: a 48px icon button just above the bottom tab bar.
     Desktop: the labelled button. On every size it steps aside (fades out,
     can't be tapped) while it would sit on a field, button or link, or while
     someone is typing; see data-feedback-widget in resources/js/app.js. --}}
<div data-feedback-widget
     class="fixed right-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-30 transition-opacity duration-150 data-[covering]:pointer-events-none data-[covering]:opacity-0 data-[covering]:focus-within:pointer-events-auto data-[covering]:focus-within:opacity-100 lg:right-6 lg:bottom-6">
    <x-button variant="accent" data-dialog-open="feedback-dialog" aria-haspopup="dialog" aria-label="Leave feedback"
              class="ml-auto size-12 p-0! shadow-lg lg:size-auto lg:px-5! lg:py-2.5!">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M7.5 8.25h9m-9 3.75h6M3.75 19.5V6a1.5 1.5 0 0 1 1.5-1.5h13.5a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5H8.25L3.75 19.5Z"/></svg>
        <span class="hidden lg:inline" aria-hidden="true">Feedback</span>
    </x-button>
</div>

<dialog id="feedback-dialog" aria-labelledby="feedback-heading" @if ($hasErrors) data-open-on-load @endif
        class="m-auto w-[min(100%-2rem,32rem)] rounded-2xl bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/50">
    <form method="POST" action="{{ route('feedback.store') }}" data-busy="Sending…" class="space-y-4 p-6">
        @csrf
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="feedback-heading" class="text-2xl font-semibold text-primary">Leave feedback</h2>
                <p class="mt-1 text-sm text-muted">About this page: <x-breakable :value="request()->path() === '/' ? '/' : '/'.request()->path()" /></p>
            </div>
            <button type="button" data-dialog-close class="rounded-full p-1 text-muted hover:text-ink" aria-label="Close feedback form">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-6" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <x-feedback-fields prefix="fb" bag="feedback" :page-url="request()->fullUrl()" />

        <div class="flex justify-end gap-2 pt-2">
            <x-button variant="ghost" data-dialog-close>Cancel</x-button>
            <x-button type="submit">Send feedback</x-button>
        </div>
    </form>
</dialog>
