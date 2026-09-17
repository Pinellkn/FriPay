# 🏦 FriPay — Plateforme de Transferts Inter-opérateurs (Bénin)

> Système de transferts d'argent inter-opérateurs mobile money pour le Bénin,
> supportant MTN MoMo, Moov Money, Celtis, et d'autres agrégateurs.

---

## Architecture réelle du dépôt

```
API_Fripay-PushA/
│
├── fripay-gateway/              # 🔀 API Gateway (PHP pur, port 8080)
│   ├── config/gateway.php
│   ├── public/index.php
│   └── src/                     # ProxyClient, CircuitBreaker, RateLimiter, LogManager
│
├── fripay-users/                # 👤 Users Service (Laravel 13, port 8000)
├── fripay-payments/             # 💸 Payments Service (Laravel 13, port 8001)
├── fripay-admin/                # 🛡️ Admin Service (Laravel 13, port 8002)
│
├── packages/fripay-common/      # 📦 Package partagé (referencé en path-repository local)
│
├── start-fripay.ps1             # Démarre MySQL + les 4 services en une commande
└── fripay_database_dump.sql
```

## Microservices

| Service | Port | Rôle | Framework |
|---------|------|------|-----------|
| **Gateway** | 8080 | Point d'entrée unique, rate limiting, circuit breaker | PHP pur |
| **Users** | 8000 | Authentification, profil, comptes mobile money | Laravel 13 |
| **Payments** | 8001 | Transferts, webhooks, QR codes hors-ligne | Laravel 13 |
| **Admin** | 8002 | Back-office, RBAC, corridors, dashboard | Laravel 13 |

## Base de données

⚠️ **Le projet utilise désormais MySQL (XAMPP), et non PostgreSQL.**

- `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=fripay`, `DB_USERNAME=root`, `DB_PASSWORD=` (vide)
- **Une seule base `fripay` partagée entre les 3 services Laravel** — ils partagent donc aussi la même table `migrations`. Les migrations de tables communes (`operators`, `phone_prefixes`, `personal_access_tokens`) sont automatiquement ignorées par les services qui arrivent après le premier à les avoir jouées.
- **⚠️ Ordre de migration important** : `fripay-payments` doit être migré **avant** `fripay-users`, car la table `notifications` (users) référence par clé étrangère la table `transactions` (payments).

## Démarrage rapide

```powershell
# PHP requis : 8.5 (Laravel 13 exige ^8.3). Chemin utilisé dans ce projet :
# C:\Users\Pinel\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe

# 1. Installer les dépendances + migrer (ordre correct géré automatiquement pour les tables communes)
.\setup-mysql.bat

# 2. Si c'est la toute première installation, seed les données de référence :
cd fripay-users  && php artisan db:seed --class=OperatorSeeder --force
cd fripay-admin  && php artisan db:seed --class=RoleAndPermissionSeeder --force

# 3. Démarrer les 4 services d'un coup :
powershell -ExecutionPolicy Bypass -File start-fripay.ps1
```

### Vérification santé

```
GET http://localhost:8000/up        → 200 (Users)
GET http://localhost:8001/up        → 200 (Payments)
GET http://localhost:8002/up        → 200 (Admin)
GET http://localhost:8080/__gateway/status  → état des 3 services + circuit breakers
```

### Test bout-en-bout (inscription via le gateway)

```powershell
$body = @{ phone_number = "+22997123456"; first_name = "Test"; last_name = "User" } | ConvertTo-Json
Invoke-WebRequest -Uri "http://localhost:8080/api/v1/auth/register" -Method POST -Body $body -ContentType "application/json"
# → 201 Created, utilisateur créé en base
```
Format de numéro attendu : `+229` suivi de 8 chiffres, dont le préfixe doit exister dans `phone_prefixes` (seedé par `OperatorSeeder`).

## Variables d'environnement

Chaque service possède son propre fichier `.env` (déjà généré et fonctionnel pour les 3 services Laravel + le gateway). Voir `.env.example` dans chaque dossier pour la liste complète.

Clés communes :
- `DB_*` : voir section Base de données ci-dessus
- `SANCTUM_*` : Authentification par tokens
- `MTN_MOMO_*`, `MOOV_MONEY_*`, `PAYDUNYA_*` : Identifiants agrégateurs (vides — sandbox à configurer)
- `PISPI_WEBHOOK_SECRET` / `KKIAPAY_WEBHOOK_SECRET` / `FEDAPAY_WEBHOOK_SECRET` : Clés HMAC webhooks

---

## 🚧 Suivi de la mise en route (session en cours)

