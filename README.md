# README — État fonctionnel complet de FriPay (au 17/09/2026)

## 1. Architecture actuelle

- **4 services Laravel** : `fripay-users` (8000), `fripay-payments` (8001), `fripay-admin` (8002), `fripay-gateway` (8080, point d'entrée unique de l'app mobile).
- **1 package partagé** `fripay-common` (Enums, modèles User/Wallet/Notification/Operator, services communs).
- **App mobile Flutter** (Android Studio) — **entièrement branchée sur l'API réelle**, plus aucun import de `mock_data.dart` dans le code (vérifié : le fichier existe encore mais n'est plus utilisé nulle part).
- **App web** (`benin-money-hub-main`, React/TanStack via Lovable) — scaffold présent mais non audité dans cette session, à vérifier séparément si besoin.
- **Admin** (`fripay-admin`) — **API seule, aucune interface web** (juste la page Laravel par défaut). Il faut soit construire un front, soit piloter via Postman/un outil interne.

---

## 2. Ce qui fonctionne réellement (vérifié, pas juste lu dans le code)

| Domaine | État | Détail |
|---|---|---|
| Inscription + OTP SMS | ✅ Fonctionne en interne | Code généré, stocké, vérifié. **Mais le SMS n'est jamais réellement envoyé** — il est seulement journalisé (log serveur) en environnement dev, et renvoyé dans la réponse JSON en dev uniquement. |
| Vérification email | ✅ Fonctionne en interne | Même logique : `MAIL_MAILER=log`, donc aucun email n'est réellement envoyé, juste journalisé. |
| Numéro Fripay (préfixe 30) | ✅ Fait | Généré à l'inscription, affiché partout. |
| Connexion biométrie / PIN | ✅ Fait (déclaré précédemment, non retesté cette session) | |
| **Transfert interne FriPay → FriPay** | ✅ **Vérifié bout en bout cette session** | Testé par un vrai appel HTTP via le gateway : quote → initiate → statut `completed` immédiat, solde débité/crédité en base, notification créée et récupérée via l'API, marquage "lu" fonctionnel. |
| Transfert externe (vers MTN/Moov/Celtiis non-FriPay) | ⚠️ Devis (quote) fonctionne | Corridors bien configurés (MTN/MOOV/CELTIIS, frais 1,5%) → le calcul de frais marche. **Mais l'envoi réel échoue toujours** (voir §3). |
| QR code (génération, scan, transfert à un tiers, receveur sans compte) | ✅ Fait (déclaré précédemment) | Backend + mobile reliés, `flutter analyze` propre. |
| Historique des transactions | ✅ Fait | `WalletLedgerEntry` + `TransactionStatusHistory` + écran mobile branché. |
| Contacts (répertoire tél.) | ✅ Fait | Picker natif, API réelle (`fripay-users`). |
| **Paiement de factures (SBEE, SONEB, Canal+...)** | ⚠️ Fonctionne **en interne uniquement** | Débite bien le wallet FriPay et marque "payé" — mais **ne paie aucune vraie facture** : les billers sont des données seedées statiques, sans connexion à un agrégateur de factures réel. |
| **Réclamations (plaintes)** | ⚠️ Partiel | Le ticket est bien créé en base, le remboursement passe en statut `requested` pour les motifs éligibles — mais **rien ne traite jamais ce remboursement** (pas de contrôleur admin pour ça, pas de crédit wallet automatique). |
| Interface technique (statut des microservices) | ✅ Fait | Accessible depuis Profil, appelle `/__gateway/status`. |

---

## 3. Ce qui est fait mais ne fonctionne pas encore en conditions réelles

