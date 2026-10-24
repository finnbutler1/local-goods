@props(['value'])

{{-- A long email, link or id that may break onto a second line only after
     @ . / _ or -, never mid-word. The text and what copy/paste gets are
     unchanged (<wbr> adds nothing). --}}
<span {{ $attributes->class('wrap-anywhere-safe') }}>{!! preg_replace('~([@./_-])~', '$1<wbr>', e($value)) !!}</span>
