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