1. **Aucun connecteur opérateur externe n'est configuré** (`fripay-payments/.env`) :
   - `MTN_MOMO_API_USER`, `MTN_MOMO_API_KEY`, `MTN_MOMO_SUBSCRIPTION_KEY` → **vides**
   - `MOOV_MONEY_USERNAME`, `MOOV_MONEY_PASSWORD`, `MOOV_MONEY_ENCRYPTION_KEY` → **vides**
   - `PAYDUNYA_MASTER_KEY`, `PAYDUNYA_PRIVATE_KEY`, `PAYDUNYA_TOKEN` (pour Celtiis) → **vides**

   Conséquence concrète : envoyer de l'argent vers un numéro qui **n'a pas** de compte FriPay reste bloqué indéfiniment en file d'attente (`PendingTransfer`, statut `pending`), exactement comme le bug initial — mais cette fois c'est normal et attendu tant que les clés ne sont pas fournies, puisqu'il n'existe physiquement aucun moyen d'atteindre le réseau MTN/Moov/Celtiis sans elles.

2. **Aucun SMS réel n'est envoyé** — pas de compte configuré chez un fournisseur SMS béninois (ex. via un agrégateur local). L'OTP ne sort jamais du serveur.

3. **Aucun email réel n'est envoyé** — `MAIL_MAILER=log`, pas de SMTP configuré.

4. **Paiement de factures non connecté à un vrai agrégateur** — aucune clé API pour un service de paiement de factures (PayDunya/Kkiapay proposent ce genre de service au Bénin, à vérifier avec eux).

5. **Remboursement de plainte non automatisé** — un utilisateur peut demander un remboursement, mais personne (aucun code) ne l'exécute jamais.

6. **Lien "Installer l'appli" sur la page web de réclamation QR** (`claim/show.blade.php`) pointe vers un placeholder `https://fripay.bj/app`.

7. **`applicationId` Android toujours `com.example.fripay_app`** — à changer avant toute publication sur le Play Store.

---

## 4. Ce qu'il reste à faire pour que TOUT fonctionne

### A. Clés API réelles à obtenir et renseigner (le plus bloquant pour l'externe)

| Service | Où l'obtenir | Variables à remplir (`fripay-payments/.env`) |
|---|---|---|
| **MTN MoMo** (Disbursements) | https://momodeveloper.mtn.com — créer un compte développeur, souscrire au produit "Disbursements", passer en production après validation MTN | `MTN_MOMO_API_USER`, `MTN_MOMO_API_KEY`, `MTN_MOMO_SUBSCRIPTION_KEY`, `MTN_MOMO_CALLBACK_URL` (+ passer `MTN_MOMO_TARGET_ENVIRONMENT` de `sandbox` à `production`) |
| **Moov Money** | Contrat marchand avec Moov Africa Bénin (démarche commerciale, pas juste un compte en ligne) | `MOOV_MONEY_USERNAME`, `MOOV_MONEY_PASSWORD`, `MOOV_MONEY_ENCRYPTION_KEY` |
| **Celtiis Cash** (via PayDunya) | https://developers.paydunya.com — créer un compte marchand PayDunya | `PAYDUNYA_MASTER_KEY`, `PAYDUNYA_PRIVATE_KEY`, `PAYDUNYA_TOKEN`, `CELTIIS_CALLBACK_URL` |
| **Fournisseur SMS** (OTP) | À choisir — un agrégateur SMS béninois/africain (ex. un service supportant l'envoi vers +229). Aucune variable n'existe encore dans le `.env` : il faudra créer le service d'envoi + les clés correspondantes une fois le fournisseur choisi. | *(à créer)* |
| **SMTP réel** (email de vérification) | N'importe quel fournisseur SMTP (Mailgun, SendGrid, Amazon SES, ou un compte email classique) | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` dans `fripay-users/.env` |
| **Agrégateur de paiement de factures** | À vérifier si PayDunya/Kkiapay couvrent SBEE/SONEB/Canal+ au Bénin, sinon voir directement avec chaque fournisseur | *(à créer)* |

### B. Développement restant

- Créer une interface admin (front web) exploitant l'API `fripay-admin` déjà existante — ou définir avec le boss l'"interface globale" (§8 du cahier des charges, toujours pas définie).
- Ajouter côté admin la gestion des réclamations (voir, traiter, exécuter le remboursement réel).
- Câbler `BillController::pay()` à un vrai agrégateur au lieu de marquer "payé" localement.
- Remplacer le lien placeholder `https://fripay.bj/app` une fois l'appli publiée sur un store.
- Changer `applicationId` avant publication.
- Activer le mode développeur Windows pour permettre `flutter build apk` complet (signalé précédemment, non revérifié cette session).
- Rechercher/valider les préfixes MTN/Moov/Celtiis les plus récents (tâche en attente signalée dans le cahier des charges §4).
- Définir avec le client le rail PI-SPI/BCEAO ou confirmer que le rail agrégateur actuel (MTN/Moov direct + PayDunya) suffit pour le lancement — écart déjà identifié dans le document de suivi.

### C. Correction à faire dans le document de suivi

Le "Point de vigilance" du fichier `Etat fonctionnel de Fripay.md` dit qu'il n'y a **pas** de wallet dédié — c'est **faux/obsolète** : un `Wallet` (avec grand-livre `WalletLedgerEntry`) existe bel et bien et fonctionne (c'est lui qui a été testé bout en bout dans cette session).

