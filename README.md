# FriPay

Plateforme de paiement mobile (Bénin, XOF) — QR codes offline P2P et marchand (CPM/MPM), connecteurs Mobile Money.

## Structure du monorepo

- **`api-backend/`** — API FriPay (microservices Laravel)
  - `fripay-users` — gestion des comptes utilisateurs
  - `fripay-payments` — moteur de paiement, QR offline
  - `fripay-admin` — back-office
  - `fripay-gateway` — passerelle / routage
  - `packages/fripay-common` — code partagé
- **`mobile-app/`** — Application mobile FriPay (Flutter/Dart)

## Notes

- Les fichiers `.env` et les dumps de base de données ne sont pas versionnés (voir `.gitignore`).
- Se référer aux `README.md` et `.env.example` de chaque service pour la configuration locale.
