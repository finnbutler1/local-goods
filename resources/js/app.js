import './checkout.js';

// Light/dark toggle: [data-theme-toggle], pressed = dark. With no saved
// choice the page follows the system setting (CSS media query); pressing the
// button saves a choice. Storage may be blocked: then it lasts for this page.
const themeToggles = document.querySelectorAll('[data-theme-toggle]');
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
const isDark = () => {
    const chosen = document.documentElement.dataset.theme;
    return chosen ? chosen === 'dark' : systemDark.matches;
};
const showTheme = () => themeToggles.forEach((toggle) => toggle.setAttribute('aria-pressed', String(isDark())));
themeToggles.forEach((toggle) => toggle.addEventListener('click', () => {
    const theme = isDark() ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try {
        localStorage.setItem('lg-theme', theme);
    } catch {
        // Private mode or blocked storage: the choice lasts until the next page.
    }
    showTheme();
}));
systemDark.addEventListener('change', showTheme);
showTheme();

// Disclosure menus: <x-menu> (data-menu). The button toggles the list;
// ArrowDown/ArrowUp open it on the first/last item and move between items,
// Home/End jump, Escape closes it and puts focus back on the button.
// Clicking elsewhere or tabbing out closes it.
document.querySelectorAll('[data-menu]').forEach((menu) => {
    const button = menu.querySelector('[data-menu-button]');
    const panel = menu.querySelector('[data-menu-panel]');
    const items = () => [...panel.querySelectorAll('a[href], button:not([disabled])')];
    const isOpen = () => button.getAttribute('aria-expanded') === 'true';
    const open = (focus) => {
        button.setAttribute('aria-expanded', 'true');
        panel.dataset.open = '';
        if (focus === 'first') items()[0]?.focus();
        if (focus === 'last') items().at(-1)?.focus();
    };
    const close = (returnFocus) => {
        if (!isOpen()) return;
        button.setAttribute('aria-expanded', 'false');
        delete panel.dataset.open;
        if (returnFocus) button.focus();
    };
    button.addEventListener('click', () => (isOpen() ? close(false) : open()));
    button.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            open('first');
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            open('last');
        } else if (event.key === 'Escape') {
            close(true);
        }
    });
    panel.addEventListener('keydown', (event) => {
        const list = items();
        const at = list.indexOf(document.activeElement);
        const move = {
            ArrowDown: list[(at + 1) % list.length],
            ArrowUp: list[(at - 1 + list.length) % list.length],
            Home: list[0],
            End: list.at(-1),
        }[event.key];
        if (move) {
            event.preventDefault();
            move.focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        }
    });
    // Safari doesn't focus a clicked link, so focus leaves on mouse-down:
    // ignore that while the pointer is pressed inside the menu.
    let pressing = false;
    menu.addEventListener('pointerdown', () => (pressing = true));
    document.addEventListener('pointerup', () => setTimeout(() => (pressing = false)));
    menu.addEventListener('focusout', (event) => {
        if (!pressing && !menu.contains(event.relatedTarget)) close(false);
    });
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) close(false);
    });
});

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
// pressed button shows a spinner and says what is happening, and a screen
// reader hears the same words. A second submit is cancelled straight away;
// one tick later every submit button in the form is disabled for real. Not
// during the submit event itself: a disabled button's name and value would
// be left out of what is sent. Nothing about what is sent changes.
const busyStatus = document.createElement('p');
busyStatus.setAttribute('role', 'status');
busyStatus.className = 'sr-only';
document.body.append(busyStatus);
const submitButtons = (form) => [...form.elements].filter((el) => el.type === 'submit');
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
        button.setAttribute('aria-busy', 'true');
        const spinner = document.createElement('span');
        spinner.className = 'spinner';
        spinner.setAttribute('aria-hidden', 'true');
        button.replaceChildren(spinner, form.dataset.busy);
    }
    busyStatus.textContent = form.dataset.busy;
    setTimeout(() => submitButtons(form).forEach((other) => {
        if (other.disabled) return;
        other.disabled = true;
        other.dataset.disabledWhileBusy = '';
    }));
});
// Back button: the browser may restore this page as it was left, mid-submit.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[aria-busy]').forEach((form) => form.removeAttribute('aria-busy'));
    document.querySelectorAll('[data-idle-label]').forEach((button) => {
        button.innerHTML = button.dataset.idleLabel;
        button.removeAttribute('aria-disabled');
        button.removeAttribute('aria-busy');
        delete button.dataset.idleLabel;
    });
    document.querySelectorAll('[data-disabled-while-busy]').forEach((button) => {
        button.disabled = false;
        delete button.dataset.disabledWhileBusy;
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

// Step-by-step jobs: <form data-auto-submit>. The server does one short step
// per request and comes back to this page with the form marked, so it is
// pressed again here until the job is done. Without JavaScript the person
// presses the button themselves.
document.querySelectorAll('form[data-auto-submit]').forEach((form) => {
    const button = form.querySelector('[type="submit"]');
    window.addEventListener('load', () => form.requestSubmit(button ?? undefined));
});

// Redeem from a menu: <form data-basket>. Quantity fields carry data-price (in
// cents); the "Other amount" field is [data-basket-other]. Shows the − / +
// buttons and a running total (read out politely). Display only: the server
// works out the real total from the menu's own prices.
document.querySelectorAll('form[data-basket]').forEach((form) => {
    const quantities = [...form.querySelectorAll('input[data-price]')];
    const other = form.querySelector('[data-basket-other]');
    const summary = form.querySelector('[data-basket-summary]');
    const total = form.querySelector('[data-basket-total]');
    const cents = (value) => {
        const match = /^(\d{1,4})(?:\.(\d{1,2}))?$/.exec(value.trim());
        return match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : 0;
    };
    const update = () => {
        const sum = quantities.reduce((acc, input) => acc + Math.max(0, Math.trunc(Number(input.value) || 0)) * Number(input.dataset.price), 0)
            + (other ? cents(other.value) : 0);
        total.textContent = '$' + (sum / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    form.querySelectorAll('button[data-step]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            const next = (Math.trunc(Number(input.value) || 0)) + Number(button.dataset.step);
            input.value = String(Math.min(Number(input.max), Math.max(0, next)));
            update();
        });
    });
    form.addEventListener('input', update);
    summary.hidden = false;
    update();
});

// Tabs: [data-tabs] around a role="tablist" of <a role="tab" aria-controls>.
// Without JavaScript each tab is a plain link to its ?tab= page. With it,
// clicking shows that panel in place; ArrowLeft/ArrowRight move to the
// previous/next tab and Home/End to the first/last, selecting it as they go.
document.querySelectorAll('[data-tabs]').forEach((tabs) => {
    const list = [...tabs.querySelectorAll('[role="tab"]')];
    const select = (tab, focus) => {
        list.forEach((each) => {
            const on = each === tab;
            each.setAttribute('aria-selected', String(on));
            each.tabIndex = on ? 0 : -1;
            document.getElementById(each.getAttribute('aria-controls')).hidden = !on;
        });
        if (focus) tab.focus();
        history.replaceState(history.state, '', tab.href);
    };
    list.forEach((tab, index) => {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            select(tab, false);
        });
        tab.addEventListener('keydown', (event) => {
            const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: list.length - 1 }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            select(list[(next + list.length) % list.length], true);
        });
    });
});