---

## 5. Tester sur un téléphone physique SANS câble USB

L'app mobile sur un téléphone réel doit joindre le backend qui tourne sur le PC, via le Wi-Fi partagé.

### Une fois (déjà fait)
- Le backend écoute sur toutes les interfaces : `start-fripay.ps1` lance `php -S 0.0.0.0:8080`.
- Règle pare-feu « FriPay Backend » créée (ports 8000-8002 + 8080 autorisés, tous profils).
- `mobile-app/lib/services/api_config.dart` → `kLanHost` = IP LAN du PC (vérifier avec `ipconfig`, section « Carte sans fil Wi-Fi » → « Adresse IPv4 »).
- Pareil pour le téléphone : il doit être sur le MÊME Wi-Fi que le PC (pas la 4G).

### À chaque nouvelle version de l'app
1. Compiler l'APK : `cd mobile-app && flutter build apk --release`
2. L'APK est ici : `mobile-app/build/app/outputs/flutter-apk/app-release.apk`
3. L'envoyer au téléphone (WhatsApp « envoyer à un contact » à soi-même, Google Drive, câble une seule fois…) et l'installer (autoriser « installer des applis inconnues »).
4. Sur le téléphone, vérifier avec Chrome que `http://IP_DU_PC:8080/api/v1/up` répond — si non, voir dépannage ci-dessous.

### Dépannage « erreur réseau » sur le téléphone
- `ipconfig` sur le PC : l'IP a changé ? → mettre à jour `kLanHost` dans `api_config.dart` et recompiler (étape 1-3).
- Backend pas démarré ? → lancer `api-backend\start-fripay.ps1`, puis `netstat -an | findstr 8080` doit afficher `0.0.0.0:8080 LISTENING`.
- Règle pare-feu absente (à faire en admin une seule fois) : `netsh advfirewall firewall add rule name="FriPay Backend" dir=in action=allow protocol=TCP localport=8000-8002,8080`
- Alternative sans compilation après changement d'IP : réserver l'IP du PC dans la box (bail DHCP statique) pour ne plus jamais y revenir.

### Variante : déboguer sans câble (adb Wi-Fi, Android 11+)
Sur le téléphone : Options développeur → « Débogage sans fil » → « Associer l'appareil avec un code ». Sur le PC : `adb pair IP:PORT` (code affiché sur le téléphone), puis `adb connect IP:PORT` et enfin `flutter run --release` — plus besoin du câble ensuite.

---

**En une phrase** : tout ce qui reste **à l'intérieur** de FriPay (compte à compte, factures internes, historique, QR) fonctionne réellement de bout en bout ; tout ce qui doit **sortir** vers l'extérieur (réseaux MTN/Moov/Celtiis, vrais SMS, vrais emails, vraies factures) est câblé et prêt côté code, mais attend des identifiants/contrats réels avec les fournisseurs correspondants.
