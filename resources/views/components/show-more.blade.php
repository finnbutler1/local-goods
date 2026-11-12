@props(['target', 'limit', 'loaded', 'noun', 'total' => null, 'reload' => null])

{{-- "Show all (N)" for a long list (App\Support\ShowMore). The list's rows
     after the first `limit` carry data-more and sit inside the element whose
     id is `target`. This block starts hidden: without JavaScript every row is
     simply visible, and app.js (`[data-show-more]`) hides the extra rows and
     shows the button. `reload` is the link to the same page with the whole
     list, only given when more rows exist than the page loads. --}}
@if ($loaded > $limit)
    <div data-show-more="{{ $target }}" data-limit="{{ $limit }}" class="mt-3" hidden>
        <x-button type="button" variant="secondary" size="md" aria-expanded="false" aria-controls="{{ $target }}"
            data-label-more="Show all {{ $noun }} ({{ $loaded }})" data-label-less="Show fewer">Show all {{ $noun }} ({{ $loaded }})</x-button>
    </div>
@endif
@if ($reload && $total !== null && $total > $loaded)
    <p class="mt-3"><a href="{{ $reload }}" class="tap-target font-semibold text-primary underline decoration-accent decoration-2 underline-offset-4 hover:decoration-primary">Show all {{ $total }} {{ $noun }}</a></p>
@endif
