import 'dart:convert';

/// Routage intelligent des QR scannés — coeur de la « zone de scan »
/// centrale. Un seul point d'entrée (ScanHubScreen) pour tous les scans de
/// l'app ; chaque écran spécifique (réception d'argent, paiement marchand)
/// reste accessible et continue d'utiliser [QrScanScreen] directement.
///
/// Deux familles de QR coexistent (même table `offline_qr_codes` côté API) :
/// - QR marchand (MPM)   : le marchand l'affiche, le client paie (POST /qr/mpm/pay).
/// - QR « argent » (CPM) : montant réservé par l'envoyeur, réclamable
///   (POST /qr/receive puis redeem/transfer).
///
/// Les payloads FriPay sont des JSON signés :
/// `{"app":"fripay","payload":"<json>","signature":"..."}`.
/// Le payload interne porte `mode` ('mpm'|'cpm'), `type` ('static'|'dynamic')
/// et, pour les QR argent générés par /qr/generate, `sender_fripay_number`.
/// On inspecte SANS vérifier la signature : la vérification
/// cryptographique reste du ressort exclusif de l'API (source de vérité),
/// le routeur ne fait que choisir l'écran de destination.
class QrRouter {
  QrRouter._();

  /// Inspecte le contenu brut d'un QR et détermine sa destination.
  ///
  /// Ne lève jamais : en dernier recours retourne une route [QrRouteKind.unknown]
  /// avec un message d'erreur affichable.
  static QrRoute route(String rawContent) {
    final content = rawContent.trim();
    try {
      final outer = jsonDecode(content);
      if (outer is Map<String, dynamic> &&
          (outer['app'] ?? '') == 'fripay' &&
          outer['payload'] is String) {
        final inner = jsonDecode(outer['payload'] as String);
        if (inner is Map<String, dynamic>) {
          // QR « argent » : généré par POST /qr/generate — porte le numéro
          // Fripay de l'envoyeur (constituant signé du QR).
          if (inner.containsKey('sender_fripay_number')) {
            return QrRoute._(QrRouteKind.money, content, inner);
          }
          // Sinon : QR marchand (mode mpm explicite ou par défaut).
          final mode = (inner['mode'] ?? 'mpm').toString().toLowerCase();
          return QrRoute._(
            mode == 'cpm' ? QrRouteKind.money : QrRouteKind.merchant,
            content,
            inner,
          );
        }
      }
    } on FormatException {
      // Contenu non-JSON : ce n'est pas un QR FriPay signé.
    } catch (_) {
      // Défensive : tout problème d'inspection => inconnu.
    }
    return const QrRoute._unknown();
  }
}

/// Résultat de l'inspection d'un QR scanné.
class QrRoute {
  final QrRouteKind kind;
  final String rawContent;

  /// Données extraites du payload (non vérifié) : uuid, montant, type...
  final Map<String, dynamic> payload;

  /// Renseigné quand le QR n'est pas un payload FriPay reconnaissable.
  final String? error;

  const QrRoute._(this.kind, this.rawContent, this.payload) : error = null;

  const QrRoute._unknown()
      : kind = QrRouteKind.unknown,
        rawContent = '',
        payload = const {},
        error = 'Ce QR code n\'est pas un QR FriPay valide.';
}

enum QrRouteKind { merchant, money, unknown }
