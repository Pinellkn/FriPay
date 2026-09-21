<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>FriPay — Payer ce lien</title>
    <meta name="description" content="Payez via Mobile Money (MTN, Moov) le lien FriPay qui vous a été partagé — sans compte FriPay.">
    <meta name="theme-color" content="#22c55e">
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
        .slide-up { animation: slideUp 0.3s ease-out; }
        @keyframes slideUp {
            from { transform: translateY(16px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-950 text-white min-h-screen" x-data="payLinkApp('{{ $token }}')" x-init="init()">

    <header class="bg-gray-900 border-b border-gray-800 px-4 py-3">
        <div class="max-w-md mx-auto flex items-center gap-2">
            <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center font-bold text-sm">F</div>
            <span class="font-semibold text-lg">FriPay</span>
        </div>
    </header>

    <main class="max-w-md mx-auto px-4 py-8">

        <!-- ===== LOADING ===== -->
        <div x-show="loading && !loaded" class="text-center py-16 text-gray-400">
            <svg class="animate-spin h-8 w-8 mx-auto mb-3" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25"/>
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity="0.75"/>
            </svg>
            Chargement…
        </div>

        <!-- ===== FATAL ERROR (lien introuvable) ===== -->
        <div x-show="loaded && fatalError" x-cloak class="slide-up text-center py-10">
            <div class="text-5xl mb-4">😕</div>
            <h2 class="text-xl font-semibold mb-2">Ce lien n'est pas valide</h2>
            <p class="text-gray-400 text-sm" x-text="fatalError"></p>
        </div>

        <!-- ===== LINK CARD ===== -->
        <template x-if="loaded && !fatalError">
            <div class="slide-up">
                <div class="bg-gray-900 rounded-2xl p-6 border border-gray-700 text-center mb-6">
                    <div class="text-sm text-gray-400 mb-1">
                        <span x-text="link.creator"></span> vous demande
                    </div>
                    <div class="text-4xl font-bold text-green-400" x-text="formatAmount(link.amount, link.currency)"></div>
                    <div x-show="link.description" class="mt-3 text-sm text-gray-300" x-text="link.description"></div>

                    <div x-show="link.status === 'paid'" class="mt-3 inline-flex items-center gap-1 bg-green-900/30 text-green-400 text-xs px-3 py-1 rounded-full">
                        ✅ Déjà payé
                    </div>
                    <div x-show="link.status === 'cancelled'" class="mt-3 inline-flex items-center gap-1 bg-red-900/30 text-red-400 text-xs px-3 py-1 rounded-full">
                        🚫 Lien annulé
                    </div>
                    <div x-show="link.expired && link.status === 'created'" class="mt-3 inline-flex items-center gap-1 bg-red-900/30 text-red-400 text-xs px-3 py-1 rounded-full">
                        ⏱ Expiré
                    </div>
                </div>

                <!-- ===== PAYABLE : choix opérateur + numéro ===== -->
                <div x-show="link.payable && step === 'pay'" x-cloak class="space-y-3">
                    <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800 space-y-4">
                        <div>
                            <label class="text-sm text-gray-400 mb-2 block">Votre opérateur</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button type="button" @click="form.operator = 'MTN'"
                                        :class="form.operator === 'MTN' ? 'border-yellow-400 bg-yellow-400/10' : 'border-gray-700 bg-gray-800'"
                                        class="border-2 rounded-xl py-3 font-semibold transition">
                                    MTN MoMo
                                </button>
                                <button type="button" @click="form.operator = 'MOOV'"
                                        :class="form.operator === 'MOOV' ? 'border-blue-400 bg-blue-400/10' : 'border-gray-700 bg-gray-800'"
                                        class="border-2 rounded-xl py-3 font-semibold transition">
                                    Moov Money
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Votre numéro Mobile Money</label>
                            <input type="tel" x-model="form.phone" placeholder="+229 01 XX XX XX XX"
                                   class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none transition">
                            <p class="text-xs text-gray-500 mt-1">
                                Vous recevrez une demande de paiement sur votre téléphone : validez avec votre code Mobile Money.
                            </p>
                        </div>

                        <div x-show="formError" x-cloak class="text-red-400 text-sm bg-red-900/20 border border-red-800/50 rounded-xl px-3 py-2" x-text="formError"></div>

                        <button @click="submitPay()"
                                :disabled="submitting || !form.phone || !form.operator"
                                class="w-full bg-green-500 hover:bg-green-400 disabled:bg-gray-700 disabled:text-gray-500 py-3.5 rounded-xl font-semibold text-lg transition">
                            <span x-text="submitting ? 'Envoi…' : 'Payer ' + formatAmount(link.amount, link.currency)"></span>
                        </button>
                    </div>
                </div>

                <!-- ===== STEP: WAITING (validation sur le téléphone) ===== -->
                <div x-show="step === 'waiting'" x-cloak class="slide-up text-center py-6">
                    <svg class="animate-spin h-10 w-10 mx-auto mb-4 text-green-400" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25"/>
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity="0.75"/>
                    </svg>
                    <h2 class="text-xl font-semibold mb-2">Validez sur votre téléphone</h2>
                    <p class="text-gray-400 text-sm px-4 mb-4">
                        Une demande de paiement <span x-text="formatAmount(link.amount, link.currency)"></span>
                        a été envoyée sur votre numéro. Composez votre code Mobile Money pour confirmer.
                    </p>
                    <p class="text-xs text-gray-500">
                        Vérification automatique… <span x-text="pollCount"></span>
                    </p>
                </div>

                <!-- ===== STEP: SUCCESS ===== -->
                <div x-show="step === 'success'" x-cloak class="slide-up text-center py-8">
                    <div class="text-5xl mb-4">✅</div>
                    <h2 class="text-xl font-semibold mb-2">Paiement effectué</h2>
                    <p class="text-gray-400 text-sm px-4">
                        <span x-text="formatAmount(link.amount, link.currency)"></span> ont été envoyés à
                        <span x-text="link.creator"></span>. Merci !
                    </p>
                </div>

                <!-- ===== STEP: FAILED ===== -->
                <div x-show="step === 'failed'" x-cloak class="slide-up text-center py-8">
                    <div class="text-5xl mb-4">❌</div>
                    <h2 class="text-xl font-semibold mb-2">Paiement non abouti</h2>
                    <p class="text-gray-400 text-sm px-4 mb-4">
                        Le paiement n'a pas été confirmé. Si vous avez été débité, l'argent sera restitué automatiquement.
                    </p>
                    <button @click="step = 'pay'" class="bg-gray-800 border border-gray-700 px-6 py-3 rounded-xl font-medium">
                        Réessayer
                        </button>
                </div>
            </div>
        </template>
    </main>

    <script>
    /**
     * Page publique de paiement d'un FriPay Link.
     * Consomme GET /api/v1/payment-links/{token}, POST .../pay,
     * GET .../status (polling). Sans auth.
     */
    function payLinkApp(token) {
        return {
            token,
            apiBase: '/api/v1',
            loading: true,
            loaded: false,
            fatalError: null,
            link: {},
            step: 'pay', // pay | waiting | success | failed
            form: { operator: '', phone: '' },
            formError: null,
            submitting: false,
            pollTimer: null,
            pollCount: 0,
            maxPolls: 90,

            async init() {
                await this.lookup();
            },

            async lookup() {
                this.loading = true;
                try {
                    const res = await fetch(`${this.apiBase}/payment-links/${this.token}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();

                    if (!res.ok) {
                        this.fatalError = data.error === 'LINK_NOT_FOUND'
                            ? "Ce lien de paiement n'existe pas ou a été supprimé."
                            : 'Une erreur est survenue.';
                        return;
                    }

                    this.link = data;

                    if (data.status === 'paid') {
                        this.step = 'success';
                    }
                } catch (e) {
                    this.fatalError = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
                } finally {
                    this.loading = false;
                    this.loaded = true;
                }
            },

            async submitPay() {
                this.formError = null;
                this.submitting = true;
                try {
                    const res = await fetch(`${this.apiBase}/payment-links/${this.token}/pay`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            phone: this.form.phone,
                            operator: this.form.operator,
                        }),
                    });
                    const data = await res.json();

                    if (res.ok && data.accepted) {
                        this.step = 'waiting';
                        this.startPolling();
                        return;
                    }

                    if (data.error === 'LINK_NOT_PAYABLE') {
                        this.fatalError = data.message || 'Ce lien ne peut plus être payé.';
                        return;
                    }

                    this.formError = data.message || 'Une erreur est survenue, réessayez.';
                } catch (e) {
                    this.formError = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
                } finally {
                    this.submitting = false;
                }
            },

            startPolling() {
                this.pollCount = 0;
                this.pollTimer = setInterval(() => this.checkStatus(), 3000);
            },

            async checkStatus() {
                this.pollCount++;
                if (this.pollCount > this.maxPolls) {
                    clearInterval(this.pollTimer);
                    this.step = 'failed';
                    return;
                }

                try {
                    const res = await fetch(`${this.apiBase}/payment-links/${this.token}/status`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();

                    if (data.status === 'paid') {
                        clearInterval(this.pollTimer);
                        this.link = data;
                        this.step = 'success';
                    } else if (data.status !== 'created') {
                        // cancelled / expired côté serveur.
                        clearInterval(this.pollTimer);
                        this.link = data;
                        this.step = 'failed';
                    }
                } catch (e) {
                    // Erreur réseau passagère : on retente au prochain tick.
                }
            },

            formatAmount(amt, currency) {
                if (!amt) return '—';
                return new Intl.NumberFormat('fr-FR').format(amt) + ' ' + (currency || 'FCFA');
            },
        };
    }
    </script>
</body>
</html>
