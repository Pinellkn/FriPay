# Référence technique — Base de données & API
## Application de transfert inter-opérateurs (Bénin)

Ce document est la source de vérité partagée par toute l'équipe (back-end, back-office, application client) pour :
- la structure de la base de données PostgreSQL,
- le contrat de chaque route API (entrée, sortie, codes d'erreur),
- les conventions communes à respecter par tout le monde.

Toute modification d'une route ou d'une table doit être répercutée ici avant d'être développée. En cas de doute entre ce document et le code, ce document fait foi jusqu'à mise à jour explicite.

Le script SQL complet correspondant à ce dictionnaire est fourni séparément : `schema.sql`.

---

## 1. Conventions générales

Ces règles s'appliquent à **toutes** les routes, sans exception. Elles existent pour qu'un développeur back-end et un développeur front-end puissent travailler en parallèle sans se resynchroniser à chaque endpoint.

### 1.1 Architecture des services

Le back-end est découpé en deux services applicatifs distincts (cohérent avec l'architecture validée) :

| Service | Responsabilité | Préfixe de route |
|---|---|---|
| **Service Utilisateurs** (`users-service`) | Comptes, authentification, comptes mobile money liés, contacts, notifications | `/api/v1/...` |
| **Service Paiements** (`payments-service`) | Simulation de frais, initiation de transferts, suivi de statut, réception des webhooks | `/api/v1/...` |
| **API Back-office** (`admin-service`, peut être un module des deux précédents exposé séparément) | Gestion interne : utilisateurs, transactions, corridors, staff | `/api/v1/admin/...` |

Le front-end client (web + mobile) et le front-end back-office consomment ces API via une **API Gateway unique** — ils ne parlent jamais directement aux bases de données ni aux connecteurs (PI-SPI, agrégateurs).

### 1.2 Format des échanges

- Toutes les routes échangent en **JSON** (`Content-Type: application/json; charset=utf-8`).
- Encodage des identifiants : **UUID v4** en chaîne de caractères pour toute ressource exposée publiquement (utilisateur, transaction, contact...). Les identifiants internes de référentiel (opérateur, corridor) restent des entiers.
- Casse des champs JSON : **snake_case** partout (`first_name`, `created_at`), jamais de camelCase, pour rester cohérent avec les noms de colonnes SQL et éviter toute conversion silencieuse.
- Dates et heures : **ISO 8601 avec fuseau**, toujours en UTC dans les échanges API (`2026-07-04T14:32:00Z`). L'affichage en heure locale (Bénin, UTC+1) est une responsabilité du front-end.
- Montants : nombre **décimal en chaîne ou number**, toujours en **XOF** (franc CFA, pas de sous-unité). Champ toujours accompagné d'un champ `currency` explicite même s'il vaut toujours `"XOF"` en V1, pour ne pas avoir à changer le contrat le jour d'une extension régionale.
- Téléphones : format international **E.164** sans espace (`+22997xxxxxx`), validé et normalisé côté back-end à chaque entrée.

### 1.3 Authentification

- **Application client** : JWT (access token courte durée ~15 min + refresh token longue durée géré via `auth_sessions`), transmis en en-tête `Authorization: Bearer <token>`.
- **Back-office** : même mécanisme JWT, avec un `role` embarqué dans le token et vérifié à chaque route sensible (RBAC via les tables `roles` / `permissions`).
- **Webhooks entrants** (agrégateurs, PI-SPI) : pas de JWT — vérification de signature HMAC dans un en-tête dédié (`X-Signature`), rejet immédiat (401) si invalide, avant tout traitement métier.

### 1.4 En-têtes obligatoires

| En-tête | Obligatoire sur | Rôle |
|---|---|---|
| `Authorization: Bearer <token>` | Toute route authentifiée | Identification de l'appelant |
| `Idempotency-Key` | Toute route `POST` qui déclenche un mouvement d'argent | Empêche tout double traitement en cas de retry réseau |
| `Accept-Language` | Toute route retournant du texte utilisateur | `fr` par défaut ; prévu pour extension multilingue |
| `X-Request-Id` | Toutes les routes | Traçabilité de bout en bout dans les logs |

### 1.5 Pagination

Toutes les routes de liste utilisent une pagination par curseur ou page, au choix uniforme suivant :

```
GET /api/v1/transfers?page=1&size=20&sort=-initiated_at
```

Réponse standard :
```json
{
  "data": [ ... ],
  "meta": { "page": 1, "size": 20, "total": 134, "has_next": true }
}
```

### 1.6 Format des erreurs

Toutes les erreurs suivent un format unique, inspiré de la RFC 7807 (le même standard qu'utilise PI-SPI côté BCEAO, pour rester cohérent avec les connecteurs) :

```json
{
  "type": "INSUFFICIENT_FUNDS",
  "title": "Solde insuffisant",
  "status": 422,
  "detail": "Le compte source ne dispose pas des fonds nécessaires pour ce transfert.",
  "request_id": "a1b2c3d4"
}
```

- `4xx` : erreur causée par l'appelant (validation, autorisation, état de la ressource).
- `5xx` : erreur serveur ou dépendance externe (agrégateur, PI-SPI indisponible).
- Le catalogue complet des valeurs de `type` est en section 8.

### 1.7 Versionnement

Toutes les routes sont préfixées `/api/v1/`. Un changement non rétrocompatible impose un `/api/v2/` — jamais de modification silencieuse d'un contrat existant une fois qu'un front-end le consomme.

---

## 2. Dictionnaire de données (PostgreSQL)

Vue d'ensemble des tables. Le détail des colonnes suit ; le script exécutable complet est dans `schema.sql`.

| Table | Rôle |
|---|---|
| `operators` | Référentiel des 3 opérateurs béninois (MTN, Moov, Celtiis) |
| `phone_prefixes` | Préfixes numériques permettant de détecter l'opérateur d'un numéro |
| `users` | Comptes utilisateurs de l'application |
| `otp_codes` | Codes de vérification à usage unique |
| `auth_sessions` | Sessions actives / refresh tokens |
| `linked_accounts` | Comptes mobile money rattachés à un utilisateur |
| `contacts` | Destinataires favoris |
| `corridors` | Règles de routage pilotant l'orchestrateur de transfert |
| `transactions` | Chaque transfert, son montant, ses frais, son statut |
| `transaction_status_history` | Historique des changements de statut d'une transaction |
| `notifications` | Notifications envoyées aux utilisateurs |
| `audit_logs` | Journal d'audit immuable (conformité BCEAO) |
| `webhook_events` | Journal brut des webhooks reçus des connecteurs |
| `pending_transfers` | File d'attente des transferts différés (mode offline / outbox) |
| `roles`, `permissions`, `role_permissions` | Contrôle d'accès du back-office |
| `staff_users` | Comptes du personnel interne (back-office) |

### 2.1 `operators`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | smallserial | PK | Identifiant interne |
| `code` | varchar(20) | unique, not null | Code court : `MTN`, `MOOV`, `CELTIIS` |
| `name` | varchar(100) | not null | Nom commercial complet |
| `country_code` | char(2) | default `BJ` | Pays de l'opérateur |
| `active` | boolean | default true | Opérateur actif dans le routage |
| `created_at` / `updated_at` | timestamptz | | Horodatages |

### 2.2 `phone_prefixes`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | serial | PK | |
| `operator_id` | smallint | FK → operators.id | |
| `prefix` | varchar(10) | | Préfixe numérique (ex. `97`, `41`, `43`) |
| `country_code` | char(2) | | |

### 2.3 `users`

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `id` | uuid | PK | Identifiant public de l'utilisateur |
| `phone_number` | varchar(20) | unique, not null | Numéro E.164, identifiant de connexion |
| `first_name` / `last_name` | varchar(100) | | |
| `email` | varchar(150) | unique, nullable | Optionnel |
| `pin_hash` | text | | PIN applicatif haché (jamais en clair) |
| `kyc_status` | enum | `pending` / `verified` / `rejected` | |
| `client_type` | enum | `P` / `C` / `B` / `G` | Catégorie PI-SPI (voir cahier des charges §3.3) |
| `status` | enum | `active` / `blocked` / `suspended` | |
| `preferred_language` | varchar(5) | default `fr` | |
| `last_login_at` | timestamptz | | |
| `created_at` / `updated_at` | timestamptz | | |

### 2.4 `otp_codes`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `phone_number` | varchar(20) | Numéro cible |
| `code_hash` | text | Code haché, jamais stocké en clair |
| `purpose` | enum | `registration` / `login` / `transaction_confirmation` / `password_reset` |
| `attempts` | smallint | Compteur de tentatives, pour limiter le brute-force |
| `consumed` | boolean | |
| `expires_at` | timestamptz | |

### 2.5 `auth_sessions`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid FK | |
| `refresh_token_hash` | text | |
| `device_info` | text | User-agent / identifiant d'appareil |
| `ip_address` | inet | |
| `revoked` | boolean | |
| `expires_at` | timestamptz | |

### 2.6 `linked_accounts`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid FK | |
| `operator_id` | smallint FK | |
| `msisdn` | varchar(20) | Numéro mobile money lié (peut différer du numéro de connexion) |
| `alias_type` | enum | `phone` / `pispi_shid` — rempli une fois PI-SPI intégré |
| `alias_value` | varchar(64) | Valeur de l'alias PI-SPI le cas échéant |
| `is_primary` | boolean | Compte utilisé par défaut à l'émission |
| `status` | enum | `active` / `inactive` |

### 2.7 `contacts`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid FK | Propriétaire du carnet de contacts |
| `contact_phone` | varchar(20) | |
| `contact_name` | varchar(150) | |
| `detected_operator_id` | smallint FK | Opérateur détecté automatiquement |

### 2.8 `corridors`

Table pilotée par le back-office, lue par l'orchestrateur à chaque transfert.

| Colonne | Type | Description |
|---|---|---|
| `id` | serial | |
| `source_operator_id` / `destination_operator_id` | smallint FK | Sens du corridor |
| `rail` | enum | `pispi` / `aggregator` / `manual` |
| `aggregator_provider` | varchar(50) | Connecteur utilisé (agrégateur ou API native de l'opérateur), nul si rail `pispi` |
| `priority` | smallint | 1 = priorité la plus haute (l'orchestrateur essaie dans cet ordre) |
| `fee_type` | enum | `fixed` / `percentage` / `tiered` |
| `fee_value` | numeric(12,4) | Valeur du taux ou du montant fixe |
| `fee_cap` | numeric(12,2) | Plafond de frais, si applicable |
| `min_amount` / `max_amount` | numeric(14,2) | Bornes autorisées sur ce corridor |
| `active` | boolean | |

### 2.9 `transactions`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | Identifiant public |
| `reference` | varchar(40) | Référence lisible (ex. `TXN-20260704-A1B2C3`), affichée à l'utilisateur |
| `idempotency_key` | varchar(100) | Clé fournie par le client à l'initiation, unique |
| `sender_user_id` | uuid FK | |
| `sender_account_id` | uuid FK → linked_accounts | Compte source |
| `recipient_phone` | varchar(20) | |
| `recipient_operator_id` | smallint FK | |
| `recipient_name` | varchar(150) | Résolu via alias PI-SPI ou saisie manuelle |
| `amount` | numeric(14,2) | Montant envoyé, hors frais |
| `currency` | char(3) | `XOF` |
| `fee_amount` | numeric(14,2) | Frais appliqués |
| `total_debited` | numeric(14,2) | `amount + fee_amount` |
| `rail_used` | enum | Rail effectivement utilisé |
| `aggregator_provider` | varchar(50) | Si rail agrégateur |
| `corridor_id` | integer FK | Corridor appliqué |
| `status` | enum | `initiated` / `pending` / `processing` / `succeeded` / `failed` / `cancelled` |
| `failure_reason` | text | |
| `external_reference` | varchar(100) | Identifiant renvoyé par PI-SPI / l'agrégateur |
| `client_type_snapshot` | enum | Catégorie PI-SPI de l'émetteur au moment du transfert (traçabilité) |
| `metadata` | jsonb | Données additionnelles libres (motif, canal...) |
| `initiated_at` / `completed_at` | timestamptz | |

### 2.10 `transaction_status_history`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigserial | |
| `transaction_id` | uuid FK | |
| `previous_status` / `new_status` | enum | |
| `source` | varchar(50) | `webhook` / `manual` / `system` |
| `note` | text | |

### 2.11 `notifications`

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid FK | |
| `type` | enum | `transaction_update` / `security` / `marketing` / `system` |
| `channel` | enum | `push` / `sms` / `email` / `in_app` |
| `title` / `body` | varchar / text | |
| `related_transaction_id` | uuid FK nullable | |
| `read` | boolean | |

### 2.12 `audit_logs`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigserial | |
| `actor_type` | enum | `user` / `staff` / `system` |
| `actor_id` | varchar(100) | |
| `action` | varchar(100) | ex. `transaction.status_changed`, `user.blocked` |
| `entity_type` / `entity_id` | varchar | Ressource concernée |
| `payload` | jsonb | Détail de l'action |
| `ip_address` | inet | |

### 2.13 `webhook_events`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigserial | |
| `provider` | varchar(50) | `kkiapay`, `fedapay`, `pispi`, ... |
| `signature_valid` | boolean | |
| `payload` | jsonb | Corps brut reçu |
| `processed` | boolean | |
| `processing_error` | text | |

### 2.14 `roles`, `permissions`, `role_permissions`, `staff_users`

Modèle RBAC classique pour le back-office : un `staff_user` a un `role`, un `role` a un ensemble de `permissions` (ex. `transactions.read`, `users.block`, `corridors.write`).

---

### 2.15 `pending_transfers`

File d'attente locale (outbox) des transferts acceptés alors que le connecteur du réseau est indisponible ou pas encore intégré.

| Colonne | Type | Description |
|---|---|---|
| `id` | uuid | |
| `transaction_id` | uuid FK | Transaction concernée |
| `payload` | jsonb | Paramètres de l'appel au connecteur (montant, téléphone, référence, opérateur) |
| `status` | varchar | `pending` / `processing` / `completed` |
| `attempts` / `max_attempts` | smallint | Tentatives effectuées / maximum (défaut 10) |
| `next_retry_at` | timestamptz | Prochaine tentative (backoff exponentiel) |
| `last_error` | text | Dernière erreur rencontrée |

---

## 3. Routes API — Service Utilisateurs & Authentification

Base : `/api/v1`

### 3.1 Inscription & authentification

#### `POST /auth/register`
Crée un compte à partir d'un numéro de téléphone et déclenche l'envoi d'un OTP.

**Entrée**
```json
{
  "phone_number": "+22997000000",
  "first_name": "Aline",
  "last_name": "Dossou"
}
```
**Sortie `201`**
```json
{ "user_id": "uuid", "phone_number": "+22997000000", "otp_expires_in": 300 }
```
**Erreurs** : `409 PHONE_ALREADY_REGISTERED`, `422 INVALID_PHONE_FORMAT`

#### `POST /auth/verify-otp`
**Entrée**
```json
{ "phone_number": "+22997000000", "code": "482913", "purpose": "registration" }
```
**Sortie `200`**
```json
{ "access_token": "...", "refresh_token": "...", "expires_in": 900 }
```
**Erreurs** : `400 OTP_INVALID`, `410 OTP_EXPIRED`, `429 TOO_MANY_ATTEMPTS`

#### `POST /auth/login`
**Entrée** : `{ "phone_number": "+22997000000", "pin": "1234" }`
**Sortie `200`** : mêmes champs que `verify-otp`.
**Erreurs** : `401 INVALID_CREDENTIALS`, `423 ACCOUNT_BLOCKED`

#### `POST /auth/refresh-token`
**Entrée** : `{ "refresh_token": "..." }`
**Sortie `200`** : nouveau couple access/refresh token.

#### `POST /auth/logout`
Révoque la session courante (`auth_sessions.revoked = true`). Sortie `204`.

### 3.2 Profil utilisateur

#### `GET /users/me`
**Sortie `200`**
```json
{
  "id": "uuid", "phone_number": "+22997000000", "first_name": "Aline",
  "last_name": "Dossou", "email": null, "kyc_status": "verified",
  "client_type": "P", "status": "active", "created_at": "2026-06-01T10:00:00Z"
}
```

#### `PUT /users/me`
**Entrée** : sous-ensemble modifiable — `{ "first_name": "...", "last_name": "...", "email": "..." }`
**Sortie `200`** : profil mis à jour.

#### `POST /users/me/pin`
Définit ou modifie le PIN applicatif.
**Entrée** : `{ "current_pin": "1234", "new_pin": "5678" }` (`current_pin` omis à la création initiale)
**Sortie `204`**. **Erreurs** : `401 INVALID_CURRENT_PIN`

### 3.3 Comptes mobile money liés

#### `GET /users/me/accounts`
**Sortie `200`**
```json
{ "data": [
  { "id": "uuid", "operator": "MTN", "msisdn": "+22997000000", "is_primary": true, "status": "active" }
]}
```

#### `POST /users/me/accounts`
**Entrée** : `{ "msisdn": "+22961000000" }` (opérateur détecté automatiquement côté serveur via `phone_prefixes`)
**Sortie `201`** : compte créé.
**Erreurs** : `422 OPERATOR_NOT_SUPPORTED`, `409 ACCOUNT_ALREADY_LINKED`

#### `DELETE /users/me/accounts/{account_id}`
**Sortie `204`**. **Erreurs** : `409 CANNOT_DELETE_LAST_ACCOUNT`

### 3.4 Contacts favoris

#### `GET /users/me/contacts`
#### `POST /users/me/contacts` — `{ "contact_phone": "...", "contact_name": "..." }`
#### `DELETE /users/me/contacts/{contact_id}`

Contrats identiques au bloc "comptes liés" ci-dessus (liste / création / suppression).

### 3.5 Notifications

#### `GET /notifications?read=false&page=1&size=20`
#### `PUT /notifications/{notification_id}/read` — marque comme lue, sortie `204`.

---

## 4. Routes API — Service Paiements / Transferts

Base : `/api/v1`

### 4.1 Simulation avant confirmation

#### `POST /transfers/quote`
Calcule l'opérateur détecté, le rail qui sera utilisé et les frais réels — **appelée systématiquement avant confirmation**, jamais de frais surprise.

**Entrée**
```json
{
  "sender_account_id": "uuid",
  "recipient_phone": "+22941000000",
  "amount": 5000
}
```
**Sortie `200`**
```json
{
  "recipient_operator": "MOOV",
  "recipient_name": null,
  "amount": 5000,
  "fee_amount": 75,
  "total_debited": 5075,
  "rail": "aggregator",
  "estimated_delivery_seconds": 30,
  "quote_token": "opaque-token-valable-2-minutes"
}
```
`quote_token` doit être renvoyé tel quel à l'appel `POST /transfers` suivant, pour garantir que le montant confirmé par l'utilisateur est exactement celui exécuté.

**Erreurs** : `422 AMOUNT_OUT_OF_RANGE`, `422 NO_ROUTE_AVAILABLE` (aucun corridor actif pour cette paire d'opérateurs)

### 4.2 Initiation d'un transfert

#### `POST /transfers`
**En-tête obligatoire** : `Idempotency-Key: <uuid généré côté client>`

**Entrée**
```json
{
  "quote_token": "opaque-token...",
  "sender_account_id": "uuid",
  "recipient_phone": "+22941000000",
  "amount": 5000,
  "pin": "1234"
}
```
**Sortie `202`** (traitement asynchrone tant que le rail externe n'a pas confirmé)
```json
{
  "transaction_id": "uuid",
  "reference": "TXN-20260704-A1B2C3",
  "status": "processing"
}
```
**Erreurs** : `401 INVALID_PIN`, `410 QUOTE_EXPIRED`, `422 INSUFFICIENT_FUNDS`, `409 DUPLICATE_IDEMPOTENCY_KEY` (renvoie alors la transaction déjà créée, jamais un doublon)

> **Mode hors-ligne** : si le connecteur du réseau est indisponible (réseau, timeout, HTTP 5xx) ou pas encore intégré, la transaction est quand même **acceptée** : `status` vaut alors `pending` et le transfert est mis en file d'attente locale (`pending_transfers`). Il sera exécuté automatiquement dès qu'un connecteur sera disponible (voir §4.4). Un rejet métier 4xx reste un échec immédiat (`failed`).

### 4.3 Suivi

#### `GET /transfers/{transaction_id}`
**Sortie `200`**
```json
{
  "id": "uuid", "reference": "TXN-20260704-A1B2C3", "amount": 5000,
  "fee_amount": 75, "total_debited": 5075, "status": "succeeded",
  "rail_used": "aggregator", "recipient_phone": "+22941000000",
  "recipient_operator": "MOOV", "initiated_at": "...", "completed_at": "..."
}
```

#### `GET /transfers?status=succeeded&page=1&size=20&sort=-initiated_at`
Historique paginé de l'utilisateur connecté.

#### `POST /transfers/{transaction_id}/cancel`
Autorisé uniquement si `status` = `initiated` ou `pending` (pas encore irrévocable). Sortie `200` avec statut `cancelled`, ou `409 TRANSACTION_NOT_CANCELLABLE`.

---

### 4.4 Mode hors-ligne — file d'attente des transferts différés (outbox)

Pour qu'un transfert fonctionne **même sans connexion avec l'agrégateur** :

1. `POST /transfers` enregistre toujours la transaction localement en `pending` avant tout appel externe.
2. Si un connecteur est configuré et joignable → statut `processing`, envoi immédiat (comportement habituel).
3. Si le connecteur est injoignable (réseau, timeout, HTTP 5xx, 429, clés manquantes) ou pas encore intégré → la transaction reste `pending` et une entrée est créée dans `pending_transfers` (outbox).
4. Un rejet métier définitif (4xx) → statut `failed` immédiat, aucune file d'attente.

**Exécution différée** : la commande `php artisan transfers:process-pending` (planifiée chaque minute via le scheduler, ou déclenchée automatiquement par `GET /transfers/...`) rejoue les transferts en attente avec **backoff exponentiel** (60 s, 2 min, 4 min… plafonné à 24 h, paramétrable via `config/fripay.php`), jusqu'à `max_attempts` (défaut 10).

**Cas particuliers** :
- Si le webhook du réseau confirme la transaction avant le prochain flush, l'entrée d'outbox est simplement close (`completed`), aucun double envoi.
- Une transaction `pending` peut être annulée par l'utilisateur (`POST /transfers/{id}/cancel`) tant qu'elle n'a pas été envoyée.
- La réponse de `POST /transfers` est `202` avec `status: pending` tant que l'envoi n'a pas abouti ; le client doit suivre via `GET /transfers/{id}`.

---

## 5. Webhooks entrants (connecteurs externes)

Ces routes ne sont **jamais** appelées par un front-end — uniquement par les agrégateurs ou PI-SPI. Elles ne portent pas de JWT, mais une signature vérifiée.

#### `POST /webhooks/aggregator/{provider}`
Reçoit la confirmation de statut d'un agrégateur ou de l'API native d'un opérateur.
- Vérifie `X-Signature` avant tout traitement → `401 INVALID_SIGNATURE` si échec.
- Journalise systématiquement dans `webhook_events`, même en cas d'échec de signature.
- Met à jour `transactions.status` + insère une ligne dans `transaction_status_history`.
- Répond `200` immédiatement après journalisation ; le traitement métier peut être asynchrone (file d'attente) pour ne jamais faire attendre l'émetteur du webhook.

#### `POST /webhooks/pispi`
Même contrat, dédié aux notifications de l'API Business PI-SPI une fois le connecteur actif (voir cahier des charges §6.3.1).

#### `POST /webhooks/mtn` — callback natif MTN MoMo

Reçu par le service Paiements sur l'URL déclarée dans `MTN_MOMO_CALLBACK_URL` (header `X-Callback-Url` envoyé par le connecteur). **Pas de signature HMAC** : MTN MoMo ne signe pas ses notifications, le flux est sécurisé par l'idempotence (le `externalId` est la référence FriPay, `X-Reference-Id` côté MTN est un UUID déterministe dérivé de cette référence).

Payload reçu :

```json
{
  "externalId": "TXN-20260806-AB12CD",
  "financialTransactionId": "281942019",
  "amount": "5000.00",
  "currency": "EUR",
  "status": "SUCCESSFUL",
  "reason": null
}
```

Mapping des statuts : `SUCCESSFUL` → `succeeded`, `FAILED` → `failed`, `PENDING` → `pending`. La transaction est retrouvée via son champ `reference` (qui vaut `externalId`), et `financialTransactionId` est stocké dans `external_reference`.

#### Connecteur MTN MoMo (produit Disbursements)

`app/Services/Connectors/MtnMomoConnector.php` — flux : token OAuth2 (`POST /disbursement/token/`) puis `POST /disbursement/v1_0/transfer`. Idempotence : `X-Reference-Id` = UUID v5 déterministe dérivé de la référence FriPay → un rejeu de l'outbox réutilise le même identifiant (réponse HTTP 409 → statut interrogé au lieu d'un nouveau débit). Erreurs : 4xx → rejet définitif, 429/5xx/réseau → rejouable (file d'attente). Clés : `MTN_MOMO_API_USER`, `MTN_MOMO_API_KEY`, `MTN_MOMO_SUBSCRIPTION_KEY`, `MTN_MOMO_TARGET_ENVIRONMENT` (`sandbox`/`live`), `MTN_MOMO_BASE_URL`, `MTN_MOMO_CALLBACK_URL` (voir `.env.example`).

---

## 6. Routes API — Back-office (Admin)

Base : `/api/v1/admin` — accès réservé aux `staff_users`, RBAC vérifié à chaque route via les `permissions`.

### 6.1 Authentification back-office

#### `POST /admin/auth/login`
**Entrée** : `{ "email": "...", "password": "..." }`
**Sortie `200`** : `{ "access_token": "...", "role": "support", "permissions": ["transactions.read", ...] }`

### 6.2 Utilisateurs

#### `GET /admin/users?search=&status=&page=1&size=20`
#### `GET /admin/users/{user_id}`
#### `PUT /admin/users/{user_id}/status`
**Entrée** : `{ "status": "blocked", "reason": "Suspicion de fraude" }`
**Effet** : met à jour `users.status`, écrit une entrée `audit_logs`. **Permission requise** : `users.block`.

### 6.3 Transactions

#### `GET /admin/transactions?status=&operator=&date_from=&date_to=&page=1&size=20`
#### `GET /admin/transactions/{transaction_id}`
Retourne la transaction **et** son historique complet (`transaction_status_history`).

#### `POST /admin/transactions/{transaction_id}/retry`
Relance manuellement un transfert resté en échec technique (ex. timeout agrégateur). **Permission requise** : `transactions.retry`.

### 6.4 Corridors (pilotage du routage)

#### `GET /admin/corridors`
#### `POST /admin/corridors` — création d'une règle de routage
#### `PUT /admin/corridors/{corridor_id}` — activer/désactiver, changer priorité ou frais
**Entrée type**
```json
{
  "source_operator": "MTN", "destination_operator": "CELTIIS",
  "rail": "aggregator", "aggregator_provider": "fedapay",
  "priority": 1, "fee_type": "percentage", "fee_value": 1.5,
  "min_amount": 100, "max_amount": 500000, "active": true
}
```
**Permission requise** : `corridors.write`. Toute modification est tracée dans `audit_logs`.

### 6.5 Tableau de bord

#### `GET /admin/dashboard/kpis?period=7d`
**Sortie `200`**
```json
{
  "success_rate": 0.978,
  "total_volume_xof": 12450000,
  "transactions_count": 842,
  "avg_delivery_seconds": 34,
  "by_rail": { "pispi": 0, "aggregator": 842 }
}
```

### 6.6 Gestion du personnel

#### `GET /admin/staff`, `POST /admin/staff`, `PUT /admin/staff/{id}/role`
Réservé au rôle `admin`.

---

## 7. Catalogue d'erreurs communes

| `type` | HTTP | Signification |
|---|---|---|
| `INVALID_PHONE_FORMAT` | 422 | Numéro non conforme E.164 |
| `PHONE_ALREADY_REGISTERED` | 409 | Compte déjà existant |
| `OTP_INVALID` / `OTP_EXPIRED` | 400 / 410 | Code de vérification incorrect ou expiré |
| `TOO_MANY_ATTEMPTS` | 429 | Limite de tentatives OTP/PIN atteinte |
| `INVALID_CREDENTIALS` | 401 | Numéro/PIN incorrect |
| `ACCOUNT_BLOCKED` | 423 | Compte bloqué par le back-office ou pour fraude |
| `OPERATOR_NOT_SUPPORTED` | 422 | Préfixe non reconnu parmi MTN/Moov/Celtiis |
| `NO_ROUTE_AVAILABLE` | 422 | Aucun corridor actif pour cette paire d'opérateurs |
| `AMOUNT_OUT_OF_RANGE` | 422 | Montant hors bornes `min_amount` / `max_amount` du corridor |
| `QUOTE_EXPIRED` | 410 | Le `quote_token` a dépassé sa durée de validité (2 min) |
| `INSUFFICIENT_FUNDS` | 422 | Solde insuffisant sur le compte source |
| `DUPLICATE_IDEMPOTENCY_KEY` | 409 | Requête déjà traitée — transaction existante renvoyée |
| `TRANSACTION_NOT_CANCELLABLE` | 409 | Transaction déjà irrévocable |
| `INVALID_SIGNATURE` | 401 | Webhook non authentifié |
| `INSUFFICIENT_PERMISSIONS` | 403 | Rôle back-office ne couvrant pas l'action demandée |

---

## 8. Ce qui reste à trancher avant le développement

- Choix définitif du ou des agrégateurs à intégrer en premier (impacte les valeurs possibles de `aggregator_provider` et le détail exact des webhooks §5).
- Politique précise de limites de tentatives PIN/OTP (nombre exact, durée de blocage).
- Durée de vie exacte des tokens JWT et des `quote_token` (valeurs proposées ci-dessus à valider en équipe).
- Format exact du `reference` humainement lisible des transactions (proposition : `TXN-YYYYMMDD-<6 caractères alphanumériques>`).
