# 🔍 Rapport d'Audit Complet — API FriPay

**Date** : 18 août 2026  
**Scope** : Microservice `fripay-payments` (QR Codes hors-ligne + Connecteurs)  
**Sévérités** : 🔴 CRITIQUE | 🟠 ÉLEVÉE | 🟡 MOYENNE | 🟢 BASSE

---

## 📊 Résumé Exécutif

| Catégorie | 🔴 Critique | 🟠 Élevée | 🟡 Moyenne | 🟢 Basse | Total |
|---|---|---|---|---|---|
| Sécurité | 1 | 3 | 2 | 1 | 7 |
| Code / Syntaxe | 3 | 1 | 1 | 0 | 5 |
| Architecture | 0 | 2 | 2 | 1 | 5 |
| Base de données | 0 | 1 | 1 | 0 | 2 |
| **Total** | **4** | **7** | **6** | **2** | **19** |

---

## 🔴 PROBLÈMES CRITIQUES (4)

### 🔴 C1 — MoovMoneyConnector : Code syntaxiquement invalide

**Fichier** : `app/Services/Connectors/MoovMoneyConnector.php`

Le connecteur contient des erreurs de syntaxe PHP qui empêchent son chargement :

```php
// ERREUR : syntaxe invalide — $this manquant
->config("username")    // ✗  devrait être $this->config("username")
->config("password")    // ✗
->config("encryption_key") // ✗

// ERREUR : variables non déclarées
$token = ->generateToken()();  // ✗ syntaxe impossible

// ERREUR : chaîne de caractères non fermée
return config(fripay.moov_money.' . $key); // ✗ guillemets manquants

// ERREUR : bloc de code orphelin (hors de toute méthode)
return (string) $response->json('access_token');
});  // ← Ce bloc est hors de toute classe
```

**Impact** : Le connecteur Moov Money est **totalement inutilisable**. Toute tentative de chargement provoque une erreur fatale PHP.  
**Correction** : Réécrire le connecteur à partir du MtnMomoConnector en adaptant l'API Moov Africa (SOAP/OAuth2).

---

### 🔴 C2 — CeltiisConnector : Code syntaxiquement invalide

**Fichier** : `app/Services/Connectors/CeltiisConnector.php`

Mêmes erreurs que MoovMoneyConnector :

```php
// ERREUR : $this manquant
->config("master_key")  // ✗
->config("private_key") // ✗
->config("token")       // ✗

// ERREUR : chaîne non fermée
return config(fripay.celtiis.' . $key); // ✗

// ERREUR : bloc orphelin
return (string) $response->json('access_token');
});  // ← hors de toute classe
```

**Impact** : Le connecteur Celtiis est **inutilisable**.  
**Correction** : Réécrire en adaptant l'API PayDunya Disbursement.

---

### 🔴 C3 — ReconcileOfflineQr : Détection de double dépense cassée

**Fichier** : `app/Console/Commands/ReconcileOfflineQr.php`

```sql
-- La requête GROUP BY uuid est inutile car uuid est UNIQUE
SELECT uuid, COUNT(*) as reception_count 
FROM offline_qr_codes 
WHERE status IN ('received', 'redeemed')
GROUP BY uuid 
HAVING reception_count > 1  -- ← JAMAIS vrai (uuid est unique)
```

**Impact** : La détection de double dépense **ne fonctionne jamais**. Le compteur `reception_count` sera toujours 1 car `uuid` a une contrainte `UNIQUE`.  
**Correction** : La détection doit se faire au niveau des **événements** (`offline_qr_events`), pas des QR codes, ou en comptant les réceptions distinctes par QR code dans les events.

---

### 🔴 C4 — bootstrap/app.php : Import d'un middleware inexistant

**Fichier** : `bootstrap/app.php`

```php
use App\Http\Middleware\IdempotencyMiddleware; // ← N'existe pas
```

Le fichier `app/Http/Middleware/IdempotencyMiddleware.php` n'existe pas. L'import génère une erreur fatale au chargement de l'application.

**Impact** : L'application peut ne pas démarrer en production.  
**Correction** : Soit créer le middleware, soit retirer l'import et l'alias.

---

