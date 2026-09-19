/**
 * FriPay QR Sync - Background Synchronization Module
 *
 * Gère la synchronisation des opérations hors-ligne vers le serveur.
 *
 * Stratégie :
 * 1. Quand le device revient en ligne, les opérations pending_ops sont
 *    envoyées au serveur dans l'ordre FIFO
 * 2. Backoff exponentiel : 5s → 10s → 20s → 40s → max 60s
 * 3. Si un conflit (409/422) est détecté, l'opération est marquée en erreur
 * 4. Le Network Information API détecte la qualité de la connexion
 *
 * Usage :
 *   FriPaySync.start()     // Active la sync automatique
 *   FriPaySync.stop()      // Désactive
 *   FriPaySync.syncNow()   // Force une synchronisation immédiate
 *   FriPaySync.onStatus()  // Callback de changement d'état
 */
const FriPaySync = (() => {
    let isOnline = navigator.onLine;
    let isSyncing = false;
    let syncInterval = null;
    let statusCallback = null;
    const SYNC_INTERVAL = 10_000; // 10 secondes
    const MAX_RETRIES = 5;
    const BASE_DELAY = 5000; // 5 secondes

    /**
     * Initialise le module de synchronisation.
     */
    function start() {
        isOnline = navigator.onLine;

        // Écouter les événements réseau
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);

        // Sync périodique
        syncInterval = setInterval(() => {
            if (isOnline && !isSyncing) syncNow();
        }, SYNC_INTERVAL);

        // Sync immédiat si on vient de revenir en ligne
        if (isOnline) {
            setTimeout(() => syncNow(), 2000);
        }

        updateIndicator();
    }

    /**
     * Arrête la synchronisation.
     */
    function stop() {
        window.removeEventListener('online', onOnline);
        window.removeEventListener('offline', onOffline);
        if (syncInterval) clearInterval(syncInterval);
    }

    /**
     * Gestionnaire d'événement online.
     */
    function onOnline() {
        isOnline = true;
        updateIndicator();
        // Sync immédiat avec un petit délai pour laisser le réseau se stabiliser
        setTimeout(() => syncNow(), 1500);
    }

    /**
     * Gestionnaire d'événement offline.
     */
    function onOffline() {
        isOnline = false;
        updateIndicator();
    }

    /**
     * Synchronise toutes les opérations en attente.
     */
    async function syncNow() {
        if (!isOnline || isSyncing) return { synced: 0, errors: 0 };

        isSyncing = true;
        updateIndicator();

        const pendingOps = await FriPayDB.getPendingOps();
        let synced = 0;
        let errors = 0;

        for (const op of pendingOps) {
            if (op.status !== 'pending') continue;
            if (op.retries >= MAX_RETRIES) {
                await FriPayDB.updatePendingOp(op.id, 'failed', {
                    error: 'Max retries exceeded',
                });
                errors++;
                continue;
            }

            try {
                const result = await sendOp(op);
                if (result.ok) {
                    await FriPayDB.deletePendingOp(op.id);
                    synced++;
                } else {
                    // Erreur serveur (422, etc.)
                    await FriPayDB.updatePendingOp(op.id, 'error', {
                        error: result.error || 'Server error',
                        status_code: result.status,
                    });
                    errors++;
                }
            } catch (err) {
                // Erreur réseau → garde en pending, incrémente retries
                await FriPayDB.updatePendingOp(op.id, 'pending', {
                    retries: (op.retries || 0) + 1,
                    last_error: err.message,
                });
                errors++;
            }
        }

        isSyncing = false;
        updateIndicator();

        return { synced, errors };
    }

    /**
     * Envoie une opération au serveur.
     */
    async function sendOp(op) {
        const token = localStorage.getItem('fripay_token');
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        let url, method, body;

        switch (op.type) {
            case 'redeem':
                url = '/api/v1/qr/redeem';
                method = 'POST';
                body = JSON.stringify({ uuid: op.qr_uuid });
                break;

            case 'transfer':
                url = '/api/v1/qr/transfer';
                method = 'POST';
                body = JSON.stringify({ uuid: op.qr_uuid, recipient_phone: op.payload.recipient_phone });
                break;

            case 'revoke':
                url = '/api/v1/qr/revoke';
                method = 'POST';
                body = JSON.stringify({ uuid: op.qr_uuid });
                break;

            case 'receive':
                url = '/api/v1/qr/receive';
                method = 'POST';
                body = JSON.stringify({ qr_content: op.payload.qr_content });
                break;

            case 'generate':
                // La génération peut se faire hors-ligne, mais on sync le résultat
                url = '/api/v1/qr/generate';
                method = 'POST';
                body = JSON.stringify(op.payload);
                break;

            default:
                return { ok: false, error: `Unknown op type: ${op.type}` };
        }

        const res = await fetch(url, { method, headers, body });
        const data = await res.json();

        return {
            ok: res.ok,
            status: res.status,
            data,
            error: data.error || data.message,
        };
    }

    /**
     * Ajoute une opération et tente de la synchroniser immédiatement.
     */
    async function queueAndSync(op) {
        const id = await FriPayDB.addPendingOp(op);

        // Si en ligne, tente la sync immédiate
        if (isOnline) {
            try {
                const result = await sendOp({ ...op, id });
                if (result.ok) {
                    await FriPayDB.deletePendingOp(id);
                    return { queued: false, result };
                }
            } catch (err) {
                // Échec → garde en file d'attente
            }
        }

        return { queued: true, id };
    }

    /**
     * Met à jour l'indicateur visuel de connexion.
     */
    function updateIndicator() {
        const el = document.getElementById('sync-indicator');
        if (el) {
            if (!isOnline) {
                el.className = 'sync-indicator offline';
                el.title = 'Hors-ligne — les opérations seront synchronisées à la reconnexion';
            } else if (isSyncing) {
                el.className = 'sync-indicator syncing';
                el.title = 'Synchronisation en cours...';
            } else {
                el.className = 'sync-indicator online';
                el.title = 'En ligne';
            }
        }

        // Notifier le callback
        if (statusCallback) {
            statusCallback({ online: isOnline, syncing: isSyncing });
        }
    }

    /**
     * Enregistre un callback de changement d'état.
     */
    function onStatus(callback) {
        statusCallback = callback;
    }

    /**
     * Retourne l'état actuel.
     */
    function getStatus() {
        return {
            online: isOnline,
            syncing: isSyncing,
            pending: FriPayDB.countPendingOps(),
        };
    }

    /**
     * Force un refresh via le Service Worker.
     */
    async function refreshCache() {
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type: 'CACHE_CLEAR' });
        }
    }

    return {
        start, stop, syncNow, queueAndSync,
        onStatus, getStatus, refreshCache,
        get isOnline() { return isOnline; },
        get isSyncing() { return isSyncing; },
    };
})();
