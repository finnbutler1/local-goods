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