### ✅ Backend fonctionnel de bout en bout (MySQL/XAMPP)
- [x] Bascule complète PostgreSQL → **MySQL (XAMPP, MariaDB 10.4.28)**, plus de blocage admin
- [x] PHP 8.5 configuré avec `mysqli`/`pdo_mysql` actifs, utilisé explicitement (pas le PHP 8.2 du PATH système)
- [x] 3× `composer.lock` corrigés (référençaient l'ancien chemin Laragon `C:/laragon/www/...`) → chemin relatif `../packages/fripay-common`
- [x] `.env` / `.env.example` de `fripay-payments` corrigés (ligne `[TEMPLATE]` invalide supprimée)
- [x] `setup-mysql.bat` corrigé (bug de parenthèses dans un bloc `for` cmd.exe)
- [x] Migration `offline_qr_codes` corrigée (`expires_at` rendu `nullable()`, sinon rejeté par le mode strict MariaDB)
- [x] **21 tables migrées avec succès** dans les 3 bases de service (ordre : payments → users → admin)
- [x] Dossiers `storage/framework/{views,cache,cache/data,sessions,testing}` recréés pour les 3 services Laravel (absents car non trackés par Git — corrige une 500 "Please provide a valid cache path")
- [x] `gateway.bat` et `start-fripay.ps1` corrigés (ancien chemin Laragon + bug de guillemetage PowerShell dû à l'espace dans "Fripay projet")
- [x] Warning PHP 8.5 (`curl_close()` déprécié) supprimé de `fripay-gateway/src/ProxyClient.php` — il polluait les réponses JSON
- [x] Seeders `OperatorSeeder` (fripay-users) et `RoleAndPermissionSeeder` (fripay-admin — attention, existe en double, chaque copie doit être lancée depuis **son propre service**, pas depuis fripay-users) exécutés avec succès
- [x] **Test bout-en-bout réussi** : `POST /api/v1/auth/register` via le gateway (:8080) → Users Service (:8000) → écriture MySQL confirmée
- [x] Les 4 services (gateway, users, payments, admin) démarrent et répondent tous `200` sur leurs endpoints de santé

### ✅ Bugs critiques (P0) de `rapport-audit-api.md` — audités
- [x] C1/C2 (syntaxe invalide MoovMoneyConnector/CeltiisConnector) — déjà corrigés, vérifiés `php -l`
- [x] C3 (détection double-dépense QR cassée) — corrigé, testé en `--dry-run`
- [x] C4 (`IdempotencyMiddleware` dupliqué entre `app/Http/Middleware` et le package partagé) — copie du package partagé supprimée, autoload régénéré

### ✅ Points E1-E7 (sévérité élevée) — re-vérifiés par lecture de code (2026-09-09)
- [x] E1 (rate limiting QR) — `throttle:qr-verify`, `throttle:qr-api`, `throttle:qr-generate`, `throttle:webhook` déjà en place dans `routes/api.php`
- [x] E2 (race condition receive/redeem) — `lockForUpdate()` dans une transaction DB sur `receive()`, `redeem()`, `transfer()` et `revoke()` dans `OfflineQrController`
- [x] E3 (validation `recipient_phone`) — regex stricte `^\+[0-9]+$` (QR) et `^\+229\d{10}$` (transferts, nouveau format 10 chiffres) déjà en place
- [x] E4 (HMAC webhooks) — `WebhookController::verifySignature()` fait un `hash_hmac` + `hash_equals` ; le callback MTN (sans HMAC natif) est filtré par whitelist IP
- [x] E5 (accès au statut QR) — `status()` vérifie que l'utilisateur est expéditeur/récepteur/marchand avant de répondre
- [x] E7 (CORS) — `config/cors.php` présent et cohérent dans les 3 services Laravel (users, payments, admin)
- [x] M2 (limite `expires_minutes`) — validé `min:5|max:60` dans `generate()`
- [x] M4 (purge QR expirés) — `ReconcileOfflineQr` purge après 30 jours (events + QR codes)
- Reste non revérifié en détail : E6 (imports inutilisés — mineur), M1/M3/M5/M6, B1/B2 (tests, CDN pinning)

### 🔌 Connexion de l'app mobile Flutter — en cours
L'app Flutter (`C:\Users\Pinel\AndroidStudioProjects\Fripay_App`) est en
train d'être branchée sur ce backend via le gateway (`http://<host>:8080/api/v1`,
`10.0.2.2` depuis l'émulateur Android). Voir le README de l'app pour le détail.

- [x] Auth complète (register, verify-otp, login, refresh-token, logout, set-pin)
- [x] Comptes mobile money liés (`/users/me/accounts`)
- [ ] Transferts (`/transfers/quote`, `/transfers`) — pas encore branchés côté app
- [ ] Historique (`GET /transfers`) — pas encore branché côté app
- [ ] QR hors-ligne / marchand (`/qr/*`) — endpoints disponibles, pas encore branchés côté app

**Constat important** : aucun endpoint de **solde** n'existe côté API
(`LinkedAccountResource` n'expose pas de `balance`, et il n'y a pas de
service wallet dans `fripay-payments`). Si un solde consolidé par
opérateur est nécessaire côté app, il faudra soit l'ajouter côté
`fripay-payments` (requête vers chaque connecteur), soit construire un
solde propre FriPay dérivé des transactions.

### ⏳ Reste à faire
- [ ] Tester les flux authentifiés bout en bout (verify-otp, login, transferts) — l'OTP n'est jamais exposé par l'API par design ; le tester nécessite soit un connecteur SMS configuré, soit un accès direct DB en environnement de dev
- [ ] Configurer les vraies clés sandbox MTN MoMo / Moov / PayDunya dans les `.env` si des tests de connecteurs réels sont nécessaires
- [x] Compte de test créé au nouveau format (10 chiffres) via `POST /api/v1/auth/register` (`+2290197999888` → 201 Created) et détection d'opérateur vérifiée en conditions réelles via `OperatorDetectionService::detect()` sur les 3 opérateurs : `+2290197999888` → MTN Bénin, `+2290145111222` → Moov Africa Bénin, `+2290120111222` → Celtiis Bénin
- [ ] E6 (imports inutilisés QrCryptoService), M1/M3/M5/M6, B1/B2 — non revérifiés

> **Note (2026-09-09, mise à jour)** : les 4 services tournaient déjà (vérifié via `Get-NetTCPConnection` + `/up` sur les 4 ports, tous 200, circuit breakers fermés). Un accès terminal (Desktop Commander) a permis d'exécuter réellement les tests ci-dessus (inscription + `artisan tinker`), contrairement à la session précédente qui n'avait que lecture/écriture de fichiers.

### 🐞 09/09 (suite 2) — Messages d'erreur de validation en anglais dans une app 100% française

**Symptôme** : à l'inscription (et sur toute route validée par un
`FormRequest`), un numéro déjà pris renvoyait `"The phone number has
already been taken."` tel quel dans l'app mobile — aucun `FormRequest`
des 3 services (`fripay-users`, `fripay-payments`, `fripay-admin`) ne
surchargeait `messages()`, et aucun des 3 n'avait de fichier de langue
français : `APP_LOCALE=en` partout, donc Laravel retombait sur ses
messages de validation par défaut (anglais) codés en dur dans le
framework.

**Corrigé** (sur les 3 services) :
- Ajout de `lang/fr/validation.php` (règles réellement utilisées :
  `required`, `string`, `unique`, `regex`, `size`, `min`, `max`, `in`,
  `exists`, `numeric`, `uuid`, `gte`, etc. + noms d'attributs français
  pour `phone_number`, `pin`, `amount`, `quote_token`...).
- `.env` : `APP_LOCALE=fr` / `APP_FALLBACK_LOCALE=fr` (était `en`).
- `php artisan config:clear` sur les 3 services + redémarrage des 4
  processus PHP (`users`:8000, `payments`:8001, `admin`:8002 relancés ;
  gateway:8080 inchangé).
- Vérifié bout en bout via le gateway : `POST /api/v1/auth/register`
  avec un numéro déjà pris → `"detail": "Ce numéro de téléphone est
  déjà utilisé."` (avant : message anglais brut). Un test regex invalide
  renvoie de même `"Le format de numéro de téléphone est invalide."`.
- Gateway status → toujours `circuit: closed` sur les 3 services après
  redémarrage.

---

### 🐞 09/09 (suite 3) — Parcours OTP testé de bout en bout (mode dev)

**Constat** : `OtpService::generate()` avait déjà été corrigé par la session
précédente — en environnement `local`/`development`/`testing`, le code OTP
est journalisé (`logger()->info`) dans `storage/logs/laravel.log` au lieu
d'être envoyé par SMS (aucun fournisseur SMS n'est branché — ligne
`SmsService::send(...)` toujours en commentaire). Ce log n'est jamais
exposé à l'app mobile ni dans une réponse API.

**Test réalisé** (via le gateway `:8080`, `APP_ENV=local` confirmé) :
1. `POST /api/v1/auth/register` (`+2290196123789`) → `201`, OTP généré
2. Code OTP récupéré dans `fripay-users/storage/logs/laravel.log`
3. `POST /api/v1/auth/verify-otp` (`phone_number`, `code`, `purpose: registration`) → `200`, `access_token` + `refresh_token` émis
4. `POST /api/v1/users/me/pin` avec `Authorization: Bearer <token>` → **`new_pin`** (pas `pin`), **5 caractères exactement** (`size:5`) → `204`
5. `POST /api/v1/auth/login` (`phone_number`, `pin` — 5 caractères) → `200`, nouveaux tokens émis

