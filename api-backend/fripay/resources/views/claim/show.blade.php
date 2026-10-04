<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>FriPay — Vous avez reçu de l'argent</title>
    <meta name="description" content="Recevez l'argent qui vous a été envoyé sur FriPay : via l'application ou directement sur votre compte mobile.">
    <meta name="theme-color" content="#1E7A5C">
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ivory: '#FAF9F4',
                        ink: '#1E2B25',
                        fern: '#1E7A5C',
                        'fern-deep': '#16332A',
                        'fern-glow': '#3FAE85',
                        'fern-pale': '#EEF3E9',
                        gold: '#E0AA3E',
                        line: '#DEE2D6',
                        danger: '#D93A2B',
                    },
                    fontFamily: { sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                },
            },
        };
    </script>

    <style>
        body { font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif; }
        [x-cloak] { display: none !important; }
        .slide-up { animation: slideUp .35s cubic-bezier(.2,.7,.3,1) both; }
        @keyframes slideUp {
            from { transform: translateY(18px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .pop { animation: pop .45s cubic-bezier(.2,.9,.3,1.4) both; }
        @keyframes pop {
            from { transform: scale(.8); opacity: 0; }
            to   { transform: scale(1);  opacity: 1; }
        }
        .hero-glow {
            background:
                radial-gradient(120% 90% at 85% -10%, rgba(63,174,133,.55) 0%, rgba(63,174,133,0) 55%),
                radial-gradient(120% 100% at 0% 0%, rgba(224,170,62,.28) 0%, rgba(224,170,62,0) 45%),
                linear-gradient(135deg, #16332A 0%, #1E7A5C 62%, #2C9C74 100%);
        }
        .choice-card { transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .choice-card:hover { transform: translateY(-2px); box-shadow: 0 14px 30px -12px rgba(22,51,42,.25); }
        .choice-card:active { transform: translateY(0) scale(.99); }
    </style>
</head>
<body class="bg-ivory text-ink min-h-screen antialiased" x-data="claimApp('{{ $uuid }}', '{{ $playStoreUrl }}', '{{ $appStoreUrl }}')" x-init="init()">

    <!-- ══════════════ HERO ══════════════ -->
    <header class="hero-glow px-4 pt-5 pb-20 rounded-b-[2.5rem] shadow-lg shadow-fern-deep/20">
        <div class="max-w-md mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 bg-white/15 backdrop-blur rounded-xl flex items-center justify-center ring-1 ring-white/25">
                    <span class="text-white font-extrabold text-lg leading-none">F</span>
                </div>
                <span class="text-white font-extrabold text-xl tracking-tight">FriPay</span>
            </div>
            <span class="inline-flex items-center gap-1.5 bg-white/12 ring-1 ring-white/25 text-white text-[11px] font-semibold px-3 py-1.5 rounded-full">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                Transfert sécurisé
            </span>
        </div>
    </header>

    <main class="max-w-md mx-auto px-4 -mt-14 pb-10">

        <!-- ===== LOADING ===== -->
        <div x-show="loading && !loaded" class="text-center py-16 text-fern-deep/50">
            <svg class="animate-spin h-9 w-9 mx-auto mb-4 text-fern" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.2"/>
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity="0.8"/>
            </svg>
            Chargement…
        </div>

        <!-- ===== FATAL ERROR (QR introuvable / mauvais type) ===== -->
        <div x-show="loaded && fatalError" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-xl shadow-fern-deep/5 p-8 text-center mt-6">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-red-50 flex items-center justify-center">
                <span class="text-3xl">😕</span>
            </div>
            <h2 class="text-lg font-extrabold mb-1.5">Ce lien n'est pas valide</h2>
            <p class="text-ink/55 text-sm" x-text="fatalError"></p>
        </div>

        <template x-if="loaded && !fatalError">
            <div class="slide-up">

                <!-- ══════ CARTE MONTANT ══════ -->
                <div class="bg-white rounded-3xl ring-1 ring-line shadow-xl shadow-fern-deep/5 p-6 text-center">
                    <div class="text-[13px] text-ink/55 mb-1">On vous a envoyé</div>
                    <div class="text-4xl font-extrabold text-fern tracking-tight" x-text="formatAmount(qr.amount, qr.currency)"></div>

                    <div x-show="qr.already_claimed" class="mt-4 inline-flex items-center gap-1.5 bg-ink/5 text-ink/60 text-xs font-bold px-3.5 py-1.5 rounded-full">
                        ✅ Déjà retiré
                    </div>
                    <div x-show="qr.expired && !qr.already_claimed" class="mt-4 inline-flex items-center gap-1.5 bg-red-50 text-danger text-xs font-bold px-3.5 py-1.5 rounded-full">
                        ⏱ Expiré
                    </div>
                    <div x-show="!qr.already_claimed && !qr.expired && qr.claimable" class="mt-4 inline-flex items-center gap-1.5 bg-fern-pale text-fern text-xs font-bold px-3.5 py-1.5 rounded-full">
                        🔐 <span x-text="qr.attempts_remaining"></span> tentative<span x-show="qr.attempts_remaining > 1">s</span> pour le code
                    </div>
                </div>

                <!-- Not claimable at all -->
                <div x-show="!qr.claimable && !qr.already_claimed" x-cloak class="bg-white rounded-3xl ring-1 ring-line p-6 mt-4 text-center text-ink/55 text-sm">
                    Cet argent ne peut plus être retiré depuis cette page.
                </div>

                <!-- ══════ CHOIX ══════ -->
                <div x-show="qr.claimable && step === 'choice'" x-cloak>
                    <p class="text-[13px] font-bold text-ink/70 mt-7 mb-3 px-1">Comment voulez-vous recevoir cet argent ?</p>

                    <div class="space-y-3">
                        <!-- Bloc 1 : Télécharger l'application -->
                        <button type="button" @click="showStores()"
                                class="choice-card w-full text-left bg-white rounded-2xl ring-1 ring-line p-4 flex items-center gap-4 shadow-sm">
                            <div class="w-12 h-12 shrink-0 rounded-2xl hero-glow flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path d="M12 3v11m0 0l-4-4m4 4l4-4" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M4 17v1.5A2.5 2.5 0 006.5 21h11a2.5 2.5 0 002.5-2.5V17" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-extrabold text-[15px]">Télécharger l'application</div>
                                <div class="text-xs text-ink/55 mt-0.5">Créez votre compte FriPay — l'argent y sera versé.</div>
                            </div>
                            <svg class="w-5 h-5 text-ink/25 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>

                        <!-- Bloc 2 : Recevoir sur mon compte mobile -->
                        <button type="button" @click="step = 'form'"
                                class="choice-card w-full text-left bg-white rounded-2xl ring-1 ring-line p-4 flex items-center gap-4 shadow-sm">
                            <div class="w-12 h-12 shrink-0 rounded-2xl bg-gold/15 ring-1 ring-gold/30 flex items-center justify-center">
                                <svg class="w-6 h-6 text-gold" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="2.5" y="6" width="19" height="12" rx="2.5"/>
                                    <path d="M6 10h4v4H6z" fill="currentColor" stroke="none" opacity=".85"/>
                                    <path d="M14.5 10.5h4M14.5 13.5h4" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-extrabold text-[15px]">Recevoir sur mon compte mobile</div>
                                <div class="text-xs text-ink/55 mt-0.5">MTN · Moov · Celtiis — avec le code de vérification.</div>
                            </div>
                            <svg class="w-5 h-5 text-ink/25 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ══════ BLOC : STORES ══════ -->
                <div x-show="step === 'stores'" x-cloak class="slide-up">
                    <div class="bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-5 mt-7 space-y-4">
                        <button type="button" @click="step = 'choice'" class="text-ink/45 text-sm font-semibold flex items-center gap-1 hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg> Retour
                        </button>

                        <div>
                            <h2 class="font-extrabold text-lg">Télécharger l'application</h2>
                            <p class="text-sm text-ink/55 mt-1">Installez FriPay et créez votre compte avec ce numéro : l'argent vous sera automatiquement versé sur votre compte dès l'installation.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <a :href="playStoreUrl" target="_blank" rel="noopener"
                               class="flex items-center justify-center gap-2 bg-ink text-white py-4 rounded-xl font-bold text-sm hover:bg-ink/90 transition">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 1.8L14.9 12 3.6 22.2c-.4-.3-.6-.8-.6-1.4V3.2c0-.6.2-1.1.6-1.4zm12.5 9l2.9-2.7 3.3 1.9c.9.5.9 1.5 0 2l-3.3 1.9-2.9-3.1zm-1.2-1.1L5.5 1.1c.3-.1.7-.1 1.1.1l11.2 6.4-2.9 2.1zM5.5 22.9l9.4-8.6 2.9 2.1-11.2 6.4c-.4.2-.8.2-1.1.1z"/></svg>
                                Google Play
                            </a>
                            <a :href="appStoreUrl" target="_blank" rel="noopener"
                               class="flex items-center justify-center gap-2 bg-ink text-white py-4 rounded-xl font-bold text-sm hover:bg-ink/90 transition">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 12.54c-.03-2.89 2.36-4.27 2.47-4.34-1.35-1.97-3.44-2.24-4.18-2.27-1.78-.18-3.47 1.05-4.37 1.05-.9 0-2.29-1.02-3.77-1-1.94.03-3.72 1.13-4.72 2.86-2.01 3.49-.51 8.66 1.45 11.49.96 1.39 2.1 2.94 3.6 2.88 1.44-.06 1.99-.93 3.73-.93s2.23.93 3.76.9c1.55-.03 2.53-1.41 3.48-2.8 1.09-1.61 1.54-3.17 1.57-3.25-.03-.02-3-1.15-3.02-4.59zM14.16 4.06c.79-.96 1.33-2.29 1.18-3.62-1.14.05-2.53.76-3.35 1.72-.73.85-1.38 2.21-1.21 3.51 1.28.1 2.58-.65 3.38-1.61z"/></svg>
                                App Store
                            </a>
                        </div>

                        <p class="text-xs text-ink/45 px-1">
                            Vous préférez ne pas installer l'application ? Vous pouvez recevoir l'argent directement sur votre compte mobile — revenez en arrière et choisissez l'autre option.
                        </p>
                    </div>
                </div>

                <!-- ══════ BLOC : FORMULAIRE DE RETRAIT MOBILE ══════ -->
                <div x-show="qr.claimable && step === 'form'" x-cloak class="slide-up">
                    <div class="bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-5 mt-7 space-y-4">
                        <button type="button" @click="step = 'choice'" class="text-ink/45 text-sm font-semibold flex items-center gap-1 hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg> Retour
                        </button>

                        <div>
                            <h2 class="font-extrabold text-lg">Recevoir sur mon compte mobile</h2>
                            <p class="text-sm text-ink/55 mt-1">Entrez votre numéro et le code de vérification que l'expéditeur vous a transmis.</p>
                        </div>

                        <div>
                            <label class="text-[13px] font-bold text-ink/70 mb-1.5 block">Numéro où recevoir l'argent</label>
                            <input type="tel" x-model="form.payout_phone" placeholder="+229 01 XX XX XX XX"
                                   class="w-full bg-ivory border border-line rounded-xl px-4 py-3.5 focus:border-fern focus:ring-4 focus:ring-fern/10 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="text-[13px] font-bold text-ink/70 mb-1.5 block">Code de vérification (5 chiffres)</label>
                            <input type="text" inputmode="numeric" maxlength="5" x-model="form.validation_code"
                                   placeholder="•••••"
                                   class="w-full bg-ivory border border-line rounded-xl px-4 py-3.5 text-center tracking-[0.5em] text-xl font-extrabold focus:border-fern focus:ring-4 focus:ring-fern/10 focus:outline-none transition">
                            <p class="text-xs text-ink/45 mt-1.5">
                                <span x-text="qr.attempts_remaining"></span> tentative<span x-show="qr.attempts_remaining > 1">s</span> restante<span x-show="qr.attempts_remaining > 1">s</span> — après 3 échecs, l'argent retourne à l'expéditeur.
                            </p>
                        </div>

                        <div x-show="formError" x-cloak class="text-danger text-sm bg-red-50 border border-danger/20 rounded-xl px-3.5 py-2.5" x-text="formError"></div>

                        <button type="button" @click="submitClaim()"
                                :disabled="submitting || !form.payout_phone || form.validation_code.length !== 5"
                                class="w-full bg-fern hover:bg-[#186B51] disabled:bg-ink/10 disabled:text-ink/35 py-4 rounded-xl font-bold text-lg text-white transition">
                            <span x-text="submitting ? 'Vérification…' : 'Recevoir ' + formatAmount(qr.amount, qr.currency)"></span>
                        </button>
                    </div>
                </div>

                <!-- ══════ SUCCÈS ══════ -->
                <div x-show="step === 'success'" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-8 mt-7 text-center">
                    <div class="pop w-20 h-20 mx-auto mb-4 rounded-full bg-fern-pale flex items-center justify-center">
                        <span class="text-4xl">✅</span>
                    </div>
                    <h2 class="text-xl font-extrabold mb-2">Retrait effectué</h2>
                    <p class="text-ink/55 text-sm px-2" x-text="successMessage"></p>
                </div>

                <!-- ══════ ANNULÉ (3 échecs) ══════ -->
                <div x-show="step === 'cancelled'" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-8 mt-7 text-center">
                    <div class="pop w-20 h-20 mx-auto mb-4 rounded-full bg-red-50 flex items-center justify-center">
                        <span class="text-4xl">↩️</span>
                    </div>
                    <h2 class="text-xl font-extrabold mb-2">Transaction annulée</h2>
                    <p class="text-ink/55 text-sm px-2">
                        Le nombre maximal de tentatives (3) a été atteint.
                        L'argent a été <span class="font-bold text-ink">retourné à l'expéditeur</span>, qui a été informé.
                    </p>
                </div>

            </div>
        </template>

        <footer class="text-center mt-10 text-xs text-ink/40">
            🔒 Transfert protégé par FriPay — MTN · Moov · Celtiis
        </footer>
    </main>

    <script>
    /**
     * Web2 — page publique de retrait d'un QR « argent » pour un receveur
     * sans compte FriPay. Deux blocs :
     *   1. « Télécharger l'application » : liens stores (génériques tant que
     *      l'app n'est pas publiée) — une fois l'app installée et le compte
     *      créé avec ce numéro, l'argent est versé automatiquement.
     *   2. « Recevoir l'argent sur mon compte mobile » : numéro + code de
     *      vérification (POST /qr/external/{uuid}/claim). 3 échecs → annulation
     *      + remboursement automatique à l'expéditeur (informé côté serveur).
     */
    function claimApp(uuid, playStoreUrl, appStoreUrl) {
        return {
            uuid,
            playStoreUrl,
            appStoreUrl,
            apiBase: '/api/v1',
            loading: true,
            loaded: false,
            fatalError: null,
            qr: {},
            step: 'choice', // choice | stores | form | success | cancelled
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

            showStores() {
                this.step = 'stores';
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
                        this.successMessage = data.message || 'Votre argent est en cours de règlement vers votre compte mobile.';
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
                        NOT_CLAIMABLE: "Ce retrait n'est plus possible.",
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
