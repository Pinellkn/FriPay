# État fonctionnel de Fripay — Cahier des charges (synthèse)

> Document de synthèse regroupant toutes les consignes fonctionnelles données par le boss/client pour l'application Fripay. À tenir à jour à chaque nouvelle précision.

## 1. Identité — le numéro Fripay ✅ Fait (généré à l'inscription, affiché accueil + portefeuille)
- ID unique par utilisateur : **10 chiffres**, préfixe **30** + suite aléatoire.
- Généré et attribué **automatiquement** à l'inscription.
- Affiché en permanence : accueil (au niveau du solde), portefeuille, et partout où nécessaire.
- Lié directement au portefeuille de l'utilisateur.

## 2. Inscription ✅ Fait
- Champs classiques + **email obligatoire** ✅ Fait.
- Confirmation du compte par un **code de confirmation** (6 chiffres, envoyé par email, 15 min de validité, 5 tentatives max) ✅ Fait — backend : migrations `email_verified_at` + `email_verification_codes`, `EmailVerificationService`, endpoints `POST /auth/verify-email` et `POST /auth/resend-email-verification`. Côté app : nouvelle étape après l'OTP SMS dans `login_screen.dart` (`_emailStep`), `AuthService.verifyEmail()` / `resendEmailVerification()`. `flutter analyze` : 0 problème.
- Choix de l'opérateur mobile (MTN, Moov ou Celtiis).
- Champ téléphone :
  - Indicatif **+229** pré-rempli, non modifiable par l'utilisateur.
  - L'utilisateur doit saisir **01** en premier → sinon message d'erreur automatique.
  - Puis le préfixe propre à l'opérateur choisi → si incohérent avec l'opérateur sélectionné, message d'erreur automatique.
  - Format final : `229 01 XX XX XX XX`.

## 3. Connexion
Deux niveaux d'options, avec adaptation selon la plateforme (iOS vs Android) :

| Plateforme | Biométrie affichée |
|---|---|
| iPhone (iOS) | **Face ID** uniquement |
| Android / autres smartphones | **Empreinte digitale** uniquement |

- **Méthode principale** : biométrie (Face ID ou empreinte, selon l'appareil), visible seulement si l'utilisateur l'a activée dans les paramètres. Jamais l'option de l'autre plateforme proposée par erreur.
- **Méthodes alternatives** (avec code PIN à chaque fois) :
  - Numéro Fripay + PIN
  - Numéro d'opérateur (celui utilisé à l'inscription) + PIN

