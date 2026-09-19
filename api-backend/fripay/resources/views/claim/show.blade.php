<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>FriPay — Retirer votre argent</title>
    <meta name="description" content="Retirez l'argent qui vous a été envoyé sur FriPay, même sans compte.">
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
<body class="bg-gray-950 text-white min-h-screen" x-data="claimApp('{{ $uuid }}')" x-init="init()">

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

        <!-- ===== FATAL ERROR (QR introuvable / mauvais type / etc.) ===== -->
        <div x-show="loaded && fatalError" x-cloak class="slide-up text-center py-10">
            <div class="text-5xl mb-4">😕</div>
            <h2 class="text-xl font-semibold mb-2">Ce lien n'est pas valide</h2>
            <p class="text-gray-400 text-sm" x-text="fatalError"></p>
        </div>

        <!-- ===== AMOUNT CARD (visible dès que le lookup a réussi) ===== -->
        <template x-if="loaded && !fatalError">
            <div class="slide-up">
                <div class="bg-gray-900 rounded-2xl p-6 border border-gray-700 text-center mb-6">
                    <div class="text-sm text-gray-400 mb-1">On vous a envoyé</div>
                    <div class="text-4xl font-bold text-green-400" x-text="formatAmount(qr.amount, qr.currency)"></div>
                    <div x-show="qr.already_claimed" class="mt-3 inline-flex items-center gap-1 bg-gray-800 text-gray-400 text-xs px-3 py-1 rounded-full">
                        ✅ Déjà retiré
                    </div>
                    <div x-show="qr.expired && !qr.already_claimed" class="mt-3 inline-flex items-center gap-1 bg-red-900/30 text-red-400 text-xs px-3 py-1 rounded-full">
                        ⏱ Expiré
                    </div>
                    <div x-show="!qr.already_claimed && !qr.expired" class="mt-3 text-xs text-gray-500">
                        <span x-text="qr.attempts_remaining"></span> tentative(s) restante(s) pour le code
                    </div>
                </div>

                <!-- Not claimable at all -->
                <div x-show="!qr.claimable" x-cloak class="text-center text-gray-400 text-sm py-4">
                    Cet argent ne peut plus être retiré depuis cette page.
                </div>

                <!-- ===== STEP: CHOICE (installer / saisir un numéro) ===== -->
                <div x-show="qr.claimable && step === 'choice'" x-cloak class="space-y-3">
                    <a href="https://fripay.bj/app" target="_blank" rel="noopener"
                       class="block w-full text-center bg-gray-800 hover:bg-gray-700 border border-gray-700 py-3.5 rounded-xl font-medium transition">
                        📲 Installer l'appli FriPay
                    </a>
                    <button @click="step = 'form'"
                            class="w-full bg-green-500 hover:bg-green-400 py-3.5 rounded-xl font-semibold text-lg transition">
                        💸 Recevoir sans l'appli
                    </button>
                    <p class="text-xs text-gray-500 text-center px-4">
                        Saisissez un numéro (MTN, Moov, Celtiis…) et le code que
                        l'expéditeur vous a transmis pour recevoir l'argent directement.
                    </p>
                </div>

                <!-- ===== STEP: FORM (numéro + code de validation) ===== -->
                <div x-show="qr.claimable && step === 'form'" x-cloak class="slide-up">
                    <div class="bg-gray-900 rounded-2xl p-5 border border-gray-800 space-y-4">
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Numéro où recevoir l'argent</label>
                            <input type="tel" x-model="form.payout_phone" placeholder="+229 01 XX XX XX XX"
                                   class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none transition">
                        </div>
                        <div>
                            <label class="text-sm text-gray-400 mb-1 block">Code de validation (5 chiffres)</label>
                            <input type="text" inputmode="numeric" maxlength="5" x-model="form.validation_code"
                                   placeholder="•••••"
                                   class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-center tracking-[0.5em] text-xl font-bold focus:border-green-500 focus:outline-none transition">
                        </div>

                        <div x-show="formError" x-cloak class="text-red-400 text-sm bg-red-900/20 border border-red-800/50 rounded-xl px-3 py-2" x-text="formError"></div>

                        <button @click="submitClaim()"
                                :disabled="submitting || !form.payout_phone || form.validation_code.length !== 5"
                                class="w-full bg-green-500 hover:bg-green-400 disabled:bg-gray-700 disabled:text-gray-500 py-3.5 rounded-xl font-semibold text-lg transition">
                            <span x-text="submitting ? 'Vérification…' : 'Recevoir l\'argent'"></span>
                        </button>
                        <button @click="step = 'choice'" class="w-full text-gray-400 text-sm py-1">← Retour</button>
                    </div>
                </div>

                <!-- ===== STEP: SUCCESS ===== -->
                <div x-show="step === 'success'" x-cloak class="slide-up text-center py-8">
                    <div class="text-5xl mb-4">✅</div>
                    <h2 class="text-xl font-semibold mb-2">Retrait effectué</h2>
                    <p class="text-gray-400 text-sm px-4" x-text="successMessage"></p>
                </div>

                <!-- ===== STEP: CANCELLED (3 échecs) ===== -->
                <div x-show="step === 'cancelled'" x-cloak class="slide-up text-center py-8">
                    <div class="text-5xl mb-4">❌</div>
                    <h2 class="text-xl font-semibold mb-2">Transaction annulée</h2>
                    <p class="text-gray-400 text-sm px-4">
                        Le nombre maximal de tentatives a été atteint. L'argent a été
                        retourné à l'expéditeur, qui a été prévenu.
                    </p>
                </div>
            </div>
        </template>
    </main>

    <script>
    /**
     * §6.e — Page web publique de retrait pour un receveur sans compte Fripay.
     * Consomme GET/POST /api/v1/qr/external/{uuid}(/claim). Sans auth.
     */
    function claimApp(uuid) {
        return {
            uuid,
            apiBase: '/api/v1',
            loading: true,
            loaded: false,
            fatalError: null,
            qr: {},
            step: 'choice', // choice | form | success | cancelled
            form: { payout_phone: '', validation_code: '' },
            formError: null,
            submitting: false,
            successMessage: '',

            async init() {
                await this.lookup();
            },

            async lookup() {
                this.loading = true;
                try {
                    const res = await fetch(`${this.apiBase}/qr/external/${this.uuid}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();

                    if (!res.ok) {
                        this.fatalError = this.mapLookupError(data.error);
                    } else {
                        this.qr = data;
                        if (data.already_claimed) this.fatalError = null; // on affiche quand même la carte
                    }
                } catch (e) {
                    this.fatalError = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
                } finally {
                    this.loading = false;
                    this.loaded = true;
                }
            },

            mapLookupError(code) {
                return {
                    QR_NOT_FOUND: "Ce QR code n'existe pas ou a été supprimé.",
                    NOT_EXTERNAL_QR: "Ce lien ne correspond pas à ce type d'envoi.",
                }[code] || "Une erreur est survenue.";
            },

            async submitClaim() {
                this.formError = null;
                this.submitting = true;
                try {
                    const res = await fetch(`${this.apiBase}/qr/external/${this.uuid}/claim`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            payout_phone: this.form.payout_phone,
                            validation_code: this.form.validation_code,
                        }),
                    });
                    const data = await res.json();

                    if (res.ok) {
                        this.successMessage = data.message || 'Votre argent est en cours de règlement.';
                        this.step = 'success';
                        return;
                    }

                    if (data.error === 'MAX_ATTEMPTS_REACHED') {
                        this.step = 'cancelled';
                        return;
                    }

                    if (data.error === 'INVALID_CODE') {
                        this.qr.attempts_remaining = data.attempts_remaining;
                        this.formError = `Code incorrect. ${data.attempts_remaining} tentative(s) restante(s).`;
                        return;
                    }

                    this.formError = {
                        ALREADY_CLAIMED: 'Cet argent a déjà été retiré.',
                        NOT_CLAIMABLE: 'Ce retrait n\'est plus possible.',
                        QR_NOT_FOUND: "Ce QR code n'existe pas.",
                        NOT_EXTERNAL_QR: 'Lien invalide.',
                    }[data.error] || 'Une erreur est survenue, réessayez.';

                } catch (e) {
                    this.formError = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
                } finally {
                    this.submitting = false;
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
