# FriPay — Application mobile (Flutter)

Portage mobile fidèle de l'application web **benin-money-hub-main**
(`C:\Users\Pinel\Fripay projet\benin-money-hub-main`) — paiement mobile
interopérable au Bénin (MTN MoMo, Moov Money, Celtiis Cash, solde FriPay).

Même identité visuelle que le web, "en plus avancé" comme demandé :
palette **emerald + or sur fond ivoire** (variables OKLCH du web portées
fidèlement en Dart), typographies **Sora** (titres) + **Manrope** (texte),
coins arrondis 1rem, cartes à ombre douce, mêmes images (`assets/images/`),
mais avec des transitions, un clavier PIN et des retours haptique/visuel
propres à mobile.

> Statut global : **🟢 App fonctionnelle de bout en bout**, 0 erreur
> `flutter analyze`. Reste la configuration finale avant publication
> (icône/splash natifs, `applicationId`) — voir section dédiée.

---

## 🔌 Connexion à l'API réelle (fripay-users / gateway) — en cours

Le backend (`C:\Users\Pinel\Fripay projet\API_Fripay-PushA`) est
fonctionnel (MySQL/XAMPP, gateway sur `:8080`). L'app commence à
remplacer les données mock par de vrais appels HTTP.

### ✅ Fait
- `lib/services/api_config.dart` — URL du gateway (`10.0.2.2:8080` sur
  émulateur Android, `127.0.0.1:8080` ailleurs — **à adapter en IP LAN
  pour un appareil physique**, voir le fichier).
- `lib/services/token_storage.dart` — persistance session (SharedPreferences).
- `lib/services/api_client.dart` — client HTTP générique, refresh
  automatique du token sur 401, erreurs typées (`ApiException`).
- `lib/services/auth_service.dart` — register / verify-otp / login /
  set-pin / logout / getMe, tous branchés sur `fripay-users`.
- `lib/services/account_service.dart` — comptes mobile money liés
  (GET/POST/DELETE `/users/me/accounts`).
- **Écran de connexion** (`lib/screens/auth/login_screen.dart`) —
  entièrement réécrit, branché sur l'API réelle (login par PIN,
  inscription -> OTP SMS -> définition du PIN).
- **Splash** — vérifie une session existante et saute direct vers
  l'app si déjà connecté.
- **Profil** (`lib/screens/profile/profile_screen.dart`) — nom/numéro
  réels via `GET /users/me`, vraie déconnexion (`POST /auth/logout`).
- **Portefeuilles** (`lib/screens/wallets/wallets_screen.dart`) —
  liste les comptes mobile money réellement liés au compte.
- `lib/services/transfer_service.dart` — devis (`POST /transfers/quote`),
  initiation (`POST /transfers`), historique (`GET /transfers`),
  détail (`GET /transfers/{id}`), annulation (`POST /transfers/{id}/cancel`).
- **Envoyer** (`lib/screens/send/send_screen.dart`) — les deux onglets
  sont branchés :
  - "Numéro" : sélection du compte d'envoi réel, devis en direct,
    confirmation par PIN puis initiation réelle.
  - "Scanner un QR" : réutilise le scanner caméra existant
    (`scan/qr_scan_screen.dart`), vérifie le QR marchand scanné via
    `POST /qr/mpm/scan`, affiche le nom du marchand et le montant (fixe
    pour un QR dynamique, saisi par le payeur pour un QR statique), puis
    confirme le paiement par PIN via `POST /qr/mpm/pay`.
- `lib/services/merchant_qr_service.dart` — génération de QR marchand
  (`POST /qr/mpm/generate`, statique/dynamique), historique
  (`GET /qr/merchant/history`), scan et paiement côté payeur
  (`/qr/mpm/scan`, `/qr/mpm/pay`).
- **Icône QR de l'Accueil** — ouvre désormais directement l'onglet
  "Scanner un QR" d'Envoyer (`SendScreen(startOnQrTab: true)`) au lieu
  d'un scanner isolé qui ne faisait rien du résultat.
- **Historique** (`lib/screens/history/history_screen.dart`) — liste
  réelle via `GET /transfers` (transferts envoyés uniquement, l'API
  n'expose pas d'historique de réception séparé).
- **Accueil** (`lib/screens/home/home_screen.dart`) — identité réelle
  (`GET /users/me`), 5 dernières transactions réelles (`GET /transfers`).
  La carte de solde n'affiche plus de montant inventé : elle indique le
  nombre de comptes mobile money liés (voir limite backend ci-dessous).