// Pay code screen: [data-pay-code] (ADR 0026). Counts down to data-expires-at
// (hidden from screen readers; the live region speaks only at one minute
// left and at expiry) and asks every 3 seconds whether the merchant has
// confirmed. Once the code is used, declined, cancelled or expired, goes to
// the result page. Without JavaScript the person refreshes the page.
const payCode = document.querySelector('[data-pay-code]');
if (payCode) {
    const expires = Date.parse(payCode.dataset.expiresAt);
    const countdown = payCode.querySelector('[data-countdown]');
    const announce = payCode.querySelector('[data-countdown-announce]');
    const waiting = payCode.querySelector('[data-pay-code-waiting]');
    const showResult = () => window.location.assign(payCode.dataset.payCodeResultUrl);
    let warned = false;
    const tick = () => {
        const left = Math.max(0, Math.round((expires - Date.now()) / 1000));
        countdown.textContent = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        if (left <= 60 && !warned) {
            warned = true;
            announce.textContent = 'One minute left on your pay code.';
        }
        if (left === 0) {
            clearInterval(timer);
            announce.textContent = 'Your pay code expired. Nothing was charged.';
            setTimeout(showResult, 1500);
        }
    };
    const timer = setInterval(tick, 1000);
    tick();

    const check = async () => {
        try {
            const response = await fetch(payCode.dataset.payCodeStatusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const { status } = await response.json();
            if (status && status !== 'active' && status !== 'confirming') {
                showResult();
                return;
            }
            if (status === 'confirming') waiting.textContent = 'The cashier pressed Confirm. Checking…';
        } catch {
            // A dropped request is just another try.
        }
        if (Date.now() < expires + 5000) setTimeout(check, 3000);
    };
    setTimeout(check, 3000);
}
