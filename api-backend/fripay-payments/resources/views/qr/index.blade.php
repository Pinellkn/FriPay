<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>FriPay - QR Code Hors-Ligne</title>
    <meta name="description" content="Envoyez et recevez de l'argent hors-ligne avec des QR Codes signés">
    <meta name="theme-color" content="#22c55e">
    <link rel="manifest" href="/manifest.json">

    <!-- PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <!-- QR Code libs -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <!-- Offline modules -->
    <script src="/js/qr-offline.js"></script>
    <script src="/js/qr-sync.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }

        .qr-glow { box-shadow: 0 0 30px rgba(34, 197, 94, 0.3); }

        .pulse-ring { animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite; }
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            80%, 100% { transform: scale(2); opacity: 0; }
        }

        .slide-up { animation: slideUp 0.3s ease-out; }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Sync indicator */
        .sync-indicator {
            width: 8px; height: 8px; border-radius: 50%;
            transition: all 0.3s;
        }
        .sync-indicator.online { background: #22c55e; box-shadow: 0 0 6px #22c55e; }
        .sync-indicator.offline { background: #ef4444; box-shadow: 0 0 6px #ef4444; animation: pulse 1.5s infinite; }
        .sync-indicator.syncing { background: #f59e0b; box-shadow: 0 0 6px #f59e0b; animation: pulse 0.8s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* Offline badge */
        .offline-banner {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-950 text-white min-h-screen" x-data="qrApp()" x-init="init()">

    <!-- ===== OFFLINE BANNER ===== -->
    <div x-show="!isOnline" x-cloak class="offline-banner px-4 py-2 text-center text-sm font-medium text-black">
        📡 Vous êtes hors-ligne — les opérations seront synchronisées à la reconnexion
    </div>

    <!-- ===== PENDING SYNC BANNER ===== -->
    <div x-show="pendingCount > 0 && isOnline" x-cloak
         class="bg-amber-900/50 border-b border-amber-700/50 px-4 py-2 text-center text-xs text-amber-300 flex items-center justify-center gap-2">
        <div class="sync-indicator syncing"></div>
        <span x-text="pendingCount + ' opération(s) en attente de synchronisation'"></span>
        <button @click="forceSync()" class="underline hover:no-underline font-medium">Synchroniser</button>
    </div>

    <!-- ===== HEADER ===== -->
    <header class="bg-gray-900 border-b border-gray-800 px-4 py-3">
        <div class="max-w-lg mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center font-bold text-sm">F</div>
                <span class="font-semibold text-lg">FriPay</span>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-xs text-gray-500 hidden sm:block" x-text="storageInfo"></div>
                <div id="sync-indicator" class="sync-indicator"
                     :class="isOnline ? 'online' : 'offline'"
                     :title="isOnline ? 'En ligne' : 'Hors-ligne'"></div>
            </div>
        </div>
    </header>

    <main class="max-w-lg mx-auto px-4 py-6">

        <!-- ===== TABS ===== -->
        <div class="flex gap-2 mb-6">
            <button @click="tab = 'generate'" :class="tab === 'generate' ? 'bg-green-500 text-white' : 'bg-gray-800 text-gray-300'"
                    class="flex-1 py-2.5 rounded-xl font-medium text-sm transition-all">
                📤 Créer
            </button>
            <button @click="tab = 'scan'" :class="tab === 'scan' ? 'bg-blue-500 text-white' : 'bg-gray-800 text-gray-300'"
                    class="flex-1 py-2.5 rounded-xl font-medium text-sm transition-all">
                📷 Scanner
            </button>
            <button @click="tab = 'vault'" :class="tab === 'vault' ? 'bg-purple-500 text-white' : 'bg-gray-800 text-gray-300'"
                    class="flex-1 py-2.5 rounded-xl font-medium text-sm relative">
                🔒 Coffre
                <span x-show="vault.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-purple-500 rounded-full text-[10px] flex items-center justify-center font-bold"
                      x-text="vault.length"></span>
            </button>
        </div>

        <!-- ===================== TAB: GENERATE ===================== -->
        <div x-show="tab === 'generate'" x-cloak class="slide-up">

            <!-- Generated QR Display -->
            <div x-show="generatedQr" class="mb-6">
                <div class="bg-gray-900 rounded-2xl p-6 border border-gray-700 qr-glow text-center">
                    <div class="relative inline-block mb-4">
                        <div class="absolute inset-0 bg-green-500 rounded-full pulse-ring opacity-20"></div>
                        <canvas id="qr-canvas" class="rounded-xl"></canvas>
                    </div>
                    <div class="text-3xl font-bold text-green-400 mb-1" x-text="formatAmount(generatedQr?.amount)"></div>
                    <div class="text-sm text-gray-400 mb-3" x-text="'Expire le ' + formatDate(generatedQr?.expires_at)"></div>
                    <div class="bg-gray-800 rounded-lg px-3 py-2 text-xs text-gray-500 font-mono break-all" x-text="generatedQr?.uuid"></div>

                    <!-- Offline badge -->
                    <div x-show="generatedQr?.is_local" class="mt-3 inline-flex items-center gap-1 bg-amber-900/30 text-amber-400 text-xs px-3 py-1 rounded-full">
                        ⚡ Généré hors-ligne — sync à la reconnexion
                    </div>

                    <div class="flex gap-2 mt-4">
                        <button @click="shareQr()" class="flex-1 bg-green-600 hover:bg-green-500 py-2.5 rounded-xl text-sm font-medium transition">
                            📤 Partager
                        </button>
                        <button @click="downloadQr()" class="flex-1 bg-gray-700 hover:bg-gray-600 py-2.5 rounded-xl text-sm font-medium transition">
                            💾 Sauvegarder
                        </button>
                        <button @click="revokeQr(generatedQr?.uuid)" class="bg-red-600/20 hover:bg-red-600/40 text-red-400 px-4 py-2.5 rounded-xl text-sm font-medium transition">
                            ✕
                        </button>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800">
                <h3 class="font-semibold mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 bg-green-500/20 rounded-lg flex items-center justify-center text-green-400 text-sm">💰</span>
                    Créer un QR Code
                    <span class="ml-auto text-xs text-gray-500" x-text="isOnline ? '🟢 En ligne' : '🔴 Hors-ligne'"></span>
                </h3>

                <div class="space-y-4">
                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Montant (FCFA)</label>
                        <div class="relative">
                            <input type="number" x-model="form.amount" min="100" max="500000" step="100"
                                   class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-2xl font-bold text-green-400 focus:border-green-500 focus:outline-none transition"
                                   placeholder="0">
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm">FCFA</span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <template x-for="amt in [500, 1000, 2000, 5000, 10000, 25000]" :key="amt">
                                <button @click="form.amount = amt"
                                        class="flex-1 bg-gray-800 hover:bg-gray-700 py-1.5 rounded-lg text-xs text-gray-300 transition"
                                        x-text="formatAmount(amt)"></button>
                            </template>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Durée de validité</label>
                        <select x-model="form.expires_minutes" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none transition">
                            <option value="5">5 minutes</option>
                            <option value="15">15 minutes</option>
                            <option value="30">30 minutes</option>
                            <option value="60" selected>1 heure</option>
                            <option value="240">4 heures</option>
                            <option value="1440">24 heures</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm text-gray-400 mb-1 block">Destinataire (optionnel)</label>
                        <input type="text" x-model="form.recipient_hint"
                               class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none transition"
                               placeholder="Nom ou numéro">
                    </div>

                    <button @click="generateQr()"
                            :disabled="!form.amount || form.amount < 100 || loading"
                            class="w-full bg-green-500 hover:bg-green-400 disabled:bg-gray-700 disabled:text-gray-500 py-3.5 rounded-xl font-semibold text-lg transition flex items-center justify-center gap-2">
                        <svg x-show="loading" class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25"/><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity="0.75"/></svg>
                        <span x-text="loading ? 'Génération...' : (isOnline ? 'Générer le QR Code' : '⚡ Générer hors-ligne')"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ===================== TAB: SCAN ===================== -->
        <div x-show="tab === 'scan'" x-cloak class="slide-up">
            <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800 mb-4">
                <h3 class="font-semibold mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center text-blue-400 text-sm">📷</span>
                    Scanner un QR Code
                    <span class="ml-auto text-xs text-gray-500" x-text="isOnline ? '🟢 En ligne' : '🔴 Hors-ligne'"></span>
                </h3>
                <div id="qr-reader" class="rounded-xl overflow-hidden mb-4" style="min-height: 250px;"></div>
                <div class="flex gap-2">
                    <button @click="startScanner()" x-show="!scannerRunning" class="flex-1 bg-blue-500 hover:bg-blue-400 py-3 rounded-xl font-medium transition">📷 Démarrer</button>
                    <button @click="stopScanner()" x-show="scannerRunning" class="flex-1 bg-red-500 hover:bg-red-400 py-3 rounded-xl font-medium transition">⏹ Arrêter</button>
                    <button @click="pasteFromClipboard()" class="bg-gray-700 hover:bg-gray-600 px-4 py-3 rounded-xl font-medium transition text-sm">📋 Coller</button>
                </div>
            </div>

            <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800 mb-4">
                <h3 class="font-semibold mb-3 text-sm text-gray-400">Ou coller le contenu :</h3>
                <textarea x-model="scanInput" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm font-mono focus:border-blue-500 focus:outline-none transition" placeholder="Contenu du QR Code"></textarea>
                <button @click="verifyQr()" :disabled="!scanInput" class="w-full bg-blue-500 hover:bg-blue-400 disabled:bg-gray-700 py-3 rounded-xl font-medium mt-3 transition">🔍 Vérifier</button>
            </div>

            <div x-show="verifyResult" x-cloak class="slide-up">
                <div :class="verifyResult?.valid ? 'border-green-500/50 bg-green-500/10' : 'border-red-500/50 bg-red-500/10'" class="rounded-2xl p-5 border">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-3xl" x-text="verifyResult?.valid ? '✅' : '❌'"></span>
                        <div>
                            <div class="font-semibold" x-text="verifyResult?.valid ? 'QR Valide' : 'QR Invalide'"></div>
                            <div class="text-sm text-gray-400" x-text="verifyResult?.error || 'Signature vérifiée ✓'"></div>
                            <div x-show="verifyResult?.offline_verified" class="text-xs text-amber-400 mt-1">⚡ Vérifié hors-ligne (signature locale)</div>
                        </div>
                    </div>
                    <template x-if="verifyResult?.valid">
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm"><span class="text-gray-400">Montant</span><span class="font-bold text-green-400" x-text="formatAmount(verifyResult?.amount)"></span></div>
                            <div class="flex justify-between text-sm"><span class="text-gray-400">Statut</span><span :class="verifyResult?.status === 'active' ? 'text-green-400' : 'text-yellow-400'" x-text="verifyResult?.status"></span></div>
                            <button @click="receiveQr()" class="w-full bg-green-500 hover:bg-green-400 py-2.5 rounded-xl font-medium transition mt-4">
                                📥 Recevoir <span x-show="!isOnline" class="text-xs opacity-75">(stocké localement)</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ===================== TAB: VAULT ===================== -->
        <div x-show="tab === 'vault'" x-cloak class="slide-up">
            <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800">
                <h3 class="font-semibold mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center text-purple-400 text-sm">🔒</span>
                    Mon Coffre
                    <span class="ml-auto text-xs text-gray-500" x-text="vault.length + ' QR code(s)'"></span>
                </h3>

                <template x-if="vault.length === 0">
                    <div class="text-center py-8 text-gray-500">
                        <div class="text-4xl mb-2">📦</div>
                        <div>Votre coffre est vide</div>
                        <div class="text-xs mt-1">Les QR codes reçus apparaîtront ici</div>
                    </div>
                </template>

                <div class="space-y-3">
                    <template x-for="(qr, index) in vault" :key="qr.uuid">
                        <div class="bg-gray-800 rounded-xl p-4 border border-gray-700 slide-up">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <span x-text="qr.status === 'received' ? '🔒' : qr.status === 'redeemed' ? '✅' : qr.status === 'pending_sync' ? '⏳' : '❌'" class="text-xl"></span>
                                    <div>
                                        <div class="font-semibold" x-text="formatAmount(qr.amount)"></div>
                                        <div class="text-xs text-gray-400">
                                            <span x-text="qr.status"></span>
                                            <span x-show="qr.status === 'pending_sync'" class="text-amber-400"> — sync à la reconnexion</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 text-right">
                                    <div x-text="formatDate(qr.expires_at)"></div>
                                    <div x-show="qr.is_local" class="text-amber-400">⚡ local</div>
                                </div>
                            </div>

                            <!-- Events timeline -->
                            <template x-if="qr.events && qr.events.length > 0">
                                <div class="mt-2 space-y-1">
                                    <template x-for="event in qr.events.slice(-3)" :key="event.timestamp">
                                        <div class="flex items-center gap-2 text-[10px] text-gray-500">
                                            <span x-text="eventIcon(event.type)"></span>
                                            <span x-text="event.type"></span>
                                            <span class="ml-auto" x-text="formatTime(event.timestamp)"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="qr.status === 'received' || qr.status === 'active'">
                                <div class="flex gap-2 mt-3">
                                    <button @click="redeemQr(qr.uuid)" :disabled="!isOnline && qr.status === 'received'"
                                            class="flex-1 bg-purple-500 hover:bg-purple-400 disabled:bg-gray-600 py-2 rounded-lg text-sm font-medium transition">
                                        💰 Encaisser
                                    </button>
                                    <button @click="transferQrPrompt(qr)" class="flex-1 bg-gray-700 hover:bg-gray-600 py-2 rounded-lg text-sm font-medium transition">
                                        📤 Transférer
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </main>

    <!-- ===== TRANSFER MODAL ===== -->
    <div x-show="showTransferModal" x-cloak class="fixed inset-0 bg-black/70 flex items-end justify-center z-50" @click.self="showTransferModal = false">
        <div class="bg-gray-900 w-full max-w-lg rounded-t-2xl p-6 slide-up">
            <h3 class="font-semibold text-lg mb-4">📤 Transférer le QR Code</h3>
            <input type="tel" x-model="transferPhone" class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 mb-4 focus:border-purple-500 focus:outline-none" placeholder="Numéro du destinataire">
            <div class="flex gap-2">
                <button @click="showTransferModal = false" class="flex-1 bg-gray-700 py-3 rounded-xl font-medium">Annuler</button>
                <button @click="transferQr()" :disabled="!transferPhone" class="flex-1 bg-purple-500 hover:bg-purple-400 disabled:bg-gray-700 py-3 rounded-xl font-medium transition">Confirmer</button>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div x-show="toast.show" x-cloak x-transition
         :class="toast.type === 'success' ? 'bg-green-600' : toast.type === 'warning' ? 'bg-amber-600' : 'bg-red-600'"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 px-6 py-3 rounded-xl font-medium text-sm shadow-lg z-50"
         x-text="toast.message"></div>

    <script>
    /**
     * FriPay QR Code App — Full Offline Support
     *
     * Fonctionnement hors-ligne :
     * 1. Génération QR : les clés Ed25519 sont générées côté client (WebCrypto API)
     * 2. Vérification QR : la signature est vérifiée localement sans serveur
     * 3. Réception QR : stockée dans IndexedDB, synchronisée à la reconnexion
     * 4. Encaissement/Transfert : mise en file d'attente si hors-ligne
     */
    function qrApp() {
        return {
            // ── State ──
            tab: 'generate',
            loading: false,
            isOnline: navigator.onLine,
            pendingCount: 0,
            storageInfo: '',

            // Generate
            form: { amount: null, expires_minutes: 60, recipient_hint: '' },
            generatedQr: null,

            // Scan
            scanInput: '',
            verifyResult: null,
            scannerRunning: false,
            html5QrCode: null,

            // Vault
            vault: [],

            // Transfer
            showTransferModal: false,
            selectedQr: null,
            transferPhone: '',

            // Toast
            toast: { show: false, message: '', type: 'success' },

            // ── API ──
            apiBase: '/api/v1',

            // ── Crypto Keys (WebCrypto API for offline signing) ──
            cryptoKeyCache: {},

            // ══════════════════════════════════════════════
            //  INIT
            // ══════════════════════════════════════════════
            async init() {
                // Register Service Worker
                if ('serviceWorker' in navigator) {
                    const reg = await navigator.serviceWorker.register('/sw.js');
                    console.log('[FriPay] SW registered:', reg.scope);

                    // Listen for SW messages
                    navigator.serviceWorker.addEventListener('message', (event) => {
                        if (event.data.type === 'SYNC_REQUEST') {
                            FriPaySync.syncNow().then(r => this.refreshPendingCount());
                        }
                    });

                    // Request periodic background sync
                    if ('periodicSync' in reg) {
                        const status = await navigator.permissions.query({ name: 'periodic-background-sync' });
                        if (status.state === 'granted') {
                            reg.periodicSync.register('fripay-qr-sync', { minInterval: 60_000 });
                        }
                    }
                }

                // Init IndexedDB
                await FriPayDB.open();

                // Init Sync module
                FriPaySync.start();
                FriPaySync.onStatus(({ online, syncing }) => {
                    this.isOnline = online;
                    if (!syncing) this.refreshPendingCount();
                });

                // Load vault from IndexedDB
                await this.loadVault();

                // Network listeners
                window.addEventListener('online', () => {
                    this.isOnline = true;
                    this.showToast('🟢 Connexion rétablie ! Synchronisation...', 'success');
                    setTimeout(() => this.forceSync(), 2000);
                });
                window.addEventListener('offline', () => {
                    this.isOnline = false;
                    this.showToast('📡 Hors-ligne — le mode local est actif', 'warning');
                });

                // Clean expired QR codes
                await FriPayDB.cleanExpired();
                await this.updateStorageInfo();
            },

            // ══════════════════════════════════════════════
            //  GENERATE QR CODE
            // ══════════════════════════════════════════════
            async generateQr() {
                if (!this.form.amount || this.form.amount < 100) return;
                this.loading = true;

                try {
                    if (this.isOnline) {
                        // Mode en ligne : API serveur
                        const token = localStorage.getItem('fripay_token');
                        const res = await fetch(`${this.apiBase}/qr/generate`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
                            },
                            body: JSON.stringify(this.form),
                        });

                        if (!res.ok) {
                            const err = await res.json();
                            throw new Error(err.message || 'Erreur serveur');
                        }

                        const data = await res.json();
                        this.generatedQr = { ...data, is_local: false };

                        // Sauvegarder dans IndexedDB
                        await FriPayDB.saveQr({
                            uuid: data.uuid,
                            amount: data.amount,
                            currency: data.currency,
                            status: 'active',
                            qr_content: data.qr_code,
                            created_at: new Date().toISOString(),
                            expires_at: data.expires_at,
                            events: [{ type: 'generated', timestamp: new Date().toISOString() }],
                            is_local: false,
                        });

                    } else {
                        // Mode hors-ligne : génération locale
                        const data = this.generateQrLocal();
                        this.generatedQr = data;
                    }

                    // Afficher le QR Code
                    await this.$nextTick();
                    this.renderQrCanvas(this.generatedQr.qr_code);
                    this.showToast('QR Code généré !', 'success');
                    await this.loadVault();
                    await this.updateStorageInfo();

                } catch (err) {
                    this.showToast(err.message || 'Erreur lors de la génération', 'error');
                } finally {
                    this.loading = false;
                }
            },

            /**
             * Génération locale d'un QR Code hors-ligne.
             * Utilise le WebCrypto API pour les signatures Ed25519.
             */
            async generateQrLocal() {
                const uuid = this.randomUUID();
                const amount = this.form.amount;
                const currency = 'XOF';
                const now = new Date();
                const expiresAt = new Date(now.getTime() + (this.form.expires_minutes || 60) * 60000);

                // Générer une paire de clés Ed25519 via WebCrypto
                const keyPair = await window.crypto.subtle.generateKey(
                    { name: 'Ed25519', namedCurve: 'P-256' },
                    true,
                    ['sign', 'verify']
                );

                // Exporter les clés
                const pubKeyRaw = new Uint8Array(await window.crypto.subtle.exportKey('raw', keyPair.publicKey));

                // Créer le payload
                const payload = {
                    version: '1.0',
                    uuid: uuid,
                    amount: amount,
                    currency: currency,
                    sender_pubkey: this.base64Encode(pubKeyRaw),
                    timestamp: now.toISOString(),
                    recipient_hint: this.form.recipient_hint || null,
                    expires_at: expiresAt.toISOString(),
                };

                const payloadStr = JSON.stringify(payload);

                // Signer avec la clé privée
                const encoder = new TextEncoder();
                const signature = new Uint8Array(await window.crypto.subtle.sign(
                    'Ed25519',
                    keyPair.privateKey,
                    encoder.encode(payloadStr)
                ));

                // Construire le contenu QR
                const qrContent = JSON.stringify({
                    app: 'fripay',
                    version: '1.0',
                    type: 'offline_qr',
                    payload: payloadStr,
                    signature: this.base64Encode(signature),
                });

                // Sauvegarder la clé privée en local
                const privKeyRaw = new Uint8Array(await window.crypto.subtle.exportKey('raw', keyPair.privateKey));
                await FriPayDB.saveKeypair(uuid, {
                    private_key: this.base64Encode(privKeyRaw),
                    public_key: this.base64Encode(pubKeyRaw),
                });

                // Sauvegarder le QR en IndexedDB
                await FriPayDB.saveQr({
                    uuid: uuid,
                    amount: amount,
                    currency: currency,
                    status: 'active',
                    qr_content: qrContent,
                    created_at: now.toISOString(),
                    expires_at: expiresAt.toISOString(),
                    events: [{ type: 'generated_local', timestamp: now.toISOString() }],
                    is_local: true,
                });

                // Queue pour sync plus tard
                await FriPaySync.queueAndSync({
                    type: 'generate',
                    qr_uuid: uuid,
                    payload: { amount, currency: 'XOF', expires_minutes: this.form.expires_minutes, recipient_hint: this.form.recipient_hint },
                });

                return {
                    uuid,
                    qr_code: qrContent,
                    amount,
                    currency,
                    expires_at: expiresAt.toISOString(),
                    status: 'active',
                    is_local: true,
                };
            },

            // ══════════════════════════════════════════════
            //  VERIFY QR CODE
            // ══════════════════════════════════════════════
            async verifyQr() {
                if (!this.scanInput) return;
                this.loading = true;

                try {
                    const input = this.scanInput.trim();
                    const data = JSON.parse(input);

                    // Vérification locale de la signature
                    let valid = false;
                    let error = null;
                    let qrData = null;

                    try {
                        qrData = JSON.parse(data.payload);
                        const pubKeyRaw = this.base64Decode(qrData.sender_pubkey);
                        const pubKey = await window.crypto.subtle.importKey(
                            'raw', pubKeyRaw,
                            { name: 'Ed25519', namedCurve: 'P-256' },
                            false, ['verify']
                        );

                        const encoder = new TextEncoder();
                        const sigRaw = this.base64Decode(data.signature);
                        valid = await window.crypto.subtle.verify('Ed25519', pubKey, sigRaw, encoder.encode(data.payload));

                        // Vérifier expiration
                        if (valid && qrData.expires_at && new Date(qrData.expires_at) < new Date()) {
                            valid = false;
                            error = 'QR Code expiré';
                        }
                    } catch (err) {
                        valid = false;
                        error = 'Signature invalide ou format incorrect';
                    }

                    this.verifyResult = {
                        valid,
                        status: valid ? 'active' : 'invalid',
                        error,
                        amount: qrData?.amount,
                        currency: qrData?.currency,
                        data: qrData,
                        offline_verified: true,
                    };

                    this.scanInput = '';
                } catch (err) {
                    this.verifyResult = { valid: false, error: 'Format QR Code invalide', status: 'invalid' };
                } finally {
                    this.loading = false;
                }
            },

            // ══════════════════════════════════════════════
            //  RECEIVE QR CODE
            // ══════════════════════════════════════════════
            async receiveQr() {
                if (!this.verifyResult?.valid || !this.verifyResult?.data) return;

                const data = this.verifyResult.data;
                const qr = {
                    uuid: data.uuid,
                    amount: data.amount,
                    currency: data.currency || 'XOF',
                    status: 'received',
                    qr_content: this.scanInput || JSON.stringify({ app: 'fripay', payload: JSON.stringify(data), signature: '' }),
                    created_at: data.timestamp || new Date().toISOString(),
                    expires_at: data.expires_at,
                    events: [{ type: 'received_local', timestamp: new Date().toISOString() }],
                    is_local: true,
                };

                // Sauvegarder dans IndexedDB
                await FriPayDB.saveQr(qr);

                // Queue pour sync
                await FriPaySync.queueAndSync({
                    type: 'receive',
                    qr_uuid: data.uuid,
                    payload: { qr_content: JSON.stringify({ app: 'fripay', version: '1.0', type: 'offline_qr', payload: JSON.stringify(data), signature: '' }) },
                });

                this.verifyResult = null;
                await this.loadVault();
                this.tab = 'vault';
                this.showToast('📥 QR Code reçu et stocké dans votre coffre !', 'success');
                await this.updateStorageInfo();
            },

            // ══════════════════════════════════════════════
            //  REDEEM QR CODE
            // ══════════════════════════════════════════════
            async redeemQr(uuid) {
                this.loading = true;

                try {
                    if (this.isOnline) {
                        const token = localStorage.getItem('fripay_token');
                        const res = await fetch(`${this.apiBase}/qr/redeem`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? { 'Authorization': `Bearer ${token}` } : {}) },
                            body: JSON.stringify({ uuid }),
                        });

                        if (!res.ok) throw new Error((await res.json()).message || 'Erreur serveur');
                    }

                    // Mettre à jour localement
                    await FriPayDB.updateQrStatus(uuid, 'redeemed');
                    await FriPayDB.addQrEvent(uuid, { type: 'redeemed', timestamp: new Date().toISOString() });

                    if (!this.isOnline) {
                        await FriPaySync.queueAndSync({ type: 'redeem', qr_uuid: uuid });
                        this.showToast('💰 Encaissé localement — sync à la reconnexion', 'warning');
                    } else {
                        this.showToast('💰 QR Code encaissé !', 'success');
                    }

                    await this.loadVault();
                } catch (err) {
                    this.showToast(err.message || 'Erreur lors de l\'encaissement', 'error');
                } finally {
                    this.loading = false;
                }
            },

            // ══════════════════════════════════════════════
            //  TRANSFER QR CODE
            // ══════════════════════════════════════════════
            transferQrPrompt(qr) {
                this.selectedQr = qr;
                this.showTransferModal = true;
            },

            async transferQr() {
                if (!this.selectedQr || !this.transferPhone) return;
                this.loading = true;

                try {
                    if (this.isOnline) {
                        const token = localStorage.getItem('fripay_token');
                        const res = await fetch(`${this.apiBase}/qr/transfer`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? { 'Authorization': `Bearer ${token}` } : {}) },
                            body: JSON.stringify({ uuid: this.selectedQr.uuid, recipient_phone: this.transferPhone }),
                        });

                        if (!res.ok) throw new Error((await res.json()).message || 'Erreur serveur');
                    }

                    await FriPayDB.updateQrStatus(this.selectedQr.uuid, 'transferred');
                    await FriPayDB.addQrEvent(this.selectedQr.uuid, { type: 'transferred_local', to: this.transferPhone });

                    if (!this.isOnline) {
                        await FriPaySync.queueAndSync({ type: 'transfer', qr_uuid: this.selectedQr.uuid, payload: { recipient_phone: this.transferPhone } });
                        this.showToast('📤 Transfert enregistré localement', 'warning');
                    } else {
                        this.showToast('📤 QR Code transféré !', 'success');
                    }

                    this.showTransferModal = false;
                    this.transferPhone = '';
                    this.selectedQr = null;
                    await this.loadVault();
                } catch (err) {
                    this.showToast(err.message || 'Erreur lors du transfert', 'error');
                } finally {
                    this.loading = false;
                }
            },

            // ══════════════════════════════════════════════
            //  REVOKE QR CODE
            // ══════════════════════════════════════════════
            async revokeQr(uuid) {
                if (!confirm('Révoquer ce QR Code ?')) return;
                this.loading = true;

                try {
                    if (this.isOnline) {
                        const token = localStorage.getItem('fripay_token');
                        const res = await fetch(`${this.apiBase}/qr/revoke`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? { 'Authorization': `Bearer ${token}` } : {}) },
                            body: JSON.stringify({ uuid }),
                        });

                        if (!res.ok) throw new Error((await res.json()).message || 'Erreur serveur');
                    }

                    await FriPayDB.updateQrStatus(uuid, 'revoked');
                    await FriPayDB.addQrEvent(uuid, { type: 'revoked_local' });

                    if (!this.isOnline) {
                        await FriPaySync.queueAndSync({ type: 'revoke', qr_uuid: uuid });
                    }

                    this.generatedQr = null;
                    await this.loadVault();
                    this.showToast('QR Code révoqué', 'success');
                } catch (err) {
                    this.showToast(err.message || 'Erreur lors de la révocation', 'error');
                } finally {
                    this.loading = false;
                }
            },

            // ══════════════════════════════════════════════
            //  VAULT (IndexedDB)
            // ══════════════════════════════════════════════
            async loadVault() {
                this.vault = await FriPayDB.getAllQr();
                this.vault = this.vault.filter(qr => ['active', 'received', 'pending_sync'].includes(qr.status));
                await this.refreshPendingCount();
            },

            // ══════════════════════════════════════════════
            //  SYNC
            // ══════════════════════════════════════════════
            async forceSync() {
                if (!this.isOnline) return;
                this.showToast('🔄 Synchronisation en cours...', 'warning');
                const result = await FriPaySync.syncNow();
                if (result.synced > 0) {
                    this.showToast(`✅ ${result.synced} opération(s) synchronisée(s)`, 'success');
                }
                await this.loadVault();
            },

            async refreshPendingCount() {
                this.pendingCount = await FriPayDB.countPendingOps();
            },

            // ══════════════════════════════════════════════
            //  SCANNER (Camera)
            // ══════════════════════════════════════════════
            startScanner() {
                this.html5QrCode = new Html5Qrcode('qr-reader');
                this.html5QrCode.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    (text) => {
                        this.scanInput = text;
                        this.stopScanner();
                        this.verifyQr();
                    },
                    () => {}
                );
                this.scannerRunning = true;
            },

            stopScanner() {
                if (this.html5QrCode) {
                    this.html5QrCode.stop().then(() => {
                        this.scannerRunning = false;
                    }).catch(() => {
                        this.scannerRunning = false;
                    });
                }
            },

            async pasteFromClipboard() {
                try {
                    const text = await navigator.clipboard.readText();
                    this.scanInput = text;
                    this.showToast('📋 Collé depuis le presse-papiers', 'success');
                } catch {
                    this.showToast('Impossible d\'accéder au presse-papiers', 'error');
                }
            },

            // ══════════════════════════════════════════════
            //  QR CODE RENDERING
            // ══════════════════════════════════════════════
            renderQrCanvas(content) {
                const canvas = document.getElementById('qr-canvas');
                if (!canvas) return;
                QRCode.toCanvas(canvas, content, {
                    width: 250,
                    margin: 2,
                    color: { dark: '#1a1a2e', light: '#ffffff' },
                });
            },

            shareQr() {
                if (navigator.share && this.generatedQr) {
                    navigator.share({ title: 'FriPay QR Code', text: this.generatedQr.qr_code });
                }
            },

            downloadQr() {
                const canvas = document.getElementById('qr-canvas');
                if (!canvas) return;
                const link = document.createElement('a');
                link.download = `fripay-qr-${this.generatedQr?.uuid?.slice(0, 8)}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
            },

            // ══════════════════════════════════════════════
            //  UTILITIES
            // ══════════════════════════════════════════════
            formatAmount(amt) {
                if (!amt) return '0';
                return new Intl.NumberFormat('fr-FR').format(amt) + ' FCFA';
            },

            formatDate(iso) {
                if (!iso) return '';
                return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            },

            formatTime(iso) {
                if (!iso) return '';
                return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            },

            eventIcon(type) {
                const icons = { generated: '🆕', generated_local: '⚡', received: '📥', received_local: '📥', redeemed: '💰', redeemed_local: '💰', transferred: '📤', transferred_local: '📤', revoked: '❌', revoked_local: '❌' };
                return icons[type] || '•';
            },

            async updateStorageInfo() {
                const stats = await FriPayDB.getStats();
                this.storageInfo = stats.total_qr + ' QR | ' + stats.pending_ops + ' sync';
            },

            showToast(message, type = 'success') {
                this.toast = { show: true, message, type };
                setTimeout(() => { this.toast.show = false; }, 3500);
            },

            randomUUID() {
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = Math.random() * 16 | 0;
                    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            },

            base64Encode(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.length; i++) {
                    binary += String.fromCharCode(bytes[i]);
                }
                return btoa(binary);
            },

            base64Decode(str) {
                const binary = atob(str);
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                return bytes;
            },
        };
    }
    </script>
</body>
</html>
