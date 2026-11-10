import { bindDialog, openDialog } from './modals.js';

export function initializeOverlays(scope = document) {
    if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
    for (const section of scope.querySelectorAll('section[data-sb-overlay]')) {
        const dialog = document.createElement('dialog');
        for (const attribute of section.attributes) dialog.setAttribute(attribute.name, attribute.value);
        dialog.append(...section.childNodes);
        section.replaceWith(dialog);
        bindDialog(dialog);
        const close = dialog.querySelector('[data-overlay-close]');
        if (close) { close.hidden = false; close.addEventListener('click', () => dialog.close()); }
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
        });
    }
    for (const trigger of scope.querySelectorAll('a[href^="#sb-overlay-"]')) {
        const dialog = document.getElementById(trigger.getAttribute('href').slice(1));
        if (!dialog?.matches('dialog[data-sb-overlay]')) continue;
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-controls', dialog.id);
    }
}

if (typeof document !== 'undefined') {
    const boot = () => {
        initializeOverlays();
        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const trigger = event.target.closest('a[href^="#sb-overlay-"]');
            if (!trigger) return;
            const dialog = document.getElementById(trigger.getAttribute('href').slice(1));
            if (dialog?.matches('dialog[data-sb-overlay]') && openDialog(dialog, trigger)) event.preventDefault();
        });
        new MutationObserver(() => initializeOverlays()).observe(document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}
