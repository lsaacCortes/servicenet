/* Dialogos e avisos proprios do painel (substituem confirm/alert do navegador).
 *
 *   SN.confirm({ title, message, confirmText, cancelText, tone })  -> Promise<boolean>
 *   SN.confirm('Mensagem simples')                                  -> Promise<boolean>
 *   SN.alert({ title, message, tone })                              -> Promise<void>
 *   SN.toast('Mensagem', 'success' | 'error' | 'info')
 *
 * Formularios com data-confirm="texto" pedem confirmacao antes de enviar.
 */
(function () {
    const ICONS = {
        danger:  'fa-triangle-exclamation',
        warning: 'fa-triangle-exclamation',
        primary: 'fa-circle-question',
        success: 'fa-circle-check',
        error:   'fa-circle-xmark',
        info:    'fa-circle-info',
    };

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function dialog(opts, withCancel) {
        if (typeof opts === 'string') opts = { message: opts };
        const tone = opts.tone || (withCancel ? 'primary' : 'info');

        return new Promise(resolve => {
            const previous = document.activeElement;
            const backdrop = el('div', 'sn-backdrop');
            const box = el('div', 'sn-dialog sn-tone-' + tone);
            box.setAttribute('role', withCancel ? 'alertdialog' : 'dialog');
            box.setAttribute('aria-modal', 'true');

            const icon = el('div', 'sn-dialog-icon');
            icon.append(el('i', 'fa-solid ' + (ICONS[tone] || ICONS.info)));

            const title = el('h3', 'sn-dialog-title', opts.title || (withCancel ? 'Confirmar ação' : 'Aviso'));
            const msg = el('p', 'sn-dialog-msg', opts.message || '');
            const actions = el('div', 'sn-dialog-actions');

            const cancel = el('button', 'sn-btn sn-btn-ghost', opts.cancelText || 'Cancelar');
            cancel.type = 'button';
            const ok = el('button', 'sn-btn ' + (tone === 'danger' ? 'sn-btn-danger' : 'sn-btn-primary'),
                opts.confirmText || (withCancel ? 'Confirmar' : 'Entendi'));
            ok.type = 'button';
            if (withCancel) actions.append(cancel);
            actions.append(ok);

            box.append(icon, title, msg, actions);
            backdrop.append(box);
            document.body.append(backdrop);
            requestAnimationFrame(() => backdrop.classList.add('sn-show'));
            ok.focus();

            function close(result) {
                document.removeEventListener('keydown', onKey, true);
                backdrop.classList.remove('sn-show');
                setTimeout(() => backdrop.remove(), 180);
                if (previous && previous.focus) previous.focus();
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(false); }
                else if (e.key === 'Tab') {
                    // mantem o foco dentro do dialogo
                    const f = box.querySelectorAll('button');
                    const first = f[0], last = f[f.length - 1];
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
            document.addEventListener('keydown', onKey, true);
            ok.onclick = () => close(true);
            cancel.onclick = () => close(false);
            backdrop.addEventListener('mousedown', e => { if (e.target === backdrop) close(false); });
        });
    }

    function toast(message, type) {
        type = type || 'success';
        let host = document.getElementById('sn-toasts');
        if (!host) {
            host = el('div', 'sn-toasts');
            host.id = 'sn-toasts';
            host.setAttribute('aria-live', 'polite');
            document.body.append(host);
        }
        const t = el('div', 'sn-toast sn-toast-' + type);
        t.append(el('i', 'fa-solid ' + (ICONS[type] || ICONS.info)), el('span', '', message));
        host.append(t);
        requestAnimationFrame(() => t.classList.add('sn-show'));
        setTimeout(() => {
            t.classList.remove('sn-show');
            setTimeout(() => t.remove(), 250);
        }, type === 'error' ? 5000 : 3000);
    }

    window.SN = {
        confirm: opts => dialog(opts, true),
        alert: opts => dialog(opts, false).then(() => undefined),
        toast: toast,
    };

    document.addEventListener('submit', async e => {
        const form = e.target;
        const texto = form.dataset && form.dataset.confirm;
        if (!texto || form.dataset.confirmed === '1') return;
        e.preventDefault();
        const ok = await SN.confirm({
            title: form.dataset.confirmTitle || 'Confirmar ação',
            message: texto,
            confirmText: form.dataset.confirmText || 'Confirmar',
            tone: form.dataset.confirmTone || 'danger',
        });
        if (ok) {
            form.dataset.confirmed = '1';
            form.submit();
        }
    });
})();
