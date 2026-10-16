// Copy buttons: <button data-copy="#input-id"> copies that input's value.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;

    const input = document.querySelector(button.dataset.copy);
    const label = button.textContent;
    try {
        await navigator.clipboard.writeText(input.value);
        button.textContent = 'Copied';
    } catch {
        input.select();
        button.textContent = 'Press Ctrl+C or ⌘C';
    }
    setTimeout(() => (button.textContent = label), 2000);
});

// Dialogs: <button data-dialog-open="id"> opens <dialog id="id">; [data-dialog-close] closes it.
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) document.getElementById(opener.dataset.dialogOpen)?.showModal();

    const closer = event.target.closest('[data-dialog-close]');
    if (closer) closer.closest('dialog')?.close();
});

// Reopen a dialog whose form came back with errors.
document.querySelectorAll('dialog[data-open-on-load]').forEach((dialog) => dialog.showModal());

// Reviewer magic links: /judge-access#<id>.<token>. The part after # never
// reaches the server's logs. Move it into the form body, clear it from the
// address bar (and history), then sign in.
const magicLinkForm = document.querySelector('form[data-magic-link]');
if (magicLinkForm) {
    const link = window.location.hash.slice(1);
    history.replaceState(null, '', window.location.pathname);

    if (link) {
        magicLinkForm.elements.link.value = link;
        magicLinkForm.submit();
    } else {
        document.getElementById('magic-link-missing').hidden = false;
        magicLinkForm.hidden = true;
    }
}

// Occasion pickers: <select data-fill-amount="#amount-input"> whose options carry
// data-amount puts that option's amount in the field when it is chosen.
document.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-fill-amount]');
    const amount = select?.selectedOptions[0]?.dataset.amount;
    if (amount) document.querySelector(select.dataset.fillAmount).value = amount;
});

// Approved redemption screen: a ticking clock (DC time) so the merchant can see
// it is the live app, not a screenshot.
document.querySelectorAll('[data-live-clock]').forEach((clock) => {
    const format = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit', second: '2-digit',
    });
    const tick = () => (clock.textContent = format.format(new Date()));
    tick();
    setInterval(tick, 1000);
});

// The floating Feedback button never covers a field, button or link: while
// anything interactive is underneath it, or while someone is typing in a
// field, it fades out and can't be tapped, and comes back once clear.
const feedbackWidget = document.querySelector('[data-feedback-widget]');
if (feedbackWidget) {
    const interactive = 'input, select, textarea, button, a[href], summary, label, [role="button"], [tabindex]:not([tabindex="-1"])';
    const covering = () => {
        if (feedbackWidget.contains(document.activeElement)) return false;
        const typing = document.activeElement?.matches('input:not([type=checkbox]):not([type=radio]), select, textarea')
            && !feedbackWidget.contains(document.activeElement)
            && !document.activeElement.closest('dialog');
        if (typing) return true;

        const box = feedbackWidget.getBoundingClientRect();
        if (box.width === 0) return false;
        // A 5 by 5 grid reaching 4px beyond the button, so a field that only
        // clips a corner, or sits right against it, counts as covered.
        const steps = [0, 0.25, 0.5, 0.75, 1];
        const pad = 4;
        const points = steps.flatMap((x) => steps.map((y) => [
            box.left - pad + (box.width + 2 * pad) * x, box.top - pad + (box.height + 2 * pad) * y,
        ]));

        feedbackWidget.style.visibility = 'hidden'; // look through it
        const hit = points.some(([x, y]) => {
            const element = document.elementFromPoint(x, y)?.closest(interactive);
            return element && !element.closest('nav, dialog');
        });
        feedbackWidget.style.visibility = '';
        return hit;
    };
    let queued = false;
    const update = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            feedbackWidget.toggleAttribute('data-covering', covering());
        });
    };
    ['scroll', 'resize', 'focusin', 'focusout', 'load', 'toggle'].forEach((type) => window.addEventListener(type, update, { passive: true, capture: true }));
    // Layout that moves without a scroll (fonts loading, a section opening) is checked too.
    new ResizeObserver(update).observe(document.body);
    update();
}
