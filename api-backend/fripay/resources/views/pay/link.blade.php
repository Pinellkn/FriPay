<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>FriPay — Payer ce lien</title>
    <meta name="description" content="Payez via FriPay ou Mobile Money (MTN, Moov) le lien FriPay qui vous a été partagé.">
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
<body class="bg-ivory text-ink min-h-screen antialiased" x-data="payLinkApp('{{ $token }}', '{{ $playStoreUrl }}', '{{ $appStoreUrl }}', '{{ $deepLinkScheme }}')" x-init="init()">

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
                Paiement sécurisé
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
            Chargement du lien…
        </div>

        <!-- ===== FATAL ERROR (lien introuvable) ===== -->
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
                    <div class="text-[13px] text-ink/55 mb-1">
                        <span class="font-semibold text-ink/75" x-text="link.creator"></span> vous demande
                    </div>
                    <div class="text-4xl font-extrabold text-fern tracking-tight" x-text="formatAmount(link.amount, link.currency)"></div>
                    <div x-show="link.description" class="mt-3 text-sm text-ink/60 bg-fern-pale rounded-xl px-3 py-2.5" x-text="link.description"></div>

                    <div x-show="link.status === 'paid'" class="mt-4 inline-flex items-center gap-1.5 bg-fern-pale text-fern text-xs font-bold px-3.5 py-1.5 rounded-full">
                        ✅ Déjà payé
                    </div>
                    <div x-show="link.status === 'cancelled'" class="mt-4 inline-flex items-center gap-1.5 bg-red-50 text-danger text-xs font-bold px-3.5 py-1.5 rounded-full">
                        🚫 Lien annulé
                    </div>
                    <div x-show="link.expired && link.status === 'created'" class="mt-4 inline-flex items-center gap-1.5 bg-red-50 text-danger text-xs font-bold px-3.5 py-1.5 rounded-full">
                        ⏱ Expiré
                    </div>
                </div>

                <!-- ══════ CHOIX DU MOYEN DE PAIEMENT ══════ -->
                <div x-show="link.payable && step === 'choice'" x-cloak>
                    <p class="text-[13px] font-bold text-ink/70 mt-7 mb-3 px-1">Comment voulez-vous payer ?</p>

                    <div class="space-y-3">
                        <!-- Bloc 1 : Payer via FriPay -->
                        <button type="button" @click="step = 'fripay'"
                                class="choice-card w-full text-left bg-white rounded-2xl ring-1 ring-line p-4 flex items-center gap-4 shadow-sm">
                            <div class="w-12 h-12 shrink-0 rounded-2xl hero-glow flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="7" y="2.5" width="10" height="19" rx="2.5"/>
                                    <path d="M10.5 18.5h3" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-extrabold text-[15px]">Payer via FriPay</div>
                                <div class="text-xs text-ink/55 mt-0.5">Vous avez l'application ? Payez en deux taps.</div>
                            </div>
                            <svg class="w-5 h-5 text-ink/25 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>

                        <!-- Bloc 2 : Payer via mon compte mobile -->
                        <button type="button" @click="step = 'mobile'"
                                class="choice-card w-full text-left bg-white rounded-2xl ring-1 ring-line p-4 flex items-center gap-4 shadow-sm">
                            <div class="w-12 h-12 shrink-0 rounded-2xl bg-gold/15 ring-1 ring-gold/30 flex items-center justify-center">
                                <svg class="w-6 h-6 text-gold" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="2.5" y="6" width="19" height="12" rx="2.5"/>
                                    <path d="M6 10h4v4H6z" fill="currentColor" stroke="none" opacity=".85"/>
                                    <path d="M14.5 10.5h4M14.5 13.5h4" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-extrabold text-[15px]">Payer via mon compte mobile</div>
                                <div class="text-xs text-ink/55 mt-0.5 mt-1 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-[#F2C94C] inline-block"></span>
                                    <span class="w-2 h-2 rounded-full bg-[#3B5FDB] inline-block"></span>
                                    MTN · Moov — validation sur votre téléphone
                                </div>
                            </div>
                            <svg class="w-5 h-5 text-ink/25 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ══════ BLOC : PAYER VIA FRIPAY ══════ -->
                <div x-show="step === 'fripay'" x-cloak class="slide-up">
                    <div class="bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-5 mt-7 space-y-4">
                        <button type="button" @click="resetChoice()" class="text-ink/45 text-sm font-semibold flex items-center gap-1 hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg> Retour
                        </button>

                        <div>
                            <h2 class="font-extrabold text-lg">Payer via FriPay</h2>
                            <p class="text-sm text-ink/55 mt-1">Indiquez votre numéro : on vérifie si vous avez un compte FriPay.</p>
                        </div>

                        <div>
                            <label class="text-[13px] font-bold text-ink/70 mb-1.5 block">Votre numéro de téléphone</label>
                            <input type="tel" x-model="form.phone" @keydown.enter="checkFripayAccount()"
                                   placeholder="+229 01 XX XX XX XX"
                                   class="w-full bg-ivory border border-line rounded-xl px-4 py-3.5 focus:border-fern focus:ring-4 focus:ring-fern/10 focus:outline-none transition">
                        </div>

                        <div x-show="formError" x-cloak class="text-danger text-sm bg-red-50 border border-danger/20 rounded-xl px-3.5 py-2.5" x-text="formError"></div>

                        <button type="button" @click="checkFripayAccount()" :disabled="checkingAccount || !form.phone"
                                class="w-full bg-fern hover:bg-[#186B51] disabled:bg-ink/10 disabled:text-ink/35 py-3.5 rounded-xl font-bold text-[15px] text-white transition">
                            <span x-text="checkingAccount ? 'Vérification…' : 'Continuer'"></span>
                        </button>

                        <!-- Compte trouvé → ouvrir l'appli -->
                        <div x-show="accountCheck === 'found'" x-cloak class="slide-up space-y-3 pt-1">
                            <div class="flex items-start gap-2.5 bg-fern-pale rounded-xl px-4 py-3 text-[13px] text-fern-deep">
                                <span class="mt-0.5">🎉</span>
                                <span>Un compte FriPay existe avec ce numéro — ouvrez l'application pour valider le paiement.</span>
                            </div>
                            <a :href="deepLink + '://pay/' + token"
                               class="block w-full text-center hero-glow py-4 rounded-xl font-bold text-lg text-white shadow-lg shadow-fern/30">
                                Ouvrir l'application FriPay
                            </a>
                            <p class="text-[11.5px] text-ink/45 text-center px-2">Rien ne se passe ? Installez ou mettez à jour l'application ci-dessous.</p>
                        </div>

                        <!-- Pas de compte → stores -->
                        <div x-show="accountCheck === 'not_found'" x-cloak class="slide-up space-y-3 pt-1">
                            <div class="flex items-start gap-2.5 bg-gold/10 rounded-xl px-4 py-3 text-[13px] text-[#6b4d12]">
                                <span class="mt-0.5">📲</span>
                                <span>Aucun compte FriPay avec ce numéro. Téléchargez l'application pour créer le vôtre et payer.</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <a :href="playStoreUrl" target="_blank" rel="noopener"
                                   class="flex items-center justify-center gap-2 bg-ink text-white py-3.5 rounded-xl font-bold text-sm hover:bg-ink/90 transition">
                                    <svg class="w-4.5 h-4.5 w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 1.8L14.9 12 3.6 22.2c-.4-.3-.6-.8-.6-1.4V3.2c0-.6.2-1.1.6-1.4zm12.5 9l2.9-2.7 3.3 1.9c.9.5.9 1.5 0 2l-3.3 1.9-2.9-3.1zm-1.2-1.1L5.5 1.1c.3-.1.7-.1 1.1.1l11.2 6.4-2.9 2.1zM5.5 22.9l9.4-8.6 2.9 2.1-11.2 6.4c-.4.2-.8.2-1.1.1z"/></svg>
                                    Google Play
                                </a>
                                <a :href="appStoreUrl" target="_blank" rel="noopener"
                                   class="flex items-center justify-center gap-2 bg-ink text-white py-3.5 rounded-xl font-bold text-sm hover:bg-ink/90 transition">
                                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 12.54c-.03-2.89 2.36-4.27 2.47-4.34-1.35-1.97-3.44-2.24-4.18-2.27-1.78-.18-3.47 1.05-4.37 1.05-.9 0-2.29-1.02-3.77-1-1.94.03-3.72 1.13-4.72 2.86-2.01 3.49-.51 8.66 1.45 11.49.96 1.39 2.1 2.94 3.6 2.88 1.44-.06 1.99-.93 3.73-.93s2.23.93 3.76.9c1.55-.03 2.53-1.41 3.48-2.8 1.09-1.61 1.54-3.17 1.57-3.25-.03-.02-3-1.15-3.02-4.59zM14.16 4.06c.79-.96 1.33-2.29 1.18-3.62-1.14.05-2.53.76-3.35 1.72-.73.85-1.38 2.21-1.21 3.51 1.28.1 2.58-.65 3.38-1.61z"/></svg>
                                    App Store
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════ BLOC : PAYER VIA COMPTE MOBILE ══════ -->
                <div x-show="step === 'mobile'" x-cloak class="slide-up">
                    <div class="bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-5 mt-7 space-y-4">
                        <button type="button" @click="resetChoice()" class="text-ink/45 text-sm font-semibold flex items-center gap-1 hover:text-ink">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg> Retour
                        </button>

                        <div>
                            <h2 class="font-extrabold text-lg">Payer via mon compte mobile</h2>
                            <p class="text-sm text-ink/55 mt-1">Vous recevrez une demande de validation sur votre téléphone.</p>
                        </div>

                        <div>
                            <label class="text-[13px] font-bold text-ink/70 mb-2 block">Votre opérateur</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button type="button" @click="form.operator = 'MTN'"
                                        :class="form.operator === 'MTN' ? 'border-[#E0B33C] bg-[#F2C94C]/15' : 'border-line bg-ivory'"
                                        class="border-2 rounded-xl py-3.5 font-bold transition flex items-center justify-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-[#F2C94C]"></span> MTN
                                </button>
                                <button type="button" @click="form.operator = 'MOOV'"
                                        :class="form.operator === 'MOOV' ? 'border-[#3B5FDB] bg-[#3B5FDB]/10' : 'border-line bg-ivory'"
                                        class="border-2 rounded-xl py-3.5 font-bold transition flex items-center justify-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-[#3B5FDB]"></span> Moov
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="text-[13px] font-bold text-ink/70 mb-1.5 block">Votre numéro Mobile Money</label>
                            <input type="tel" x-model="form.phone" placeholder="+229 01 XX XX XX XX"
                                   class="w-full bg-ivory border border-line rounded-xl px-4 py-3.5 focus:border-fern focus:ring-4 focus:ring-fern/10 focus:outline-none transition">
                            <p class="text-xs text-ink/45 mt-1.5">
                                Un message de confirmation vous sera envoyé : validez avec votre code Mobile Money, le montant sera débité de votre compte vers le bénéficiaire.
                            </p>
                        </div>

                        <div x-show="formError" x-cloak class="text-danger text-sm bg-red-50 border border-danger/20 rounded-xl px-3.5 py-2.5" x-text="formError"></div>

                        <button type="button" @click="submitPay()" :disabled="submitting || !form.phone || !form.operator"
                                class="w-full bg-fern hover:bg-[#186B51] disabled:bg-ink/10 disabled:text-ink/35 py-4 rounded-xl font-bold text-lg text-white transition">
                            <span x-text="submitting ? 'Envoi…' : 'Payer ' + formatAmount(link.amount, link.currency)"></span>
                        </button>
                    </div>
                </div>

                <!-- ══════ EN ATTENTE DE VALIDATION ══════ -->
                <div x-show="step === 'waiting'" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-8 mt-7 text-center">
                    <svg class="animate-spin h-11 w-11 mx-auto mb-5 text-fern" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.2"/>
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity="0.8"/>
                    </svg>
                    <h2 class="text-lg font-extrabold mb-2">Validez sur votre téléphone</h2>
                    <p class="text-ink/55 text-sm px-2 mb-4">
                        Une demande de <span class="font-bold text-ink" x-text="formatAmount(link.amount, link.currency)"></span>
                        a été envoyée sur votre numéro. Composez votre code Mobile Money pour confirmer.
                    </p>
                    <div class="flex justify-center gap-1.5" aria-hidden="true">
                        <template x-for="i in 3" :key="i">
                            <span class="w-2 h-2 rounded-full bg-fern/70 animate-pulse" :style="`animation-delay:${i * 0.18}s`"></span>
                        </template>
                    </div>
                    <p class="text-xs text-ink/40 mt-4">Vérification automatique…</p>
                </div>

                <!-- ══════ SUCCÈS ══════ -->
                <div x-show="step === 'success'" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-8 mt-7 text-center">
                    <div class="pop w-20 h-20 mx-auto mb-4 rounded-full bg-fern-pale flex items-center justify-center">
                        <span class="text-4xl">✅</span>
                    </div>
                    <h2 class="text-xl font-extrabold mb-2">Paiement effectué</h2>
                    <p class="text-ink/55 text-sm px-2">
                        <span class="font-bold text-ink" x-text="formatAmount(link.amount, link.currency)"></span>
                        ont été envoyés à <span class="font-bold text-ink" x-text="link.creator"></span>. Merci !
                    </p>
                </div>

                <!-- ══════ ÉCHEC ══════ -->
                <div x-show="step === 'failed'" x-cloak class="slide-up bg-white rounded-3xl ring-1 ring-line shadow-lg shadow-fern-deep/5 p-8 mt-7 text-center">
                    <div class="pop w-20 h-20 mx-auto mb-4 rounded-full bg-red-50 flex items-center justify-center">
                        <span class="text-4xl">❌</span>
                    </div>
                    <h2 class="text-xl font-extrabold mb-2">Paiement non abouti</h2>
                    <p class="text-ink/55 text-sm px-2 mb-5">
                        Le paiement n'a pas été confirmé. Si vous avez été débité, l'argent sera restitué automatiquement.
                    </p>
                    <button type="button" @click="retry()"
                            class="bg-fern hover:bg-[#186B51] text-white px-8 py-3.5 rounded-xl font-bold transition">
                        Réessayer
                    </button>
                </div>

            </div>
        </template>

        <footer class="text-center mt-10 text-xs text-ink/40">
            🔒 Transaction protégée par FriPay — MTN · Moov
        </footer>
    </main>

    <script>
    /**
     * Web1 — page publique de paiement d'un FriPay Link.
     * Deux blocs :
     *   1. « Payer via FriPay » : vérifie si le numéro possède un compte
     *      (GET /api/v1/public/phone-check) → ouvre l'appli (deep link) ou
     *      renvoie vers les stores (liens génériques tant que l'app n'est
     *      pas publiée).
     *   2. « Payer via mon compte mobile » : opérateur + numéro → push de
     *      validation (POST /payment-links/{token}/pay) → polling /status.
     */
    function payLinkApp(token, playStoreUrl, appStoreUrl, deepLinkScheme) {
        return {
            token,
            playStoreUrl,
            appStoreUrl,
            deepLink: deepLinkScheme,
            apiBase: '/api/v1',
            loading: true,
            loaded: false,
            fatalError: null,
            link: {},
            step: 'choice', // choice | fripay | mobile | waiting | success | failed
            form: { operator: '', phone: '' },
            formError: null,
            checkingAccount: false,
            accountCheck: null, // null | found | not_found
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

            async checkFripayAccount() {
                this.formError = null;
                if (!this.form.phone.trim()) return;
                this.checkingAccount = true;
                this.accountCheck = null;
                try {
                    const res = await fetch(`${this.apiBase}/public/phone-check?phone=${encodeURIComponent(this.form.phone.trim())}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        this.formError = data.message || 'Vérification impossible, réessayez.';
                        return;
                    }
                    this.accountCheck = data.has_fripay_account ? 'found' : 'not_found';
                } catch (e) {
                    this.formError = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
                } finally {
                    this.checkingAccount = false;
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

            resetChoice() {
                this.step = 'choice';
                this.formError = null;
                this.accountCheck = null;
            },

            retry() {
                clearInterval(this.pollTimer);
                this.step = this.form.operator ? 'mobile' : 'choice';
                this.formError = null;
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