## 🟠 PROBLÈMES ÉLEVÉS (7)

### 🟠 E1 — Aucun rate limiting sur les endpoints QR

Les 7 endpoints QR n'ont **aucun middleware de rate limiting**. Le endpoint `/qr/verify` est **public** (sans auth).

```php
// Routes actuelles — pas de throttle
Route::post('/qr/generate', ...);  // Auth seulement
Route::post('/qr/verify', ...);    // PUBLIC, aucun throttle
```

**Risques** :
- Brute-force de la vérification de QR codes
- Déni de service (DDoS) sur le endpoint verify
- Abus de génération de QR codes (coût crypto)

**Correction** : Ajouter `throttle:60,1` (60 req/min) sur tous les endpoints QR, et `throttle:20,1` sur `/qr/verify`.

---

### 🟠 E2 — Race condition dans receive/redeem

Le controller `receive()` et `redeem()` n'utilisent pas de locking pessimiste :

```php
// receive() — pas de lock ForUpdate
$qrCode = OfflineQrCode::where('uuid', $uuid)->first();
// Deux requêtes simultanées peuvent toutes deux lire status=active
// puis toutes deux update vers received
```

**Risque** : Double réception du même QR Code en cas de requêtes simultanées.  
**Correction** : Ajouter `->lockForUpdate()` dans la transaction, ou utiliser une contrainte d'unicité sur `(uuid, recipient_user_id)`.

---

### 🟠 E3 — Pas de validation du format recipient_phone

```php
'recipient_phone' => 'required|string',  // ← n'importe quelle string
```

Un attaquant peut envoyer n'importe quel format (SQL injection, XSS, etc.).  
**Correction** : Valider avec `required|string|min:10|max:15|regex:/^\+[0-9]+$/`.

---

### 🟠 E4 — Webhooks sans vérification HMAC

```php
Route::post('/webhooks/aggregator/{provider}', ...);
Route::post('/webhooks/mtn', ...);
Route::post('/webhooks/pispi', ...);
```

Aucune vérification de signature HMAC sur les webhooks. Un attaquant peut envoyer de faux callbacks.  
**Correction** : Vérifier la signature HMAC avec la clé partagée MTN/Moov/PayDunya.

---

### 🟠 E5 — QR Code status endpoint sans auth

```php
Route::get('/qr/{uuid}/status', ...); // → middleware auth:sanctum ✓
```

Ce endpoint est protégé par auth, mais le status d'un QR Code (montant, statut) est accessible à **n'importe quel utilisateur authentifié**, pas seulement l'expéditeur ou le récepteur.  
**Correction** : Vérifier que l'utilisateur est l'expéditeur ou le récepteur avant de retourner les détails.

---

### 🟠 E6 — Unused imports dans QrCryptoService

```php
use App\Models\OfflineQrCode;   // ← jamais utilisé
use Illuminate\Support\Facades\Crypt; // ← jamais utilisé
```

**Impact** : Charge inutilement l'autoloader, signal de code mort.  
**Correction** : Supprimer les imports inutilisés.

---

### 🟠 E7 — Pas de CORS configuré

Aucune configuration CORS n'est visible. Les appels cross-origin (mobile app, SPA externe) seront bloqués par le navigateur.  
**Correction** : Configurer le middleware CORS pour autoriser les origines légitimes.

---

## 🟡 PROBLÈMES MOYENS (6)

### 🟡 M1 — Pas de logging des opérations QR échouées

Les erreurs de génération, vérification, ou synchronisation ne sont pas loggées. En cas de problème en production, impossible de diagnostiquer.  
**Correction** : Ajouter `Log::warning()` sur les erreurs critiques (signature invalide, double dépense, sync échouée).

---

### 🟡 M2 — Pas de validation côté serveur de l'expiration

Le `expires_at` est défini par le client (`expires_minutes`). Un client malveillant peut définir une expiration très lointaine (ex: 1440 minutes = 24h).  
**Correction** : Limiter à 60 minutes max en production, ou ajouter une config `max_qr_lifetime`.

---

### 🟡 M3 — La clé privée n'est jamais stockée côté serveur