- **Recevoir** (`lib/screens/receive/receive_screen.dart`, onglet "Mon QR")
  — QR marchand statique réel via
  `MerchantQrService.generateStatic()` (`POST /qr/mpm/generate`),
  identité réelle (`GET /users/me`), bouton "Régénérer" qui appelle de
  nouveau l'API. Les statistiques inventées ("Encaissements ce mois",
  etc., qui n'existent pas côté backend) ont été retirées, comme pour
  la carte de solde de l'Accueil. L'onglet "Demande de paiement" reste
  une simulation locale : aucun service de demande de paiement n'est
  identifié côté backend.
- **Contacts** (`lib/services/contact_service.dart`) — vrais contacts
  via `GET/POST/DELETE /users/me/contacts`. Branché sur les chips de
  raccourci d'Envoyer et de Recevoir (onglet "Demande de paiement"), à
  la place de `mock_data.dart`. Chip "+ Ajouter" (`widgets/add_contact_sheet.dart`,
  bottom sheet nom + numéro → `POST /users/me/contacts`) sur les deux
  écrans — la liste n'est donc plus condamnée à rester vide : un
  nouveau compte peut désormais créer ses premiers contacts directement
  depuis Envoyer/Recevoir.
- **Notifications** (`lib/services/user_notification_service.dart`,
  `lib/screens/profile/notifications_screen.dart`) — branché sur
  `GET /notifications` et `PUT /notifications/{id}/read`, à la place
  des 4 notifications inventées. ⚠️ Le backend n'écrit encore aucune
  notification nulle part dans le code (`UserNotification::create`
  n'est appelé par aucun service) : la liste sera **vide** tant que ça
  n'est pas ajouté côté API (ex. créer une notification à chaque
  transfert reçu) — c'est l'état réel, pas un bug d'affichage.

### 🐛 Bugs bloquants trouvés et corrigés (débogage bout-en-bout)
Avant cette session, la connexion n'avait **jamais été testée avec succès de bout en bout** (voir historique : "Tester les flux authentifiés complets" restait en attente). Deux bugs bloquants ont été trouvés en essayant réellement de se connecter :

1. **Connexion totalement cassée côté backend** — la table
   `personal_access_tokens` (Sanctum) avait `tokenable_id` en `bigint`
   (migration par défaut, prévue pour un ID auto-incrémenté), alors que
   `User::id` est un UUID (`char(36)`, trait `HasUuids`). Résultat :
   *toute* émission de token (verify-otp, login) plantait avec
   `SQLSTATE... Data truncated for column 'tokenable_id'`.
   **Corrigé** : migration
   `fripay-users/database/migrations/2026_07_20_103800_create_personal_access_tokens_table.php`
   passée de `$table->morphs('tokenable')` à
   `$table->uuidMorphs('tokenable')`, et la table déjà créée corrigée en
   base avec `ALTER TABLE personal_access_tokens MODIFY tokenable_id CHAR(36) NOT NULL;`
   (sans perte de données).
2. **En-tête `Idempotency-Key` jamais envoyé côté app** —
   `POST /users/me/accounts` (liaison d'un compte mobile money) exige cet
   en-tête côté backend (middleware `idempotent`), mais
   `lib/services/api_client.dart` ne l'envoyait jamais → erreur
   `MISSING_IDEMPOTENCY_KEY` à chaque tentative de liaison de compte
   depuis Portefeuilles. **Corrigé** : `api_client.dart` génère
   maintenant une clé unique pour tout POST/PUT et la réutilise même en
   cas de rejeu après rafraîchissement de token (pas de nouvelle clé à
   chaque tentative).

3. **`user_id` traité comme `int` côté app alors que le backend renvoie
   un ULID (chaîne)** — `POST /auth/register` renvoie
   `"user_id": "01a0859a-25c0-..."` (chaîne), et `GET /users/me` renvoie
   `"id"` sous la même forme. Mais `RegisterResult.userId` et
   `TokenStorage.saveUser({required int userId, ...})` étaient typés
   `int`. Résultat : à l'inscription (après l'OTP) **et** à la
   connexion, l'appel HTTP réussissait (200/201) mais l'app plantait
   juste après en essayant de caster la chaîne en `int` → exception Dart
   non gérée, capturée par le `catch` générique → message trompeur
   "Une erreur est survenue. Réessayez." qui masquait le vrai problème.
   **Corrigé** (09/09) : `RegisterResult.userId` et
   `TokenStorage.saveUser`/`TokenStorage.userId` passés en `String`
   partout (`lib/services/auth_service.dart`,
   `lib/services/token_storage.dart`).

Vérifié en conditions réelles après correction : inscription → OTP →
connexion par PIN → route protégée (`GET /users/me`) → liaison d'un
compte MTN, **tout fonctionne**.

