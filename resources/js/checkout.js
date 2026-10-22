// Buying credits on the employer dashboard (docs/STRIPE.md).

const money = (cents) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

// Platform fee preview, only rendered when the fee is on (ADR 0021): the same
// arithmetic as App\Support\PlatformFee::centsFor (half up, whole cents).
const feePreview = document.querySelector('[data-fee-preview]');
const amountInput = document.getElementById('purchase_amount');
if (feePreview && amountInput) {
    const basisPoints = Number(feePreview.dataset.feeBasisPoints);
    const update = () => {
        const match = amountInput.value.trim().match(/^(\d{1,6})(?:\.(\d{1,2}))?$/);
        if (!match || Number(amountInput.value) === 0) {
            feePreview.textContent = '';
            return;
        }
        const credits = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
        const fee = Math.floor((credits * basisPoints + 5000) / 10000);
        feePreview.textContent = `Credits ${money(credits)} + platform fee ${money(fee)} = total ${money(credits + fee)}`;
    };
    amountInput.addEventListener('input', update);
    update();
}

// Paying: Stripe's embedded Checkout inside the Buy credits card. Opening it
// writes nothing; the credits unlock only when Stripe's webhook confirms.
const mount = document.querySelector('[data-embedded-checkout]');
if (mount) {
    const failed = (message) => {
        mount.hidden = true;
        const panel = document.querySelector('[data-checkout-error]');
        panel.querySelector('[data-checkout-error-text]').textContent = message;
        panel.hidden = false;
        panel.focus();
    };

    (async () => {
        if (!window.Stripe) {
            failed("Stripe's payment form didn't load. Nothing was charged. Check your connection, then try again.");
            return;
        }
        try {
            const stripe = window.Stripe(mount.dataset.stripeKey);
            const fetchClientSecret = async () => {
                const response = await fetch(mount.dataset.secretUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': mount.dataset.csrf },
                });
                const body = await response.json().catch(() => ({}));
                if (!response.ok || !body.client_secret) {
                    throw new Error(body.message ?? "Payment didn't start. Nothing was charged.");
                }
                return body.client_secret;
            };
            // Stripe.js "endive" (our API version) renamed initEmbeddedCheckout.
            const create = stripe.createEmbeddedCheckoutPage ?? stripe.initEmbeddedCheckout;
            const checkout = await create.call(stripe, { fetchClientSecret });
            mount.querySelector('[data-checkout-loading]')?.remove();
            checkout.mount(mount);
        } catch (error) {
            failed(error?.message || "Payment didn't start. Nothing was charged.");
        }
    })();
}

// Confirming: paid at Stripe, waiting for the webhook. Asks every 2 seconds
// for up to 2 minutes; once the purchase is decided, reloads so the page
// shows the green confirmation and the new balance.
const confirming = document.querySelector('[data-purchase-status-url]');
if (confirming) {
    let tries = 0;
    const check = async () => {
        tries += 1;
        try {
            const response = await fetch(confirming.dataset.purchaseStatusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const { status } = await response.json();
            if (status && status !== 'pending') {
                window.location.reload();
                return;
            }
        } catch {
            // A dropped request is just another try.
        }
        if (tries < 60) {
            setTimeout(check, 2000);
        } else {
            confirming.querySelector('[data-confirming-spinner]')?.remove();
            confirming.querySelector('[data-confirming-detail]').hidden = true;
            confirming.querySelector('[data-confirming-timeout]').hidden = false;
        }
    };
    setTimeout(check, 2000);
}
