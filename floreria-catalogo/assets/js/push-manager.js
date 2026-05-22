/* ── Florería Monarca — Push Manager ── */
(function () {
    'use strict';

    const { ajaxurl, nonce, vapidPublicKey } = window.fcPushData || {};

    function urlBase64ToUint8Array(b64) {
        const pad = '='.repeat((4 - b64.length % 4) % 4);
        const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    async function saveSubscription(sub) {
        const body = new FormData();
        body.append('action',       'fc_push_subscribe');
        body.append('nonce',        nonce);
        body.append('subscription', JSON.stringify(sub));
        await fetch(ajaxurl, { method: 'POST', body });
    }

    async function init() {
        if (!vapidPublicKey) return;
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        let reg;
        try {
            reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        } catch (e) {
            console.warn('[Push] SW no se pudo registrar:', e);
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return;

        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            try {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly:      true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                });
            } catch (e) {
                console.warn('[Push] Suscripción fallida:', e);
                return;
            }
        }

        await saveSubscription(sub);
    }

    // ── Botón de prueba ──
    function bindTestButton() {
        const btn = document.getElementById('fc-btn-push-test');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = '⏳';
            try {
                const body = new FormData();
                body.append('action', 'fc_push_test');
                body.append('nonce',  nonce);
                const res  = await fetch(ajaxurl, { method: 'POST', body });
                const json = await res.json();
                btn.textContent = json.success ? '✅' : '❌';
            } catch {
                btn.textContent = '❌';
            }
            setTimeout(() => { btn.textContent = '🔔'; btn.disabled = false; }, 3000);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { init(); bindTestButton(); });
    } else {
        init();
        bindTestButton();
    }
})();
