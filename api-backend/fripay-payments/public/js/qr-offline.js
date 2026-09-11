/**
 * FriPay QR Offline - IndexedDB Storage Module
 *
 * Stocke les QR codes et les opérations en attente de synchronisation
 * dans IndexedDB pour un fonctionnement 100% hors-ligne.
 *
 * Tables :
 *   - qr_codes     : QR codes générés/reçus avec leur cycle de vie
 *   - pending_ops   : Opérations à synchroniser (redeem, transfer, revoke)
 *   - keypairs      : Paires de clés Ed25519 (clé privée = seulement en local)
 */
const FriPayDB = (() => {
    const DB_NAME = 'fripay-qr-offline';
    const DB_VERSION = 1;
    let db = null;

    /**
     * Ouvre (ou crée) la base IndexedDB.
     */
    function open() {
        return new Promise((resolve, reject) => {
            if (db) return resolve(db);

            const req = indexedDB.open(DB_NAME, DB_VERSION);

            req.onupgradeneeded = (e) => {
                const _db = e.target.result;

                // Table des QR codes
                if (!_db.objectStoreNames.contains('qr_codes')) {
                    const store = _db.createObjectStore('qr_codes', { keyPath: 'uuid' });
                    store.createIndex('status', 'status', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                    store.createIndex('expires_at', 'expires_at', { unique: false });
                }

                // Table des opérations en attente
                if (!_db.objectStoreNames.contains('pending_ops')) {
                    const store = _db.createObjectStore('pending_ops', { keyPath: 'id', autoIncrement: true });
                    store.createIndex('type', 'type', { unique: false });
                    store.createIndex('status', 'status', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }

                // Table des paires de clés (local uniquement)
                if (!_db.objectStoreNames.contains('keypairs')) {
                    _db.createObjectStore('keypairs', { keyPath: 'uuid' });
                }
            };

            req.onsuccess = (e) => {
                db = e.target.result;
                resolve(db);
            };

            req.onerror = (e) => reject(e.target.error);
        });
    }

    /**
     * Helpers génériques
     */
    function tx(storeName, mode = 'readonly') {
        return db.transaction(storeName, mode).objectStore(storeName);
    }

    function promisify(request) {
        return new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    // ─── QR CODES ──────────────────────────────────────

    /**
     * Sauvegarde un QR code en local.
     * @param {Object} qr - { uuid, amount, currency, status, qr_content, created_at, expires_at, events[], is_local }
     */
    async function saveQr(qr) {
        await open();
        return promisify(tx('qr_codes', 'readwrite').put({
            ...qr,
            updated_at: new Date().toISOString(),
        }));
    }

    /**
     * Récupère un QR code par UUID.
     */
    async function getQr(uuid) {
        await open();
        return promisify(tx('qr_codes').get(uuid));
    }

    /**
     * Récupère tous les QR codes, triés par date décroissante.
     */
    async function getAllQr() {
        await open();
        const store = tx('qr_codes');
        return new Promise((resolve, reject) => {
            const results = [];
            const req = store.openCursor(null, 'prev');
            req.onsuccess = (e) => {
                const cursor = e.target.result;
                if (cursor) {
                    results.push(cursor.value);
                    cursor.continue();
                } else {
                    resolve(results);
                }
            };
            req.onerror = () => reject(req.error);
        });
    }

    /**
     * Récupère les QR codes par statut.
     */
    async function getQrByStatus(status) {
        await open();
        const store = tx('qr_codes');
        const index = store.index('status');
        return promisify(index.getAll(status));
    }

    /**
     * Met à jour le statut d'un QR code.
     */
    async function updateQrStatus(uuid, status, extra = {}) {
        await open();
        const qr = await getQr(uuid);
        if (!qr) return null;
        return promisify(tx('qr_codes', 'readwrite').put({
            ...qr,
            status,
            ...extra,
            updated_at: new Date().toISOString(),
        }));
    }

    /**
     * Ajoute un événement à l'historique local d'un QR code.
     */
    async function addQrEvent(uuid, event) {
        await open();
        const qr = await getQr(uuid);
        if (!qr) return null;
        if (!qr.events) qr.events = [];
        qr.events.push({
            ...event,
            timestamp: new Date().toISOString(),
        });
        qr.updated_at = new Date().toISOString();
        return promisify(tx('qr_codes', 'readwrite').put(qr));
    }

    /**
     * Supprime un QR code expiré.
     */
    async function deleteQr(uuid) {
        await open();
        return promisify(tx('qr_codes', 'readwrite').delete(uuid));
    }

    // ─── PENDING OPERATIONS ────────────────────────────

    /**
     * Ajoute une opération en attente de synchronisation.
     * @param {Object} op - { type: 'redeem'|'transfer'|'revoke', qr_uuid, payload, status }
     */
    async function addPendingOp(op) {
        await open();
        return promisify(tx('pending_ops', 'readwrite').add({
            ...op,
            status: 'pending',
            retries: 0,
            created_at: new Date().toISOString(),
        }));
    }

    /**
     * Récupère toutes les opérations en attente.
     */
    async function getPendingOps() {
        await open();
        return promisify(tx('pending_ops').getAll());
    }

    /**
     * Met à jour le statut d'une opération.
     */
    async function updatePendingOp(id, status, extra = {}) {
        await open();
        const store = tx('pending_ops', 'readwrite');
        const req = store.get(id);
        return new Promise((resolve, reject) => {
            req.onsuccess = () => {
                const op = req.result;
                if (!op) return resolve(null);
                Object.assign(op, { status, ...extra });
                store.put(op);
                resolve(op);
            };
            req.onerror = () => reject(req.error);
        });
    }

    /**
     * Supprime une opération terminée.
     */
    async function deletePendingOp(id) {
        await open();
        return promisify(tx('pending_ops', 'readwrite').delete(id));
    }

    /**
     * Compte les opérations en attente.
     */
    async function countPendingOps() {
        await open();
        return promisify(tx('pending_ops').count());
    }

    // ─── KEYPAIRS ──────────────────────────────────────

    /**
     * Sauvegarde une paire de clés en local (seulement côté client).
     * IMPORTANT: Les clés privées ne quittent jamais le téléphone.
     */
    async function saveKeypair(uuid, keypair) {
        await open();
        return promisify(tx('keypairs', 'readwrite').put({
            uuid,
            ...keypair,
            created_at: new Date().toISOString(),
        }));
    }

    /**
     * Récupère la paire de clés d'un QR code.
     */
    async function getKeypair(uuid) {
        await open();
        return promisify(tx('keypairs').get(uuid));
    }

    // ─── UTILITIES ─────────────────────────────────────

    /**
     * Nettoie les QR codes expirés du stockage local.
     */
    async function cleanExpired() {
        const all = await getAllQr();
        const now = new Date();
        let count = 0;
        for (const qr of all) {
            if (qr.expires_at && new Date(qr.expires_at) < now && qr.status !== 'redeemed') {
                await updateQrStatus(qr.uuid, 'expired');
                count++;
            }
        }
        return count;
    }

    /**
     * Retourne des statistiques du stockage local.
     */
    async function getStats() {
        const all = await getAllQr();
        const pending = await getPendingOps();
        const statusCounts = {};
        for (const qr of all) {
            statusCounts[qr.status] = (statusCounts[qr.status] || 0) + 1;
        }
        return {
            total_qr: all.length,
            by_status: statusCounts,
            pending_ops: pending.length,
        };
    }

    /**
     * Exporte toutes les données (debug/backup).
     */
    async function exportAll() {
        return {
            qr_codes: await getAllQr(),
            pending_ops: await getPendingOps(),
            exported_at: new Date().toISOString(),
        };
    }

    // Public API
    return {
        open,
        // QR Codes
        saveQr, getQr, getAllQr, getQrByStatus,
        updateQrStatus, addQrEvent, deleteQr,
        // Pending Operations
        addPendingOp, getPendingOps, updatePendingOp, deletePendingOp, countPendingOps,
        // Keys
        saveKeypair, getKeypair,
        // Utilities
        cleanExpired, getStats, exportAll,
    };
})();