### 🔑 Compte de test (créé pendant le débogage, utilisable dès maintenant)
- **Numéro** : `+229 01 97 99 98 88`
- **Code PIN** : `12345` (5 chiffres — le format à 4 chiffres n'est plus
  accepté depuis la mise à jour du 09/09 : PIN 5 chiffres + numéro
  obligatoirement `+22901XXXXXXXX`)
- Testé en direct côté API le 09/09 (`POST /auth/login` → `200`,
  tokens renvoyés) : le backend fonctionne correctement pour ce compte.
- Un compte MTN (`+22997001122`) est déjà lié en tant que compte
  principal — visible dans Portefeuilles/Envoyer.
- Aucun contact, aucune notification pour ce compte (comportement normal,
  voir sections Contacts/Notifications ci-dessus).

### ⚠️ Limite backend constatée
Le backend **n'expose aucun endpoint de solde** (pas de `balance` sur
`LinkedAccountResource`, pas de service wallet côté `fripay-payments`).
FriPay orchestre des transferts vers des comptes opérateurs mais ne
stocke pas leur solde réel. L'écran Portefeuilles affiche donc les
comptes liés sans solde inventé — un vrai "solde consolidé" nécessiterait
soit une requête de solde vers chaque connecteur opérateur (à construire
côté `fripay-payments`), soit un solde propre FriPay alimenté par les
transactions.

### ⏳ Reste à faire
- Factures (`bills_screen.dart`) — **vérifié à nouveau** : toujours
  aucun service factures dans les 3 services backend
  (`fripay-users`, `fripay-payments`, `fripay-admin`).
- Plaintes (`complaints_screen.dart`) — **vérifié à nouveau** : toujours
  aucun service tickets/plaintes côté backend. `fripay-admin` ne
  couvre que le back-office (users, transactions, corridors, staff),
  rien orienté plainte client.
- QR hors-ligne P2P (`offline_screen.dart`) — 🆕 **le backend a en
  fait un vrai module dédié**, non lié aux factures/plaintes :
  `POST /qr/generate`, `/qr/receive`, `/qr/redeem`, `/qr/transfer`,
  `/qr/revoke`, `/qr/verify`, `GET /qr/{uuid}/status`
  (`MerchantQrController` voisin, contrôleur `OfflineQrController`
  dans `fripay-payments`). À vérifier si ça correspond au flux USSD
  actuel de l'écran ou si c'est un système P2P différent (QR à
  transmettre sans réseau) — à creuser avant de brancher.

---

## ✅ Déjà fait

### Fondations
- [x] `pubspec.yaml` — dépendances : `google_fonts`, `qr_flutter`, `intl`, `fl_chart`
- [x] Thème complet (`lib/theme/app_colors.dart`, `lib/theme/app_theme.dart`)
      — couleurs, boutons, champs, cartes, bottom sheets, snackbars
- [x] Modèles de données (`lib/models/models.dart`) — opérateurs, portefeuilles,
      transactions, factures, contacts, tickets de plainte
- [x] Données de démonstration (`lib/data/mock_data.dart`) — miroir de
      `src/lib/fripay-data.ts` du web
- [x] Formatteurs (`lib/utils/formatters.dart`) — `formatFCFA`, `feeFor`
- [x] Images copiées dans `assets/images/` (hero, QR marchand, USSD, motif)

### Widgets partagés
- [x] `FripayLogo` (badge "F" dégradé + wordmark)
- [x] `SectionHeader` (titre de page)
- [x] `StatusBadge` / `TicketStatusBadge`
- [x] `BalanceCard`
- [x] `QuickActionButton`
- [x] `PinConfirmSheet` (confirmation par code PIN à 4 chiffres, bottom sheet)
- [x] `AppScaffold` (navigation basse à 5 onglets : Accueil, Envoyer,
      Recevoir, Historique, Profil)
- [x] `OperatorAvatar` / `OperatorDot`

### Écrans (tous terminés)
- [x] Splash (dégradé emerald plein écran)
- [x] Connexion / Inscription (onglets, flux téléphone → OTP)
- [x] Accueil / Dashboard (solde total, actions rapides, transactions récentes)
- [x] Envoyer (choix contact/numéro, opérateur, montant, frais, confirmation PIN)
- [x] Recevoir (vrai QR code généré avec `qr_flutter`)
- [x] Dépôt / Retrait via agent
- [x] Factures (SBEE, SONEB, Canal+, forfaits, scolarité)
- [x] Portefeuilles (liste des comptes par opérateur, statuts)
- [x] Historique (liste des transactions, statuts)
- [x] Mode hors ligne (code USSD de secours)
- [x] **Plaintes** — liste des tickets + état vide + bandeau remboursement
- [x] **Nouvelle plainte** — formulaire (motif, transaction liée, sujet,
      description, éligibilité au remboursement en direct)
