const identity = Symbol.for('site-builder.native-modals.v1');

function state() {
    return globalThis[identity] ||= { bound: new WeakSet(), open: [], overflow: null, removal: null };
}

function finish(dialog) {
    const manager = state();
    const record = manager.open.find((item) => item.dialog === dialog);
    if (!record) return;
    const descendants = manager.open.filter((item) => item.parent === dialog);
    descendants.reverse().forEach((item) => { item.dialog.close(); finish(item.dialog); });
    manager.open = manager.open.filter((item) => item.dialog !== dialog);
    if (!manager.open.length && manager.overflow !== null) {
        if (document.documentElement.style.overflow === 'hidden') document.documentElement.style.overflow = manager.overflow;
        manager.overflow = null;
        manager.removal?.disconnect(); manager.removal = null;
    }
    if (record.trigger?.isConnected && !record.trigger.closest('[inert]')) record.trigger.focus({ preventScroll: true });
}

export function bindDialog(dialog) {
    const manager = state();
    if (manager.bound.has(dialog)) return;
    manager.bound.add(dialog);
    dialog.addEventListener('close', () => finish(dialog));
}

export function openDialog(dialog, trigger) {
    if (typeof dialog.showModal !== 'function' || dialog.open) return false;
    bindDialog(dialog);
    const manager = state();
    const record = { dialog, trigger, parent: trigger?.closest('dialog') || null };
    try {
        dialog.showModal();
        if (!manager.open.length) {
            manager.overflow = document.documentElement.style.overflow;
            document.documentElement.style.overflow = 'hidden';
        }
        manager.open.push(record);
        if (!manager.removal && typeof MutationObserver !== 'undefined') {
            manager.removal = new MutationObserver(() => {
                for (const item of [...manager.open].reverse()) {
                    if (!item.dialog.isConnected) { item.dialog.close(); finish(item.dialog); }
                }
            });
            manager.removal.observe(document.documentElement, { childList: true, subtree: true });
        }
        return true;
    } catch {
        return false;
    }
}