## 4. Réseaux et préfixes ✅ Fait (module phone_prefixes + OperatorDetectionService côté backend, miroir Dart côté app, ARCEP)
- 4 catégories de numéros au total : Fripay (préfixe 30), MTN, Moov, Celtiis (chacun `01` + préfixe propre à l'opérateur).
- Les numéros de chaque réseau doivent rester clairement distincts entre eux.
- **Module dédié aux préfixes** à créer (gestion centralisée).
- **Tâche en attente** : rechercher et intégrer la mise à jour la plus récente des préfixes MTN/Moov/Celtiis au Bénin.

## 5. Modifications d'interface ✅ Fait
- « Envoyer » : retirer la fonction de scan (jugée inutile). ✅ Fait
- **Mode hors ligne : supprimé** (décision finale, ne pas l'implémenter). ✅ Fait
- « Dépôt » renommé en **« Recharge »**. ✅ Fait
- Ajout de contact (dans « Envoyer de l'argent ») : accès direct au répertoire téléphonique de l'appareil (numéro avec 01 obligatoire). ✅ Fait — picker natif `flutter_contacts` (FlutterContacts.openExternalPick) dans add_contact_sheet.dart, permissions READ_CONTACTS (Android) et NSContactsUsageDescription (iOS) ajoutées, nom+numéro pré-remplis et modifiables avant envoi, flutter analyze 0 problème.

## 6. QR code — le cœur du système d'envoi ✅ Fait (mobile + backend)

> **Toutes les briques du §6 sont faites et reliées entre elles.** Backend : `OfflineQrController` (generate/receive/redeem/transfer/revoke/status/mine) + `ExternalClaimController` (parcours receveur sans compte). Mobile : `send_qr_screen.dart` (§6.a génération + code à 5 chiffres si receveur sans compte), `receive_qr_screen.dart` (§6.b encaisser, §6.c transmettre à un tiers), `qr_scan_screen.dart` (§6.d scan caméra + upload galerie, partagé par les deux écrans). **Point corrigé cette session** : ces deux écrans existaient mais n'étaient reliés à aucun bouton dans l'appli — ajout d'une action QR dans l'AppBar de « Envoyer » (→ `SendQrScreen`) et de « Recevoir » (→ `ReceiveQrScreen`). `flutter analyze` : 0 problème.
>
> Reste ouvert : lien « Installer l'appli » sur la page web publique (`resources/views/claim/show.blade.php`) pointe vers un placeholder `https://fripay.bj/app` — à remplacer par le vrai lien store quand disponible.

### a) Génération et contenu
L'envoyeur génère un code de validation (anciennement « code de vérification »), puis le QR code. Le QR final contient :
- Code de validation
- Montant
- Numéro du receveur
- Numéro Fripay du receveur (si compte existant)

### b) Retrait non immédiat
Le QR est unique et reste valable tant qu'il n'est pas réclamé. Le receveur peut retirer l'argent quand il veut — pas de délai imposé (1 mois, 5 mois, etc.).

### c) Transfert du QR à un tiers
Le receveur peut, au lieu de retirer, modifier le numéro destinataire sur le QR pour le transmettre à une autre personne. Il devient alors lui-même « envoyeur », et le QR est redirigé vers ce nouveau destinataire.

### d) Parcours général
Génération → Envoi → Réception (scan ou upload via bouton « Uploader ») → Stockage.

### e) Cas particulier : receveur sans compte Fripay
1. L'envoyeur saisit le numéro du receveur → l'appli détecte automatiquement si ce numéro a un compte Fripay.
2. Si non : un champ **code de validation** est ajouté au QR, **généré automatiquement par l'appli** (défini manuellement par l'envoyeur avec pour exigence de champ de code numérique à 5 chiffres).
3. L'envoyeur transmet ce code hors appli (WhatsApp, etc.) au receveur.
4. Le receveur scanne avec un scanner externe (il n'a pas Fripay) → redirigé vers une **page web** de l'appli.
5. Cette page propose : installer Fripay, **ou** saisir le numéro (n'importe quel réseau) où recevoir l'argent.
6. Le receveur saisit ensuite le code de validation.
7. Code correct → retrait effectué vers le numéro saisi.
8. **3 tentatives maximum**. Après le 3ᵉ échec : transaction annulée, argent retransféré vers le compte de l'envoyeur, notification d'échec envoyée à l'envoyeur par l'appli.

## 7. Suivi des transactions ✅ Fait
Toute opération (transfert, génération/réclamation de QR, retrait, échec) doit être **tracée et consultable** — historique complet requis.
- Backend déjà en place : grand-livre `WalletLedgerEntry` (chaque mouvement de solde, y compris échecs/remboursements), `TransactionStatusHistory` (traçabilité fine des transferts), `OfflineQrEvent` (traçabilité des QR). Exposé via `GET /wallet/transactions`.
- Mobile : `history_screen.dart` branché sur `WalletService.listTransactions()` — historique unifié (transferts, QR, factures, recharges/retraits) avec icône/libellé par type d'opération. `flutter analyze` : 1 seul avertissement pré-existant sans rapport (auth_service.dart), 0 erreur.

## 8. Interfaces à prévoir (consigne du boss)
Chaque application doit disposer de deux interfaces séparées :
- **Interface technique** ✅ Fait — nouvel écran `technical_interface_screen.dart` (accessible depuis Profil), affichant : statut de chaque microservice (via `/__gateway/status` du gateway, protégé par une clé partagée `FRIPAY_GATEWAY_DEBUG_KEY`) et la liste des préfixes réseau par opérateur (nouveau endpoint `GET /network/prefixes`, fripay-payments).
- **Interface globale** — contenu encore à définir par le boss ; rien codé volontairement tant que la définition n'est pas précisée.

---

## Point de vigilance (écart déjà identifié)
Le cahier des charges officiel prévoyait Vue.js + Node.js + PostgreSQL + rail PI-SPI (BCEAO) + entités Wallet/AuditLog. Le réalisé actuel : Flutter + Laravel + MySQL + QR signés Ed25519, sans wallet dédié ni rail PI-SPI — écart stratégique toujours à trancher avec le client, à mettre en perspective avec toutes ces nouvelles specs QR/biométrie qui ajoutent encore de la complexité au système de transfert.
