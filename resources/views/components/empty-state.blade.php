{{-- One look for "nothing here yet" (ADR 0038): a band under a heavy ink rule,
     text left-aligned. Replaces the bare card or loose muted line. The words
     come from the page; this only draws them. --}}
<p {{ $attributes->class('rule-top bg-band px-4 pb-4 pt-3 text-muted') }}>{{ $slot }}</p>
