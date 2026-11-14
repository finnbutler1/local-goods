{{--
    Sandbox only (ADR 0045 addendum): pay on Stripe's hosted page instead of
    the card form on this page. $auto: checkout.js submits this form by
    itself when Stripe.js doesn't load or can't mount the card form (not when
    our own request for the form fails: then Try again).
--}}
<form method="POST" action="{{ route('employer.purchases.pay', $purchase) }}" data-busy="Opening Stripe…" @class(['mt-3' => ! $auto]) @if ($auto) data-checkout-fallback @endif>
    @csrf
    <input type="hidden" name="hosted" value="1">
    <x-button type="submit" size="sm" variant="secondary">Pay on Stripe's page instead</x-button>
</form>
