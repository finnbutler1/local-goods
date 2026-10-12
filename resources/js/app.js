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