✅ **Parcours complet inscription → OTP → PIN → login validé en conditions réelles.**

⚠️ **Point d'attention pour le front Flutter** : le PIN est validé côté API
avec la règle `size:5` (exactement 5 caractères), pas 4 comme un code PIN
classique. Si l'écran de saisie du PIN dans l'app mobile est calé sur 4
chiffres, il faut soit l'ajuster à 5, soit modifier la règle côté API
(`SetPinRequest` et `LoginRequest` dans `fripay-users`) — à trancher avant
de brancher cet écran.

### ⏳ Reste à faire (mise à jour)
- [x] Tester les flux authentifiés bout en bout (verify-otp, set-pin, login) — fait via le mode dev OTP (log serveur)
- [x] Décision prise : le PIN reste à **5 caractères** (l'app Flutter était déjà calée dessus — pas de changement API nécessaire)
- [ ] Brancher un vrai fournisseur SMS (Twilio / Africa's Talking / agrégateur béninois) pour la prod — compte à créer par le porteur du projet
- [ ] Configurer les vraies clés sandbox MTN MoMo / Moov / PayDunya dans les `.env` si des tests de connecteurs réels sont nécessaires
- [ ] E6 (imports inutilisés QrCryptoService), M1/M3/M5/M6, B1/B2 — non revérifiés

### 🐞 09/09 (suite 4) — Code OTP exposé temporairement dans la réponse API (mode dev)

Tant qu'aucun fournisseur SMS n'est branché, `POST /api/v1/auth/register`
renvoie désormais un champ `dev_otp_code` dans sa réponse JSON —
**uniquement** quand `APP_ENV` vaut `local`/`development`/`testing`
(`AuthController::register`, `fripay-users`). En production, dès qu'un
vrai SMS partira (`SmsService::send(...)` à implémenter dans
`OtpService::generate`), ce champ ne doit plus jamais être renvoyé — le
code reste alors uniquement dans le SMS envoyé à l'utilisateur.

Testé : `POST /auth/register` avec un nouveau numéro → réponse contient
bien `"dev_otp_code":"638417"` en plus des champs habituels.

---

### 🔍 09/09 (suite 5) — Audit de conformité : cahier des charges + relecture complète de `rapport-audit-api.md`

**Backend vérifié en direct** (pas seulement lu dans le code) : les 4
services tournaient déjà — `GET /up` → `200` sur `:8000`, `:8001`,
`:8002`, et `GET /__gateway/status` → les 3 services `circuit: closed,
failures: 0`. Rien à redémarrer.

**Corrigé dans cette session** (2 points mineurs du `rapport-audit-api.md`
qui restaient marqués « non revérifiés ») :
- [x] **E6** — import inutilisé `App\Models\OfflineQrCode` retiré de
  `fripay-payments/app/Services/QrCryptoService.php` (`php -l` OK après
  correction).
- [x] **B2** (partiel) — version d'Alpine.js pinnée dans
  `fripay-payments/resources/views/qr/index.blade.php`
  (`alpinejs@3.x.x` → `alpinejs@3.14.8`). Le CDN Tailwind
  (`cdn.tailwindcss.com`) reste volontairement **non pinné** : c'est le
  mode "Play CDN" de Tailwind, il ne propose pas de version figée par
  design — pour une vraie version pinnée il faudrait migrer vers un
  build Tailwind (CLI/PostCSS), hors scope d'une simple correction.

**Vérifiés et déjà conformes** (pas besoin de retoucher) :
- M1 (logging) — `OfflineQrController` contient bien 6 appels `Log::`.
- Corridor / routage — `Corridor::where('destination_operator_id', ...)`
  bien utilisé dans `TransferService` et `MerchantQrController` (table
  de règles en base, pas de valeurs codées en dur — conforme à la
  section 6.3 du cahier des charges).
- B1 (tests) — pas vide : `fripay-payments/tests` contient des tests
  Feature (`MtnWebhookTest`, `TransferControllerTest`,
  `WebhookSignatureTest`, `OfflineQrMerchantBlockTest`) et Unit
  (`GatewayConfigTest`, `WebhookControllerTest`) au-delà du squelette
  Laravel par défaut — mais toujours pas de test dédié à
  `QrCryptoService` ou à `OfflineQrController` (signature, vérification,
  double dépense) comme le recommandait l'audit.

**Restent non traités** (mineurs, non bloquants) :
- [ ] M3 — la clé publique Ed25519 d'un QR Code n'est toujours pas liée
  à un compte utilisateur (pas de table `user_keys`) ; limitation de
  conception documentée, pas un bug.
- [ ] M5 — `OfflineQrCode::scopeActive()` toujours défini mais jamais
  appelé (confirmé par recherche dans les controllers).
- [ ] B1 (reste) — tests dédiés `QrCryptoService`/`OfflineQrController`
  toujours absents.

### 🔍 09/09 (suite 5, bis) — Écarts constatés avec le cahier des charges officiel

Lecture de `Cahier de charge FriPay (Version finale).pdf` (19 pages).
Le projet réel a **pivoté** sur plusieurs choix techniques par rapport
à ce document — fonctionnellement l'app marche, mais voici les écarts
factuels à connaître :

| Cahier des charges (§2.6, §6.4) | Réalité du projet | Statut |
|---|---|---|
| Front-end **Vue.js** (PWA) | **Flutter** (app mobile native) | Pivot assumé, non documenté comme décision dans le cahier |
| Back-end **Node.js** | **Laravel/PHP** (3 microservices) | Pivot assumé |
| Base de données **PostgreSQL** (ou MariaDB en continuité) | **MySQL/MariaDB via XAMPP** | Conforme à l'option de repli déjà prévue au cahier (§6.4) |
| Cache/files/idempotence **Redis** | Idempotence gérée en base (clé unique par transaction), pas de Redis identifié | Écart — à vérifier si un Redis est prévu ailleurs |
| Rail **PI-SPI** (prioritaire, §6.3) en parallèle de l'agrégateur | Aucun connecteur PI-SPI trouvé dans le code ; uniquement des connecteurs agrégateurs (MTN, Moov, Celtiis/PayDunya) | **Attendu à ce stade** — le cahier prévoit PI-SPI comme démarche partenariale livrée *après* le sprint MVP (§4.4), pas comme livrable initial |
| Entité **Wallet** (solde applicatif, §6.6) | Aucun endpoint de solde, aucun modèle wallet (déjà noté dans le README de l'app mobile) | Écart à trancher : solde consolidé par opérateur ou solde propre FriPay, à construire |
| **Orchestrateur de routage** multirails avec table `Corridor` (§6.3) | Table `Corridor` existe et est utilisée pour router selon l'opérateur destination — mais un seul rail (agrégateur), pas de cascade PI-SPI → agrégateur → échec explicite | Partiellement conforme — la structure est là, la logique multirail (étape 2-4 du §6.3) n'a pas de second rail à arbitrer pour l'instant |
| Mode offline **USSD ou messagerie cryptée légère** (§2.3) | QR Codes signés Ed25519 (hors-ligne, scan-to-scan) — mécanisme différent de l'USSD décrit | Écart technique — fonctionnellement répond au besoin d'accessibilité offline, mais ce n'est pas de l'USSD ; l'écran mobile `offline_screen.dart` affiche d'ailleurs encore un "code USSD de secours" qui n'est pas ce module QR |
| Module **Factures/abonnements** (§2.2) | Écran mobile présent (`bills_screen.dart`) mais **aucun service backend** (ni users, ni payments, ni admin) | Écart réel — module non implémenté côté API |
| Journal d'audit immuable (§6.5) | Table `offline_qr_events` (event sourcing) pour les QR ; pas confirmé pour les transferts classiques hors QR | À vérifier — le cahier demande un audit trail sur *toute* transaction, pas seulement les QR |

Ces écarts ne sont pas nécessairement des erreurs — plusieurs sont des
pivots techniques raisonnables (Flutter/Laravel/MySQL fonctionnent très
bien pour un MVP) — mais ils devraient être validés avec le porteur du
projet si ce cahier des charges reste la référence contractuelle.

---

*Plateforme FriPay — Bénin*


### 🔁 09/09 (suite 6) — Re-vérification (nouvelle session)

**Backend re-testé en direct** : `GET /up` → `200` sur `:8000`, `:8001`,
`:8002` ; `GET /__gateway/status` → 3 services `circuit: closed,
failures: 0`. Rien à redémarrer, rien de cassé depuis la session
précédente.

**PDFs relus** (`Cahier de charge FriPay (Version finale).pdf`, 19
pages ; `rapport-audit-fripay.pdf`, 8 pages) : dates de modification
inchangées depuis la dernière lecture (07/09) → contenu identique à
celui déjà analysé dans la section « 09/09 (suite 5, bis) »
ci-dessus, tableau d'écarts toujours valide, rien à mettre à jour.

**Conclusion** : aucun régression détectée. Liste "reste à faire"
inchangée par rapport à la session précédente (voir sections
ci-dessus) :
- M3, M5, B1 (reste) côté audit API — mineurs, non bloquants
- Fournisseur SMS réel à brancher pour la prod
- Clés sandbox MTN/Moov/PayDunya à configurer si tests connecteurs réels
- Écarts cahier des charges (Redis, PI-SPI, Wallet/solde, USSD vs QR,
  module Factures) — à trancher avec le porteur de projet, pas des bugs


### ✅ 10/09 — M3, M5 et tests dédiés réellement appliqués et vérifiés

**Constat en ouvrant la session** : les captures d'écran de la session
précédente montraient des appels `edit_block`/`write_file` pour M3, M5
et les tests `QrCryptoServiceTest`/`OfflineQrControllerTest` — mais
aucun de ces fichiers n'était réellement sur disque, et
`OfflineQrController.php`/`QrCryptoService.php` étaient encore dans
leur état "avant fix". Les appels d'outils de la session précédente
n'ont donc jamais été persistés (session probablement interrompue avant
écriture effective). Reparti de zéro sur ces 3 points, avec vérification
systématique après chaque écriture.

**M5 — `scopeActive()` maintenant utilisé** : nouvel endpoint
`GET /api/v1/qr/mine/active` (authentifié) qui liste les QR Codes actifs
de l'utilisateur connecté via `OfflineQrCode::query()->active()->...`.
Route ajoutée dans `routes/api.php`.

**M3 — clé publique liée au compte expéditeur** : nouvelle méthode
`OfflineQrCode::hasPublicKey()` qui compare (avec `hash_equals`) la
clé publique du payload scanné à celle enregistrée en base à la
génération du QR. Appliquée dans `OfflineQrController::receive()` —
retourne `422 PUBKEY_MISMATCH` en cas de divergence. (`redeem()` ne
reçoit que l'UUID, pas de payload à comparer — non applicable, comme
identifié par la session précédente.)

**Tests dédiés créés et exécutés (pas seulement écrits)** :
- `tests/Unit/QrCryptoServiceTest.php` — 8 tests (génération de clés,
  signature/vérification, détection de falsification, expiration,
  magic number, round-trip base64) → **8/8 passent**.
- `tests/Feature/OfflineQrControllerTest.php` — 6 tests (réception
  valide, auto-transfert rejeté, `PUBKEY_MISMATCH`, `verify`, `mine`
  filtre bien par utilisateur et exclut les QR expirés) → **6/6 passent**.
- Suite complète `fripay-payments` relancée : **82 passent / 8
  échouent** — les 8 échecs sont préexistants et sans lien avec ce
  travail (`ExampleTest` route `/`, `MtnWebhookTest::duplicate_webhook`,
  6 tests `TransferControllerTest` liés à la validation
  `sender_account_id`/`quote_token`). Non traités dans cette session.

**Fix d'environnement nécessaire** : PHP 8.5 (celui utilisé pour
Laravel 13) avait `pdo_sqlite` désactivé dans son `php.ini`, ce qui
bloquait tous les tests (`RefreshDatabase` utilise SQLite en mémoire).
Activé.

**Reste à faire** :
- [ ] `redeem()` n'a toujours aucune vérification croisée possible
  (limitation du contrat d'API — n'accepte que l'UUID).
- [ ] Les 8 échecs pré-existants ci-dessus (hors scope de cette session).
- [ ] Brancher l'app mobile Flutter sur ces API (toujours en mock).

## Session — Débogage connectivité mobile (IP LAN obsolète)

**Symptôme** : l'app Flutter affichait "Fripay n'a pas pu joindre le
serveur (http://192.168.1.64:8080/api/v1)" après 12s de timeout.

**Diagnostic** :
- Les 4 services PHP tournaient bien (`netstat` : 0.0.0.0:8000/8001/8002/8080
  en LISTENING, PIDs actifs depuis le dernier `start-fripay.ps1`).
- Pare-feu Windows OK (règles `php.exe` + `Fripay Gateway 8080` déjà
  en Allow/Inbound).
- Cause réelle : `ipconfig` montre que l'IP Wi-Fi du PC est maintenant
  **192.168.1.67** (le DHCP l'a changée depuis la dernière session, le
  code Flutter pointait encore vers l'ancienne **192.168.1.64**).

**Fix** : `kLanHost` mis à jour dans
`Fripay_App/lib/services/api_config.dart` (192.168.1.64 → 192.168.1.67).
Vérifié via `Invoke-WebRequest` depuis le PC vers l'IP LAN : le gateway
répond bien (404 sur `/api/v1/up` = route absente côté gateway mais
serveur joignable ; 403 sur `/__gateway/status` = endpoint restreint
par design).

**⚠️ Point de fragilité identifié** : cette IP est codée en dur et
changera à chaque fois que le PC change de réseau Wi-Fi ou renouvelle
son bail DHCP. À terme, prévoir soit une IP statique/réservation DHCP
pour le PC de dev, soit un mécanisme de découverte (variable
d'environnement / écran de config IP dans l'app en mode debug) pour
éviter de rééditer ce fichier à chaque session.

**Reste à faire (mis à jour)** :
- [ ] Confirmer depuis le téléphone (même Wi-Fi, hot restart complet)
  que la connexion passe désormais.
- [ ] Endpoint solde/wallet — absent côté `fripay-payments` (en cours,
  section suivante).
- [ ] Services backend Factures et Plaintes — inexistants, écrans
  mobiles déjà en place mais rien à brancher derrière.

## Session — Wallet ledger + branchement Flutter (Factures, Plaintes)

**Décision produit** : solde géré comme un vrai wallet local FriPay
(ledger), pas une interrogation live des opérateurs (impossible via
les connecteurs actuels — `TransferConnector` n'expose que
`initiateTransfer()`, aucune méthode de consultation de solde côté
MTN/Moov/Celtiis).

**Backend** :
- `WalletService` (fripay-payments) : crédite/débite le solde de
  façon atomique (verrou + transaction DB), journalise chaque
  mouvement dans une table de ledger dédiée (audit trail).
- Endpoints : `GET /wallet`, `GET /wallet/transactions`,
  `POST /wallet/topup` (dépôt manuel temporaire, en attendant un vrai
  rail de cash-in).
- Module **Factures** (bills) : catalogue de 6 fournisseurs (SBEE,
  SONEB, Canal+, MTN Data, Moov Data, Scolarité), paiement débitant
  réellement le solde via `WalletService`, PIN vérifié côté serveur.
  Testé en conditions réelles : `GET /bills/billers` → 6 fournisseurs ;
  `POST /bills/pay` → 201 et solde débité ; rejet propre si solde
  insuffisant (`INSUFFICIENT_FUNDS`) ; `GET /bills` → historique à
  jour.
- Module **Plaintes** (complaints) : `POST /complaints` crée un
  ticket `TCK-...`, remboursement auto marqué "requested" pour les
  motifs `wrong_transfer` / `not_received` / `duplicate_charge`.
  Testé : création OK.

**Mobile (Flutter)** — branchement effectué, données mock remplacées :
- Widget PIN partagé (`pin_confirm_sheet.dart`) corrigé à 5 chiffres
  (le backend exige 5, l'UI n'en acceptait que 4 — bloquait tout
  paiement réel avant correction).
- `bill_service.dart` / `complaint_service.dart` créés (miroir des
  endpoints ci-dessus).
- `bills_screen.dart` : branché sur `GET /bills/billers` +
  `POST /bills/pay`, PIN réel (plus de `1234` codé en dur).
- `complaints_screen.dart`, `new_ticket_screen.dart`,
  `ticket_detail_screen.dart` : branchés sur `GET/POST /complaints` ;
  le sélecteur de "transaction liée" utilise désormais le vrai
  historique (`GET /transfers`) au lieu du mock.
- `flutter analyze` : 0 erreur (1 info de style mineure, sans impact).

**Reste à faire** :
- [ ] Tester en réel sur téléphone (paiement facture + plainte de
  bout en bout, PIN 5 chiffres).
- [ ] `redeem()` (QR hors-ligne) sans vérification croisée possible
  (limitation du contrat d'API — n'accepte que l'UUID).
- [ ] Les 8 échecs pré-existants listés plus haut (hors scope de
  cette session).
- [ ] `applicationId` toujours `com.example.fripay_app`.
- [ ] Mode développeur Windows pas activé → `flutter build apk` /
  `flutter run` jamais testés en réel sur device.
- [ ] Écart cahier des charges vs réalisé (Vue/Node/PostgreSQL/PI-SPI
  vs Flutter/Laravel/MySQL/QR Ed25519) toujours à trancher avec le
  client.

## Session — Fix Dépôt/Retrait (bug PIN + endpoint retrait manquant)

**Bug signalé** : "PIN incorrect" systématique sur Dépôt ET Retrait.

**Cause** : l'écran `recharge_screen.dart` (Dépôt/Retrait) était resté
100% mock — le bouton comparait le PIN saisi à `'1234'` (4 chiffres)
codé en dur, en local, sans appeler le backend. Or le widget PIN partagé
avait déjà été corrigé à 5 chiffres dans une session précédente : un
PIN à 5 chiffres ne peut plus jamais être égal à `'1234'` → échec
garanti à 100%, quel que soit le vrai PIN entré. Le "Retrait" avait en
plus un second bug : `TabBarView` manquant, les deux onglets
affichaient le même formulaire "Dépôt" sans distinction.

**Découverte en creusant** : côté backend, `POST /wallet/withdraw`
n'existait carrément pas (seul `topup` existait), et `topup` ne
vérifiait même pas de PIN côté serveur (mode dev/test initial).

**Fix backend** (`fripay-payments`) :
- `WalletController::topup()` vérifie désormais le PIN
  (`AuthService::verifyPin`), comme `BillController::pay()`.
- Nouvel endpoint `POST /wallet/withdraw` (`WalletController::withdraw`)
  : PIN requis, débite le solde via `WalletService::debit()` (même
  verrou atomique + erreur `INSUFFICIENT_FUNDS` que Factures/Transferts).
- Nouvelles requêtes de validation `TopupRequest` / `WithdrawRequest`
  (`amount`, `pin` 5 chiffres, `agent_code` optionnel).
- Route ajoutée dans `routes/api.php` ; gateway route déjà par préfixe
  `/api/v1/wallet` donc aucune modif nécessaire côté gateway.
- Vérifié via `php artisan route:list --path=wallet` : 4 routes actives
  sans erreur de syntaxe.

**⚠️ Point à clarifier avec le client — "Code agent"** : il n'existe
aucun registre d'agents FriPay réel (pas de modèle `Agent`, pas de
validation). Le champ est maintenant explicitement **optionnel** côté
mobile et n'est **jamais vérifié** côté serveur — juste conservé à
titre indicatif dans le libellé du mouvement de wallet (audit). Tant
qu'un vrai réseau d'agents n'est pas construit, n'importe quelle valeur
(ou aucune) fonctionne. Le sélecteur "Réseau" (MTN/Moov/Celtiis) sur
cet écran est lui aussi purement cosmétique pour l'instant — le
dépôt/retrait ne fait que créditer/débiter le solde FriPay, sans lien
avec un opérateur réel (cohérent avec le choix "Wallet local FriPay").

**Fix mobile (Flutter)** :
- `wallet_service.dart` : `topup()` prend maintenant `pin` (requis) et
  `agentCode` (optionnel) ; nouvelle méthode `withdraw()`.
- `recharge_screen.dart` réécrit : `TabBarView` réel (Dépôt/Retrait
  affichent enfin des formulaires distincts, avec des contrôleurs de
  champ séparés pour éviter que le texte saisi dans un onglet ne fuite
  vers l'autre), PIN réel envoyé au backend, gestion d'erreur
  (`INVALID_PIN`, `INSUFFICIENT_FUNDS`) affichée à l'utilisateur.
- `flutter analyze` : 0 erreur.

**Reste à faire** :
- [ ] Tester Dépôt et Retrait en réel sur téléphone (montant + PIN).
- [ ] Décider avec le client si un vrai réseau d'agents doit être
  construit (validation de code agent, commission, etc.) ou si
  Dépôt/Retrait doivent rester en mode "libre-service" comme
  actuellement.


## Session — Wallet ledger + fix routage gateway (users/payments)

**Décision produit tranchée** : option "Wallet local Fripay (ledger)"
retenue. Fripay devient un porte-monnaie interne (solde stocké en
base, débité/crédité à chaque transaction), plutôt qu'un simple
passe-plat vers les opérateurs. Points réglementaires (BCEAO/agrément)
à valider avec le client plus tard — hors scope technique de cette
session.

**Implémenté côté `fripay-payments`** :
- Tables `wallets` (solde par utilisateur) et `wallet_ledger_entries`
  (historique des mouvements crédit/débit) — migrations créées et
  **exécutées avec succès** (`php artisan migrate --force`, MySQL
  XAMPP).
- Modèles `Wallet` et `WalletLedgerEntry` ajoutés dans `fripay-common`
  (partagés entre services).
- `WalletService` : débit/crédit atomiques avec verrou de ligne
  (anti double-dépense), remboursement idempotent.
- Câblé dans le flux de transfert : débit à l'initiation
  (`INSUFFICIENT_FUNDS` si solde insuffisant), remboursement
  automatique en cas d'échec/annulation/webhook négatif.
- Nouvelles routes : `GET /api/v1/wallet` (solde),
  `GET /api/v1/wallet/transactions` (historique),
  `POST /api/v1/wallet/topup` (dépôt manuel temporaire, en attendant
  un vrai rail de cash-in).

**Bug de routage gateway trouvé et corrigé** (`config/gateway.php`) :
- Le `health_check` du service `users` pointait vers `/api/v1/up`
  (route inexistante côté `fripay-users`, → 404 permanent), alors que
  `payments`/`admin` utilisent `/up` (route santé Laravel par
  défaut). Résultat : circuit breaker `users` bloqué en `open` (10
  échecs) même quand le service tournait normalement.
  → Corrigé : `health_check` de `users` aligné sur `/up`.
- Le pattern de routes autorisées pour `payments` ne listait pas
  `/api/v1/wallet` → toute requête vers le wallet via le gateway
  renvoyait 404 (route "invisible" pour le routeur du gateway, alors
  qu'elle répondait bien en direct sur le port 8001).
  → Corrigé : `/api/v1/wallet` ajouté aux routes de `payments`.

**Vérifié après correction** :
- `GET /up` sur les 3 services (8000/8001/8002) → 200.
- `GET /__gateway/status` (8080) → les 3 circuits `closed`, 0 échec
  (après quelques requêtes de test pour sortir `payments` de l'état
  `half_open` hérité de l'ancienne panne réseau/IP).
- `GET /api/v1/wallet` via le gateway (8080) → 401 (attendu, route
  bien atteinte, juste besoin d'un token d'auth) — avant le fix :
  404.

**Reste à faire** :
- [ ] Tester le wallet de bout en bout avec un vrai utilisateur
  authentifié (solde, topup, historique, débit lors d'un transfert).
- [ ] Écran mobile Flutter pour le solde/wallet (une fois l'API
  validée manuellement).
- [ ] Services backend Factures et Plaintes — toujours inexistants.
- [ ] Rappel persistant : les 4 services PHP ne survivent pas à la
  fermeture de session Windows — les relancer via `start-fripay.ps1`
  à chaque redémarrage (ou les passer en service Windows si Jos veut
  que ça persiste).

## Session — Test wallet bout en bout + cycle transfert complet validé

**Backend relancé et vérifié** : les 4 services (8000/8001/8002/8080)
répondent 200, gateway avec les 3 circuits `closed`, 0 échec.

**Cycle wallet testé de bout en bout avec succès** (`_test_wallet.ps1`) :
- `GET /wallet` → solde initial 0.
- `POST /wallet/topup` 5000 XOF → solde mis à jour à 5000.
- `GET /wallet/transactions` → écriture de crédit `manual_topup`
  bien enregistrée.

**Blocage `NO_ROUTE_AVAILABLE` trouvé et corrigé** : la table
`corridors` était entièrement vide, donc
`TransferService::calculateQuote()` échouait systématiquement, quel
que soit l'état du backend. Un `CorridorSeeder` a été écrit et
exécuté (un corridor "agrégateur" par opérateur MTN/MOOV/CELTIIS).

**Bug de validation trouvé et corrigé** : `InitiateTransferRequest`
exige un PIN de 5 caractères exactement ; l'utilisateur de test avait
un PIN à 4 chiffres → PIN remis à `12345`.

**Cycle complet `quote → initiate → débit wallet` validé** avec
`_test_transfer.ps1` (script corrigé — un bug de mélange
sortie-console/valeur-de-retour empêchait le parsing JSON du quote) :
1. `POST /transfers/quote` → 200, `quote_token` généré (frais 15 XOF
   sur 1000 XOF, rail `aggregator`/MTN).
2. `POST /transfers` (avec PIN `12345`) → 202, transaction créée en
   statut `pending`.
3. `GET /wallet` → solde débité atomiquement de 5000 → 3985
   (1000 + 15 de frais).
4. `GET /wallet/transactions` → écriture `debit` / `transfer_out`
   bien liée à la transaction (`balance_after: 3985`).

**Conclusion** : le débit atomique du wallet lors d'un transfert
fonctionne de bout en bout. Aucun connecteur MTN/Moov/Celtiis réel
n'est encore branché (transaction reste `pending`, mise en file
d'attente / outbox) — c'est la suite logique à traiter.

## Session suivante — écran mobile Portefeuilles branché sur le wallet

**Backend re-vérifié en bonne santé** après reprise de session (les
serveurs PHP intégrés ne survivent pas à la fermeture de session,
comme déjà noté) :
- `users` (:8000), `payments` (:8001), `admin` (:8002) → `/up` → 200.
- Gateway (:8080) était arrêté → relancé via un script dédié
  (`start-gateway-only.ps1`, équivalent du bloc `[4/4]` de
  `start-fripay.ps1` mais isolé pour ne pas retoucher les 3 services
  déjà up). `/__gateway/status` confirme les 3 circuits `closed`,
  0 échec.
- Note : `/up` n'existe pas sur la gateway elle-même, seulement sur
  les 3 services ; utiliser `/__gateway/status` pour vérifier la
  gateway.

**Côté mobile (`Fripay_App`)** : l'écran `WalletsScreen`
(`lib/screens/wallets/wallets_screen.dart`) était en cours de câblage
au wallet interne lors de l'arrêt précédent (`home_screen.dart` et
`wallet_service.dart` étaient déjà terminés) — repris et finalisé :
- Ajout d'une carte "Solde FriPay" en haut de l'écran (`GET /wallet`,
  formatée via `formatFCFA`), avec bouton "Déposer" vers
  `RechargeScreen`.
- Ajout d'une section "Historique du solde" sous les comptes liés,
  listant les écritures `GET /wallet/transactions` (crédit/débit,
  libellé selon `reason`, montant signé).
- Bug trouvé et corrigé pendant l'implémentation : conflit de nom
  `Wallet` entre `models/models.dart` et `services/wallet_service.dart`
  (`ambiguous_import`) → résolu avec `import '../../models/models.dart'
  hide Wallet;`, même pattern que celui déjà utilisé dans
  `home_screen.dart`.
- `flutter analyze` sur `wallets_screen.dart` et `home_screen.dart` →
  **No issues found!**

**Reste à faire (mis à jour)** :
- [ ] Brancher/tester les connecteurs opérateurs réels (ou confirmer
  le comportement `pending` + webhook attendu en mode agrégateur).
- [x] Écran mobile Flutter pour le solde/wallet — fait (carte solde +
  historique sur `WalletsScreen`, en plus de `BalanceCard` sur
  `HomeScreen`). Testé sur téléphone réel (mode développeur Windows
  activé, `adb devices` voit le téléphone).
- [x] Backend Plaintes (`fripay-payments`) — `Complaint` model,
  `ComplaintController` (GET/POST/GET détail), route ajoutée au
  gateway. Testé bout en bout : `POST /complaints` motif
  `not_received` → 201, ticket `TCK-...` avec `refund_requested: true`.
  Reste à brancher `ComplaintsScreen`/`NewTicketScreen` côté Flutter
  (actuellement en mock).
- [x] Backend Factures (`fripay-payments`) — `Biller`/`BillPayment`
  models, `BillController` (catalogue, paiement, historique, détail),
  6 fournisseurs seedés (miroir exact de `mock_data.dart` : SBEE,
  SONEB, Canal+, Forfait MTN/Moov, Scolarité), route ajoutée au
  gateway. Paiement débite le solde FriPay via `WalletService`
  (PIN vérifié côté serveur, rejet `INSUFFICIENT_FUNDS` propre).
  Testé bout en bout le 11/09 : `GET /bills/billers` → 6 résultats,
  `POST /bills/pay` (SBEE, 2500 FCFA) → 201 + solde débité
  50000 → 47500, `GET /bills` → historique à jour. Reste à
  brancher `BillsScreen` côté Flutter (actuellement 100% mock,
  PIN codé en dur `1234`, pas d'appel HTTP).
- **✅ RÉSOLU — écran Portefeuille vide / solde en colonne verticale**.
  Deux bugs distincts et indépendants étaient superposés :
  1. **MySQL (XAMPP) arrêté** au démarrage de cette session : les
     logs `fripay-users/storage/logs/laravel.log` montraient
     `SQLSTATE[HY000] [2002] ... connexion refusée (Host:
     127.0.0.1, Port: 3306)`. Conséquence : tous les appels
     authentifiés échouaient, ce qui ouvrait les circuit breakers
     `users`/`payments` de la gateway (6 échecs → `open` → 30s de
     rejet), d'où le message "service momentanément inaccessible"
     puis l'écran qui ne recevait jamais ses données.
     → Fix : relancer `mysqld.exe` avant les 4 services PHP à
     chaque session (pas de service Windows, XAMPP MySQL doit être
     démarré manuellement — **automatisé depuis dans
     `start-fripay.ps1`**, qui détecte et démarre MySQL tout seul).
  2. **Vrai bug Flutter** (indépendant du backend, présent même une
     fois les données chargées) : le solde s'affichait en colonne
     verticale, une lettre par ligne. Cause exacte trouvée via
     `flutter run` en mode debug (le mode release masque cette
     exception) :
     ```
     BoxConstraints forces an infinite width.
     The relevant error-causing widget was: OutlinedButton
     wallets_screen.dart:163
     ```
     Le thème global (`app_theme.dart`, `outlinedButtonTheme`) fixe
     `minimumSize: Size.fromHeight(52)` pour tous les
     `OutlinedButton` (= largeur **infinie** par défaut, pensé pour
     les boutons pleine largeur). Le bouton "Déposer" de
     `WalletsScreen` était placé dans une `Row` à côté d'un
     `Expanded` → largeur infinie propagée → crash de layout
     (écran blanc, ou texte compressé selon le moment du rendu).
     → Fix appliqué dans `wallets_screen.dart` (bouton "Déposer") :
     ```dart
     style: OutlinedButton.styleFrom(
       ...
       minimumSize: Size.zero,
       tapTargetSize: MaterialTapTargetSize.shrinkWrap,
     ),
     ```
     Vérifié : c'est le **seul** endroit de l'app où un
     `OutlinedButton` partage une `Row` avec un `Expanded` sans
     `minimumSize` explicite (grep fait sur tous les écrans) — pas
     de récidive ailleurs.
  - Une piste explorée puis abandonnée : désactiver le rendu
    Impeller/Vulkan (`EnableImpeller=false` dans
    `AndroidManifest.xml`) — n'était pas la vraie cause (le bug
    persistait), changement annulé.
  - Vérifié en conditions réelles sur le téléphone (build release,
    pas juste `flutter run`) : l'écran Portefeuille affiche
    maintenant correctement le solde ("0 FCFA" sur une ligne) et les
    **3 comptes liés** de Josephine SENOU (MTN MoMo principal, MTN
    MoMo, Moov Money).

## Session — Bug "Une erreur est survenue" sur l'écran Plaintes (doublons de processus PHP)

**Symptôme signalé** (capture d'écran téléphone réel) : l'écran
Plaintes affichait "Une erreur est survenue. Tirez pour réessayer."
au chargement.

**Ce qui n'était PAS le problème** (vérifié avant d'incriminer autre
chose) :
- Route gateway pour `/api/v1/complaints` : déjà présente dans
  `config/gateway.php` (routage OK, testé 401 sans token = route
  bien atteinte).
- `ComplaintController::index()` / `ComplaintResource` : format JSON
  vérifié conforme au modèle Dart `ApiComplaint.fromJson` (aucun
  champ manquant ou mal typé).
- IP LAN configurée dans `api_config.dart` (`192.168.1.66`) :
  toujours à jour, correspond bien à l'IP Wi-Fi actuelle du PC.

**Cause réelle trouvée** : **2 processus PHP dupliqués écoutaient
simultanément sur chacun des 4 ports** (8000, 8001, 8002, 8080) —
restes de lancements `start-fripay.ps1` non nettoyés entre sessions
précédentes (confirmé via `Get-CimInstance Win32_Process -Filter
"Name = 'php.exe'"`, 8 processus au lieu de 4, mêmes commandes `-S
0.0.0.0:PORT` en double). Deux serveurs PHP intégrés qui bindent le
même port sous Windows peuvent cohabiter sans erreur au démarrage,
mais le routage réseau devient non déterministe — une requête peut
atterrir sur le process "sain" ou sur un process resté bloqué/dans un
mauvais état selon l'origine de la connexion. Symptôme cohérent :
`localhost` (PC) fonctionnait de façon fiable (react toujours au même
process apparemment), alors que le téléphone (connexion externe via
IP LAN) tombait parfois sur le second process et échouait — d'où
l'erreur qui semblait spécifique à l'écran Plaintes alors que
n'importe quel écran aurait pu être touché au hasard.

**Fix** : tous les `php.exe` tués (`Stop-Process -Force`), backend
relancé proprement via `start-fripay.ps1` → **exactement 1 processus
par port** vérifié (`netstat -ano`).

**Vérifié après fix** :
- `GET /api/v1/complaints` avec un token réel, via `127.0.0.1:8080`
  **et** via l'IP LAN `192.168.1.66:8080` (celle utilisée par le
  téléphone) → `200` dans les deux cas, 1 ticket existant renvoyé
  avec le bon format.

**Point de vigilance pour la suite** : si ce genre d'erreur
intermittente revient, **toujours vérifier en premier qu'il n'y a
qu'un seul processus PHP par port** (`netstat -ano | findstr
LISTENING` ou `Get-CimInstance Win32_Process -Filter "Name =
'php.exe'"`) avant de chercher un bug côté code — `start-fripay.ps1`
ne vérifie pas actuellement si des processus tournent déjà avant d'en
relancer de nouveaux sur les mêmes ports.

**Reste à faire** :
- [ ] Confirmer sur le téléphone que l'écran Plaintes charge
  correctement maintenant (tirer pour réessayer ou relancer l'app).
- [x] Améliorer `start-fripay.ps1` pour qu'il tue/détecte les
  processus déjà présents sur ces 4 ports avant de relancer (voir
  section suivante — fait et vérifié).

## Session — `start-fripay.ps1` : nettoyage automatique des doublons + re-vérification Plaintes

**Fait** : ajout d'une étape `[nettoyage]` en tête de `start-fripay.ps1`
(fonction `Stop-PortProcess`) qui, pour chacun des 4 ports
(8000/8001/8002/8080), identifie via `Get-NetTCPConnection` tout
processus déjà en écoute et le termine (`Stop-Process -Force`) **avant**
de démarrer les nouveaux processus PHP. Même logique que le garde-fou
déjà existant pour MySQL, généralisée aux 4 services.

**Vérifié en conditions réelles** :
- Script relancé avec les 4 anciens processus encore actifs → les 4
  ont bien été détectés et tués, puis remplacés par 4 nouveaux PID
  (aucun doublon créé).
- Recontrôle `Get-CimInstance Win32_Process -Filter "Name='php.exe'"`
  + `netstat -ano` → **exactement 1 processus par port**, comme
  attendu.
- `GET /api/v1/complaints` testé via l'IP LAN (`192.168.1.66:8080`,
  celle utilisée par le téléphone) avec un token réel → `200`, liste
  cohérente (le ticket de test créé lors de la session précédente est
  bien retourné). Le module Plaintes répond donc correctement côté
  API — si "Une erreur est survenue" réapparaît sur le téléphone,
  relancer l'app (le bug de doublons est désormais structurellement
  empêché par ce script, pas seulement corrigé ponctuellement).
- `kLanHost` dans `api_config.dart` (`192.168.1.66`) confirmé toujours
  à jour par rapport à l'IP Wi-Fi réelle du PC (`ipconfig`).

**Précision** : `ComplaintsScreen` était déjà branché sur la vraie API
(`ComplaintService.instance.list()`, pas de mock) — vérifié directement
dans le code. Le texte "Une erreur est survenue. Tirez pour réessayer."
est exactement le message `catch` générique de cet écran ; il ne
s'affiche que quand l'appel réseau échoue, ce qui colle avec la cause
racine (doublons de processus PHP) déjà identifiée ci-dessus.

**Reste à faire** :
- [ ] Confirmer sur le téléphone (réouverture de l'app) que l'écran
  Plaintes charge bien maintenant.

## Session — §6.e QR argent : contrôleur public "receveur externe"

**Contexte** : le QR "argent" P2P (`OfflineQrController`), la migration
`add_external_claim_columns_to_offline_qr_codes_table` et les helpers du
modèle `OfflineQrCode` (`isExternal()`, `externalAttemptsRemaining()`,
`isExternallyClaimable()`) existaient déjà. Il manquait le contrôleur
public consommé par la page web (§6.e.4-8, receveur sans compte Fripay
qui scanne avec un scanner externe).

**Fait** : `App\Http\Controllers\Api\ExternalClaimController`
(`fripay-payments/app/Http/Controllers/Api/`), **sans middleware auth**
(protégé uniquement par `throttle:qr-external-claim`, déjà déclaré dans
`AppServiceProvider`) :
- `GET /api/v1/qr/external/{uuid}` (`lookup`) — infos publiques
  (montant, devise, statut, tentatives restantes) pour que la page
  web affiche le montant avant de demander le code.
- `POST /api/v1/qr/external/{uuid}/claim` (`claim`) — code à 5
  chiffres (comparaison `hash_equals`, temps constant) + numéro de
  retrait (n'importe quel réseau, détecté/normalisé via
  `OperatorDetectionService`). Verrouillage pessimiste
  (`lockForUpdate`) + transaction DB.
  - Code correct → QR passé à `redeemed`, `external_claimed_at` /
    `settled_at` renseignés, numéro/réseau de retrait tracés
    (`external_payout_number/network`). Le connecteur natif de
    disbursement par opérateur n'est pas encore implémenté dans ce
    dépôt (même écart que `TransferService`) — le règlement est donc
    "prêt pour connecteur", pas encore un vrai virement opérateur.
  - Code incorrect → `external_attempts` incrémenté ; au 3ᵉ échec
    (`externalAttemptsRemaining() <= 0`) : QR passé à `cancelled`,
    **vrai crédit wallet** de l'expéditeur via `WalletService::credit`
    (les fonds avaient été débités/held dès la génération du QR),
    événement `EVENT_CANCELLED_REFUNDED` tracé (§7, traçabilité —
    c'est la seule "notification" à l'envoyeur pour l'instant, aucun
    système de notification push/email n'existe encore dans ce dépôt).
- Routes ajoutées dans `fripay-payments/routes/api.php`, section dédiée
  juste après le bloc QR hors-ligne existant.

**Vérifié** :
- `php -l` propre sur `ExternalClaimController.php` et `routes/api.php`
  (binaire WinGet PHP 8.5, pas celui de XAMPP — voir piège déjà noté
  plus haut dans ce README).
- Migration `2026_09_16_000001_add_external_claim_columns...` :
  déjà `Ran` (batch 11) en base — confirmé via
  `artisan migrate:status`.
- `artisan route:clear` + `artisan route:list --path=qr/external` →
  les 2 routes (`lookup`, `claim`) apparaissent correctement, sans
  middleware `auth:sanctum`.

**Reste à faire (§6, plus gros morceaux)** :
- [ ] Page web publique elle-même (HTML/JS servie hors appli) qui
  consomme ces deux endpoints — rien n'existe encore côté frontend
  pour ce parcours.
- [ ] Écrans Flutter : génération QR côté envoyeur avec `recipient_phone`
  (déjà supporté par `OfflineQrController::generate`), scan + bouton
  "Uploader" côté receveur, flux "modifier le destinataire" (§6.c,
  transfert à un tiers — l'endpoint `transfer` existe déjà côté
  backend).
- [ ] §7 (historique/traçabilité complet côté app) et §8 (interfaces
  technique/globale séparées) — pas attaqués.
