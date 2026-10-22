import './checkout.js';

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

// After a form posts and the page comes back, start where the answer is: the
// first field with an error, or else the message saying what happened
// ([data-flash], see the x-flash component). Screen readers announce it and
// the next Tab continues from there instead of the top of the page.
// After a redirect to an #anchor, Chrome jumps there once the page has loaded
// and clears focus on the way, so wait until then.
const focusAnswer = () => {
    if (document.querySelector('dialog[open]')) return;
    const firstError = document.querySelector('main [aria-invalid="true"]');
    const answer = firstError?.matches('fieldset') ? firstError.querySelector('input, select, textarea') : firstError;
    (answer ?? document.querySelector('[data-flash]'))?.focus();
};
if (window.location.hash && document.readyState !== 'complete') {
    window.addEventListener('load', () => setTimeout(focusAnswer));
} else {
    focusAnswer();
}

// Toasts (x-flash): × or Escape dismisses one. Status and notice toasts fade
// out on their own after 8 seconds (paused while the pointer is on them or the
// × has focus); errors, and everything when reduced motion is on, stay until
// dismissed. Focus inside a toast that goes moves on to the page's own answer
// (a [data-flash] in main), else the #anchor the redirect jumped to, else main.
const dismissToast = (toast) => {
    const hadFocus = toast.contains(document.activeElement);
    toast.remove();
    if (!hadFocus) return;

    const anchor = window.location.hash.length > 1 ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;
    const next = document.querySelector('main [data-flash]') ?? anchor ?? document.getElementById('main');
    if (next && !next.matches('a[href], button, input, select, textarea, summary, [tabindex]')) next.setAttribute('tabindex', '-1');
    next?.focus();
};
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
document.querySelectorAll('[data-toast]').forEach((toast) => {
    const button = toast.querySelector('[data-toast-dismiss]');
    button.addEventListener('click', () => dismissToast(toast));
    toast.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') dismissToast(toast);
    });
    if (!toast.hasAttribute('data-toast-autohide') || reducedMotion) return;

    let timer;
    const pause = () => {
        clearTimeout(timer);
        toast.classList.remove('opacity-0');
    };
    const start = () => {
        pause();
        timer = setTimeout(() => {
            toast.classList.add('opacity-0');
            timer = setTimeout(() => dismissToast(toast), 500);
        }, 8000);
    };
    toast.addEventListener('pointerenter', pause);
    toast.addEventListener('pointerleave', start);
    button.addEventListener('focus', pause);
    button.addEventListener('blur', start);
    start();
});

// Slow submits: <form data-busy="Checking…">. While the next page loads, the
// pressed button says what is happening and can't be pressed again, and a
// screen reader hears the same words. Nothing about what is sent changes.
const busyStatus = document.createElement('p');
busyStatus.setAttribute('role', 'status');
busyStatus.className = 'sr-only';
document.body.append(busyStatus);
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-busy]');
    if (!form || event.defaultPrevented) return;
    if (form.hasAttribute('aria-busy')) {
        event.preventDefault(); // already on its way
        return;
    }
    form.setAttribute('aria-busy', 'true');
    const button = event.submitter;
    if (button) {
        button.dataset.idleLabel = button.innerHTML;
        button.setAttribute('aria-disabled', 'true');
        button.textContent = form.dataset.busy;
    }
    busyStatus.textContent = form.dataset.busy;
});
// Back button: the browser may restore this page as it was left, mid-submit.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[aria-busy]').forEach((form) => form.removeAttribute('aria-busy'));
    document.querySelectorAll('[data-idle-label]').forEach((button) => {
        button.innerHTML = button.dataset.idleLabel;
        button.removeAttribute('aria-disabled');
        delete button.dataset.idleLabel;
    });
    busyStatus.textContent = '';
});

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