Le `QrCryptoService` génère une clé Ed25519 mais ne la stocke pas côté serveur. La clé privée est **uniquement** dans le QR code signé. C'est intentionnel (sécurité), mais cela signifie qu'on ne peut **jamais** vérifier côté serveur qu'un QR Code a bien été signé par un utilisateur spécifique.

**Impact** : Un utilisateur peut générer un QR Code avec une clé arbitraire.  
**Correction** : Lier les clés publiques aux comptes utilisateurs (table `user_keys`).

---

### 🟡 M4 — Pas de purge automatique des QR expirés en base

Le scheduler `reconcile:offline-qr` expire les QR codes, mais ne les **supprime** pas. La table `offline_qr_codes` croîtra indéfiniment.  
**Correction** : Ajouter une purge des QR codes expirés depuis > 30 jours.

---

### 🟡 M5 — La méthode `scopeActive` n'est pas utilisée

Le model `OfflineQrCode` définit un scope `scopeActive()` mais il n'est jamais appelé dans le controller.  
**Correction** : Utiliser le scope ou le supprimer.

---

### 🟡 M6 — Le modal de transfert ne valide pas le numéro

Le `transferPhone` est utilisé tel quel sans validation format.  
**Correction** : Valider côté client avec un pattern regex avant l'envoi.

---

## 🟢 PROBLÈMES BAS (2)

### 🟢 B1 — Pas de tests unitaires

Aucun test PHPUnit n'existe pour le `QrCryptoService`, le `OfflineQrController`, ou les connecteurs.  
**Correction** : Créer des tests pour les cas critiques (signature, vérification, double dépense).

---

### 🟢 B2 — Le widget Blade utilise des CDN non versionnés

```html
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
```

Les versions `3.x.x` et le CDN Tailwind sont non versionnés, ce qui peut casser l'app en cas de mise à jour.  
**Correction** : Pinner les versions (ex: `alpinejs@3.14.8`).

---

## ✅ Points Forts

| Aspect | Évaluation |
|---|---|
| **Architecture microservices** | ✅ Bien séparée (users, payments, admin, gateway) |
| **Cryptographie** | ✅ Ed25519 via Sodium (rapide, cross-platform) |
| **Idempotence des transferts** | ✅ UUID v5 déterministe (pas de double débit) |
| **Mode hors-ligne** | ✅ Service Worker + IndexedDB + Background Sync |
| **Outbox pattern** | ✅ Backoff exponentiel pour les transferts différés |
| **API Documentation** | ✅ Swagger/OpenAPI via Scramble |
| **Cycle de vie QR** | ✅ 5 statuts bien définis (active→received→redeemed/revoked/expired) |
| **Event sourcing** | ✅ Table `offline_qr_events` pour audit trail |
| **Reconciliation** | ✅ Commande Artisan (même si la détection est cassée) |
| **Nettoyage mémoire** | ✅ `sodium_memzero()` pour les clés sensibles |

---

## 📋 Plan d'Actions Prioritaire

| Priorité | Action | Effort |
|---|---|---|
| 🔴 P0 | Corriger MoovMoneyConnector (réécriture complète) | 2h |
| 🔴 P0 | Corriger CeltiisConnector (réécriture complète) | 2h |
| 🔴 P0 | Corriger la détection double dépense | 1h |
| 🔴 P0 | Supprimer/créer IdempotencyMiddleware | 30min |
| 🟠 P1 | Ajouter rate limiting sur les routes QR | 30min |
| 🟠 P1 | Ajouter locking dans receive/redeem | 1h |
| 🟠 P1 | Valider le format recipient_phone | 15min |
| 🟠 P1 | Ajouter vérification HMAC sur webhooks | 2h |
| 🟠 P1 | Restreindre l'accès au status endpoint | 30min |
| 🟠 P1 | Configurer CORS | 30min |
| 🟡 P2 | Ajouter le logging des erreurs | 1h |
| 🟡 P2 | Limiter expires_minutes en production | 15min |
| 🟡 P2 | Purge automatique des QR expirés | 1h |
| 🟢 P3 | Créer des tests PHPUnit | 4h |
| 🟢 P3 | Pinner les versions CDN | 15min |

---

*Rapport généré le 18 août 2026 — API FriPay v1.0.0*