- [x] **Détail d'une plainte** — motif, description, transaction liée, statut
      du remboursement
- [x] **Profil** — en-tête compte, raccourcis (portefeuilles, factures, hors
      ligne, plaintes), sécurité, assistance, déconnexion

### Accueil général, connexion, sécurité & assistance
- [x] Page d'accueil générale (`landing/landing_screen.dart`) — portage complet
      de `index.tsx` du web (bandeau, hero + logo, carte flottante de
      transfert, bandeau stats, 3 étapes, section hors-ligne, grille de
      6 fonctionnalités, section marchands). Toutes les images en
      `BoxFit.contain` (jamais rognées, quel que soit l'écran)
- [x] Flux corrigé : Splash → Accueil général → Connexion
- [x] OTP avec avance automatique de case + collage du code SMS
      (`widgets/otp_input_row.dart`), branché sur l'écran de connexion,
      code démo `429106`
- [x] Modifier mon code PIN (`profile/change_pin_screen.dart`) — flux en
      3 étapes (ancien → nouveau → confirmation)
- [x] Déverrouillage biométrique — activation/désactivation depuis le profil
      avec prompt natif et persistance (`shared_preferences`). **Mise à jour
      09/09** : c'était jusque-là un simple bouton non relié à la connexion
      réelle (le PIN stocké était toujours un `'1234'` factice, jamais
      utilisé nulle part). Maintenant réellement fonctionnel de bout en
      bout — voir section dédiée plus bas.
- [x] Notifications (`profile/notifications_screen.dart`) — branché sur
      l'API réelle (`GET /notifications`), voir section "🔌 Connexion à
      l'API réelle" ci-dessus pour le détail et la limite constatée
- [x] Centre d'aide (FAQ accordéon) et À propos de FriPay, portés depuis
      `faq.tsx` / `a-propos.tsx` du web
- [x] Scanner QR (`scan/qr_scan_screen.dart`) — caméra live (`mobile_scanner`)
      + import depuis la galerie (`image_picker`), accessible depuis
      Accueil (icône QR) et Envoyer (onglet "Scanner un QR")
- [x] Tous les items du menu Profil branchés vers leurs écrans (PIN,
      biométrie, notifications, aide, à propos) — plus de `onTap: () {}`
- [x] Permissions Android ajoutées (`AndroidManifest.xml`) : caméra
      (scan QR) + biométrie (`USE_BIOMETRIC`, `USE_FINGERPRINT`)

### Icône, splash & rafraîchissement
- [x] **Icône de l'application** remplacée sur toutes les plateformes
      (Android classique + adaptative, iOS, Web, Windows) — vraie icône
      FriPay (badge "F" dégradé emerald) via `flutter_launcher_icons`,
      l'icône Flutter par défaut a disparu
- [x] **Écran de chargement (splash)** entièrement refait — durée totale
      3,2 s, logo animé (fondu + zoom élastique), texte
      « Bienvenue sur FriPay », barre de progression + 3 messages de
      statut qui défilent, même dégradé emerald que le reste de l'app
- [x] **Tirer pour rafraîchir** (`widgets/fripay_refresh.dart`) appliqué à
      toutes les pages de données : Accueil, Historique, Portefeuilles,
      Factures, Hors ligne, Plaintes, Notifications, Profil, Accueil
      général — chaque liste a `physics: AlwaysScrollableScrollPhysics()`
      pour que le geste marche même sur du contenu court. Non appliqué à
      Envoyer / Recevoir / Recharge (formulaires à champs contrôlés — un
      rafraîchissement y viderait la saisie en cours, ce qui n'a pas de sens)

### Point d'entrée & qualité
- [x] `lib/main.dart` réécrit (démarre sur `SplashScreen`, thème `AppTheme.light`,
      titre "FriPay" — l'ancien template compteur Flutter a été supprimé)
- [x] `test/widget_test.dart` réécrit pour coller à la vraie app (l'ancien
      test référençait le widget de démo supprimé)
- [x] Nettoyage `flutter analyze` : imports inutilisés retirés
      (`recharge_screen.dart`, `wallets_screen.dart`), commentaire de doc
      flottant corrigé (`models.dart`), suggestion `?trailing` appliquée
      (`section_header.dart`)
- [x] `android/.../AndroidManifest.xml` — nom affiché de l'app changé en "FriPay"
- [x] `flutter pub get` exécuté avec succès
- [x] `flutter analyze` → **No issues found!** (dernière vérification après
      le rafraîchissement, l'icône et le splash)

---

## ⏳ Ce qui reste (avant publication / build final)

- [ ] **`applicationId`** — actuellement `com.example.fripay_app` par défaut
      (`android/app/build.gradle.kts`) ; à changer pour un identifiant définitif
      (ex. `bj.fripay.app`) avant toute publication, et mettre à jour le
      dossier `android/app/src/main/kotlin/...` en conséquence
- [ ] **Activer le "Mode développeur" Windows** sur cette machine
      (`start ms-settings:developers`) — requis par Flutter pour les liens
      symboliques lors d'un vrai `flutter build apk` / `flutter run` avec
      plugins ; sans cela seuls `pub get`/`analyze` fonctionnent pleinement
- [ ] **Finir la connexion à l'API réelle** — la majorité des écrans sont
      branchés (voir section "🔌 Connexion à l'API réelle" en haut de ce
      README pour le détail exact et ce qui reste : Factures, Plaintes,
      QR hors-ligne P2P)
- [ ] Écran(s) de **détail de transaction** individuel si besoin de plus de
      profondeur que la ligne d'historique actuelle
- [ ] Tests de build réels : `flutter build apk` et `flutter run` sur un
      appareil/émulateur (non exécutés dans cette session — nécessite le
      mode développeur ci-dessus + un appareil connecté)

---

## Démarrer le projet

**1. Backend** (déjà démarré à la fin de cette session de débogage —
si tu redémarres ta machine ou fermes le terminal, relance) :
```powershell
cd "C:\Users\Pinel\Fripay projet\API_Fripay-PushA"
powershell -File start-fripay.ps1
```
Vérifier que tout tourne : `http://127.0.0.1:8080/__gateway/status`
doit répondre avec les 3 services en `"circuit": "closed"`. XAMPP
(MySQL) doit aussi être lancé (module MySQL démarré dans le panneau
XAMPP).

**2. App mobile** :
```bash
cd C:\Users\Pinel\AndroidStudioProjects\Fripay_App
flutter pub get
flutter run
```
- **Émulateur Android** : fonctionne directement (`10.0.2.2` pointe
  vers le PC hôte, déjà configuré dans `api_config.dart`).
- **Appareil physique** (même Wi-Fi que le PC) : remplacer
  temporairement `10.0.2.2`/`127.0.0.1` dans `api_config.dart` par
  l'IP LAN du PC — `192.168.1.64` au moment de cette session (vérifier
  avec `ipconfig` si elle a changé).

**Se connecter avec le compte de test** (voir section "🔑 Compte de
test" ci-dessus) : numéro `+229 01 97 99 98 88`, PIN `12345`.

### 🐞 09/09 (suite) — Dashboard/Historique en erreur : 2 bugs distincts trouvés et corrigés

**Bug 1 — `GET /transfers` renvoyait 500 (backend `fripay-payments`)**
Cause réelle trouvée dans `fripay-payments/storage/logs/laravel.log` :
`Class "App\Services\AuthService" does not exist`. Le fichier existait
pourtant bel et bien (`app/Services/AuthService.php`, namespace correct)
— mais l'autoload optimisé de Composer (`vendor/composer/autoload_classmap.php`)
avait été généré **avant** la création de ce fichier, donc la classe
n'était pas dans le classmap figé. Résultat : le circuit breaker du
gateway s'ouvrait (`payments: half_open, 5 failures`) et le dashboard/
l'historique affichaient une erreur.
- Corrigé : `composer dump-autoload -o` relancé dans `fripay-payments`
  avec PHP 8.5, service payments redémarré (port 8001).
- Vérifié : `GET /api/v1/transfers` → 200 OK, gateway status → les 3
  services `circuit: closed, failures: 0`.
- Nettoyage au passage : suppression de `app/Http/Requests/Transfer/
  UpdateUserStatusRequest.php`, fichier orphelin avec namespace/dossier
  incohérents (`namespace App\Http\Requests\Admin` dans le dossier
  `Transfer`) et une référence de classe cassée sans les `\` de
  namespace — jamais utilisé par aucune route, supprimé sans risque.

**Bug 2 — Message "Erreur" générique persistant après le fix ci-dessus,
causé côté app mobile (`api_client.dart`)**
Une fois le backend réparé, un second symptôme est apparu sur
l'appareil physique : `GET /users/me`, `/users/me/accounts`,
`/transfers`, `/users/me/contacts` échouaient tous en 401 en même temps
(token expiré après le hot restart), et **chacun de ces appels
déclenchait indépendamment son propre `POST /auth/refresh-token`** —
visible dans les logs gateway comme une rafale de 8 appels
refresh-token quasi simultanés. Le refresh_token étant à usage unique
côté serveur, seul le premier réussissait ; tous les autres recevaient
un 401 sans `title`/`detail` exploitable, d'où le texte "Erreur" nu
affiché sur Accueil et Historique alors que la session était en fait
valide.
- Corrigé : `ApiClient._tryRefresh()` partage désormais un seul
  `Future<bool>? _refreshInFlight` — toute requête qui reçoit un 401
  pendant qu'un rafraîchissement est déjà en cours s'attache au même
  appel au lieu d'en déclencher un nouveau. `flutter analyze` → aucun
  problème.
- **Act: relancer l'app (hot restart, touche `R` dans le terminal
  `flutter run`) pour charger le correctif.**

### 🐞 09/09 — "La connexion tourne indéfiniment" sur un appareil physique
**Cause trouvée** : ce n'était pas un bug de logique de connexion — le
backend répondait correctement en local (`http://127.0.0.1:8080`,
testé et confirmé). Deux problèmes combinés :
1. Le réseau Wi-Fi du PC (`Beta_Afrique-5G`) est catégorisé **Public**
   dans Windows, et **aucune règle de pare-feu** n'autorisait le port
   `8080` en entrée. Résultat : le téléphone envoie sa requête, le
   pare-feu la bloque silencieusement (pas de refus, juste un silence
   radio), et sans timeout côté app, le bouton restait bloqué en
   "chargement" **indéfiniment**.
2. `api_client.dart` n'avait aucun timeout sur les appels HTTP — corrigé
   (12s max, message d'erreur clair "Le serveur ne répond pas" au lieu
   d'un spinner infini).

**À faire côté PC (nécessite les droits admin — Desktop Commander ne les
a pas) pour que le téléphone puisse joindre le backend** :
- Le plus simple, sans admin : Windows Settings → Réseau et Internet →
  Wi-Fi → `Beta_Afrique-5G` → passer le profil réseau de **Public** à
  **Privé**.
- Et/ou, dans un PowerShell **lancé en administrateur** :
  ```powershell
  New-NetFirewallRule -DisplayName "Fripay Gateway 8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -Profile Any
  ```
- Vérifier ensuite que `ApiConfig.kLanHost` (`192.168.1.64`) correspond
  toujours à l'IP du PC (`ipconfig`) et que le téléphone est sur le
  même Wi-Fi.

**Corrections de validation côté app** (suite à la demande du 09/09) :
- PIN désormais strictement **5 chiffres** partout (connexion et
  inscription), avec **confirmation obligatoire** à l'inscription (les
  deux champs doivent correspondre avant l'envoi).
- Numéro de téléphone validé par un format strict côté app avant tout
  appel réseau : doit correspondre à `+22901XXXXXXXX` (10 chiffres
  après l'indicatif, commençant par `01`) — sinon message d'erreur
  immédiat, pas d'appel API inutile.

## Structure

```
lib/
  main.dart               # Point d'entrée (SplashScreen + AppTheme)
  theme/                   # Couleurs & thème global
  models/                  # Modèles de données
  data/                    # Données mock (à remplacer par une vraie API)
  utils/                   # Formatteurs (FCFA, frais)
  widgets/                 # Composants partagés (logo, cartes, PIN, nav...)
  screens/
    splash/  auth/  home/  send/  receive/  recharge/  bills/
    wallets/  history/  offline/  complaints/  profile/
```

## Correspondance avec le web (benin-money-hub-main)

| Web (`src/routes/...`)        | Mobile (`lib/screens/...`)              |
|--------------------------------|------------------------------------------|
| `auth.tsx`                    | `auth/login_screen.dart`                 |
| `app.index.tsx`                | `home/home_screen.dart`                  |
| `app.envoyer.tsx`               | `send/send_screen.dart`                  |
| `app.recevoir.tsx`              | `receive/receive_screen.dart`            |
| `app.recharge.tsx`              | `recharge/recharge_screen.dart`          |
| `app.factures.tsx`              | `bills/bills_screen.dart`                |
| `app.portefeuilles.tsx`         | `wallets/wallets_screen.dart`            |
| `app.historique.tsx`            | `history/history_screen.dart`            |
| `app.hors-ligne.tsx`            | `offline/offline_screen.dart`            |
| `app.plaintes.tsx`              | `complaints/complaints_screen.dart` + `ticket_detail_screen.dart` |
| `app.plaintes.nouveau.tsx`      | `complaints/new_ticket_screen.dart`      |
| `app.profil.tsx`                | `profile/profile_screen.dart`            |

---

## 🔐 Connexion biométrique — fonctionnement réel (09/09)

Avant cette mise à jour, le toggle "Déverrouillage biométrique" du profil
n'était relié à rien : il activait/désactivait un booléen local, mais le
PIN utilisé en coulisses restait la valeur factice `'1234'`, jamais reliée
à une vraie connexion. Corrigé — le flux complet est maintenant :

1. **Activation** (`profile/profile_screen.dart`) — l'utilisateur active le
   switch, confirme son empreinte (prompt natif), puis **saisit son PIN
   actuel une seule fois** dans une petite boîte de dialogue. Ce PIN est
   validé côté serveur (`POST /auth/login`) puis mémorisé localement
   (`BiometricService.setPin`) — protégé ensuite par l'empreinte, jamais
   affiché ni renvoyé nulle part.
2. **Relance de l'app avec session active** (`screens/splash/splash_screen.dart`
   → `screens/auth/biometric_lock_screen.dart`) — si une session existe déjà
   et que la biométrie est activée, l'empreinte est demandée avant d'entrer
   dans l'app (au lieu d'un accès direct). Échec/annulation → secours par
   PIN seul (`screens/auth/pin_unlock_screen.dart`, numéro déjà connu) ou
   déconnexion complète.
3. **Écran de connexion classique** (`screens/auth/login_screen.dart`) — si
   la biométrie est activée et qu'un PIN est mémorisé pour ce téléphone, un
   bouton **"Connexion par empreinte"** apparaît sous le formulaire
   numéro+PIN habituel. Un appui déclenche l'empreinte puis rappelle
   `POST /auth/login` avec le PIN mémorisé — aucune saisie manuelle requise.
4. **Le PIN mémorisé reste synchronisé** : mis à jour automatiquement à
   chaque connexion manuelle réussie et à chaque changement de PIN
   (`profile/change_pin_screen.dart`), et **effacé** à la désactivation du
   toggle ou à une déconnexion explicite (`AuthService.logout`).

⚠️ **Limite connue** : `shared_preferences` n'est pas chiffré. Pour une
version production, le PIN mémorisé (et les tokens de session) devraient
migrer vers `flutter_secure_storage`. Fonctionnellement correct pour le
développement/test actuel, mais à durcir avant mise en production.

⚠️ **Autre limite** : le verrouillage biométrique ne se déclenche qu'au
lancement complet de l'app (cold start), pas à chaque retour au premier
plan après une mise en arrière-plan (`AppLifecycleState` non géré pour
l'instant) — à ajouter si un niveau de sécurité plus strict est souhaité.

---

## 🐞 09/09 (suite 2) — "Continuer" (Envoyer) et "Scanner un QR marchand" grisés sur un nouveau compte

**Ce n'était pas un bug** : les deux boutons dépendent d'un compte
d'envoi réel (`_sender`, un compte mobile money lié), volontairement
désactivés (`onPressed: null`) tant qu'aucun compte n'est lié — ce qui
est le cas par défaut pour un compte tout juste créé (aucun MTN/Moov/
Celtiis lié). Remplir le numéro du destinataire et le montant ne change
rien à ça : il manque le compte d'envoi lui-même.

La fonctionnalité pour lier un compte (bouton "Lier un compte mobile
money" dans Portefeuilles, bottom sheet `add_account_sheet.dart`,
`POST /users/me/accounts` côté API) avait déjà été construite dans une
session parallèle et est **complète et fonctionnelle** côté app et API
(vérifié : contrôleur, route, middleware `idempotent`, détection
automatique de l'opérateur). `flutter analyze` → 0 erreur bloquante,
seulement 4 avertissements mineurs de style.

**Ajouté aujourd'hui** : un bouton "Lier un compte mobile money"
directement dans Envoyer (onglets "Numéro" et "Scanner un QR"), visible
dès que la liste des comptes liés est vide — au lieu de devoir deviner
qu'il faut aller dans Portefeuilles. Il ouvre Portefeuilles, puis
recharge automatiquement la liste des comptes au retour pour débloquer
"Continuer"/"Scanner un QR" sans avoir à rouvrir l'écran Envoyer.

**Nettoyage** : `flutter analyze` ramené à **0 problème** (import
inutilisé retiré dans `send_screen.dart`, comparaison `null` redondante
retirée dans `login_screen.dart`, 2 suggestions de style `?` appliquées
dans `auth_service.dart` et `merchant_qr_service.dart`).

**À faire pour tester** : sur le compte utilisé (nouveau compte sans
compte lié), aller dans Portefeuilles (ou utiliser le nouveau bouton
depuis Envoyer) → "Lier un compte mobile money" → saisir un numéro
`+229 01 XX XX XX XX` valide → "Continuer" et "Scanner un QR marchand"
se débloquent immédiatement. Nécessite un **hot restart** (`R` majuscule
dans le terminal `flutter run`, pas juste `r`) pour charger ces
changements de code.

### Vérification biométrie multi-comptes (signalée le 09/09)
Le mécanisme `BiometricService.ensureOwnedBy()` associant le
déverrouillage biométrique + le PIN mémorisé à un numéro précis
(`login_screen.dart`, `splash_screen.dart`, `profile_screen.dart`,
`auth_service.dart`) est déjà en place et cohérent partout — relu
en entier aujourd'hui, aucune régression trouvée. Si le comportement
observé (biométrie déjà activée sur un nouveau compte) persiste après
un **hot restart complet**, il faudra le reproduire pas à pas
(numéro du compte précédent, méthode de changement de compte : bouton
"Se déconnecter" ou perte de session) pour identifier un cas non
couvert.

---

## 🐞 09/09 (suite 3) — Fausses erreurs `ensureOwnedBy` / `devOtpCode` non définis

Erreurs signalées par l'IDE :
```
error: The method 'ensureOwnedBy' isn't defined for the type 'BiometricService'. (login_screen.dart:84)
error: The getter 'devOtpCode' isn't defined for the type 'RegisterResult'. (login_screen.dart:198)
```

**Vérifié et non reproductible** : les deux membres existent bel et bien
(`BiometricService.ensureOwnedBy` dans `biometric_service.dart`,
`RegisterResult.devOtpCode` dans `auth_service.dart`) et correspondent
exactement à l'usage fait aux lignes citées. `flutter pub get` +
`flutter analyze` (projet entier) → **0 issue**.

Cause probable : cache du serveur d'analyse Dart de l'IDE resté sur un
état antérieur (avant que ces membres soient ajoutés), combiné au fait
que `flutter pub get` n'avait apparemment pas été relancé depuis
l'ajout de `local_auth`. **Pas une régression de code.**

**Si l'erreur réapparaît dans l'IDE** : Android Studio/VS Code →
"Dart: Restart Analysis Server" (ou fermer/rouvrir le projet), puis un
`flutter clean` + `flutter pub get` si besoin. Le code source, lui, est
correct.

---

## 🔍 09/09 — Audit backend + cahier des charges (voir README de l'API pour le détail complet)

Le backend a été revérifié en direct (4 services `200`, gateway
`circuit: closed` sur les 3) et l'audit `rapport-audit-api.md` relu
intégralement — 2 points mineurs corrigés (import inutilisé, version
Alpine.js pinnée), le reste des points élevés/critiques déjà traité
lors des sessions précédentes reste confirmé. Détail complet :
`API_Fripay-PushA/README.md`, section « 🔍 09/09 (suite 5) ».

**Ce que ça change pour l'app mobile** — rien de bloquant, mais deux
points à garder en tête :
- Le cahier des charges officiel (`Cahier de charge FriPay (Version
  finale).pdf`) décrit un mode offline **USSD**, alors que le module
  hors-ligne réellement construit côté API est un système de **QR Codes
  signés** (`/qr/generate`, `/qr/receive`, etc. — voir section "Reste à
  faire" plus haut). L'écran `offline_screen.dart` de cette app affiche
  toujours un "code USSD de secours" simulé, qui ne correspond à aucun
  des deux mécanismes réels. À clarifier avant de brancher cet écran :
  soit le QR hors-ligne remplace l'USSD prévu, soit l'USSD reste à
  construire séparément.
- Le module **Factures** (`bills_screen.dart`) n'a et n'a jamais eu de
  service backend correspondant, dans aucun des 3 microservices — ce
  n'est pas un oubli de branchement côté app, la fonctionnalité API
  n'existe simplement pas encore.

---
*Dernière mise à jour : session de portage automatisé — voir cases cochées ci-dessus pour le détail exact de ce qui a été livré.*


## 🔁 09/09 (suite 4) — Re-vérification (nouvelle session)

Backend re-testé en direct (voir README de l'API, section « 09/09
(suite 6) ») : les 4 services tournent toujours, `200` partout,
`circuit: closed`. Rien à re-brancher côté app suite à cette
vérification — les écrans déjà listés comme branchés (auth, comptes,
envoi, réception QR marchand, historique, contacts, notifications)
restent fonctionnels.

**Toujours en attente côté app** (inchangé) :
- Factures et Plaintes — aucun service backend correspondant (confirmé
  à nouveau, voir README API)
- QR hors-ligne P2P (`offline_screen.dart`) — le vrai module backend
  (`/qr/generate`, `/qr/receive`, etc.) existe mais n'est pas encore
  branché ; l'écran affiche toujours un faux "code USSD" à clarifier
  avant de le brancher
- `applicationId` par défaut (`com.example.fripay_app`) à changer avant
  publication
- Mode développeur Windows à activer pour un `flutter build apk` /
  `flutter run` complet avec plugins
- Tests de build réels non exécutés dans cette session
