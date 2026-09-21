import 'api_client.dart';

/// FriPay Link — liens de paiement partageables.
///
/// Miroir des endpoints backend (préfixe /api/v1) :
///   POST /payment-links                 -> crée un lien (montant + motif)
///   GET  /payment-links                 -> liens de l'utilisateur courant
///   GET  /payment-links/{token}         -> consultation publique (page web)
///   POST /payment-links/{token}/pay     -> paiement public via FeexPay
///   GET  /payment-links/{token}/status  -> statut temps réel (page web)
///
/// Le backend génère un token public non-devinable et verrouille le montant
/// côté serveur : le payeur ne peut jamais le modifier.
class PaymentLinkService {
  PaymentLinkService._();
  static final PaymentLinkService instance = PaymentLinkService._();
  final _api = ApiClient.instance;

  /// Modèle interne : miroir de la sérialisation backend de payment_links.
  static FripayLink fromJson(Map<String, dynamic> j) => FripayLink(
        token: '${j['token']}',
        amount: (j['amount'] as num?)?.toInt() ?? 0,
        currency: (j['currency'] as String?) ?? 'XOF',
        description: j['description'] as String?,
        status: (j['status'] as String?) ?? 'created',
        creator: (j['creator'] as String?) ?? '',
        shareUrl: j['share_url'] as String?,
        expired: (j['expired'] as bool?) ?? false,
      );

  /// POST /payment-links — crée un lien de paiement (montant verrouillé).
  /// Retourne le lien créé avec son URL de partage.
  Future<FripayLink> create({
    required int amount,
    String? description,
  }) async {
    final res = await _api.post('/payment-links', body: {
      'amount': amount,
      if (description != null && description.trim().isNotEmpty)
        'description': description.trim(),
    });
    final map = res as Map<String, dynamic>;
    final link = (map['link'] as Map<String, dynamic>?) ?? const {};
    return fromJson({...link, 'share_url': map['share_url'] ?? link['share_url']});
  }

  /// GET /payment-links — historique des liens de l'utilisateur.
  Future<List<FripayLink>> history() async {
    final res = await _api.get('/payment-links');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? [])
        .map((e) => fromJson(e as Map<String, dynamic>))
        .toList();
  }
}

/// Miroir de la sérialisation backend de payment_links.
class FripayLink {
  final String token;
  final int amount;
  final String currency;
  final String? description;
  final String status; // created | paid | expired | cancelled
  final String creator;
  final String? shareUrl;
  final bool expired;

  FripayLink({
    required this.token,
    required this.amount,
    required this.currency,
    required this.status,
    required this.creator,
    this.description,
    this.shareUrl,
    this.expired = false,
  });

  bool get isPaid => status == 'paid';
  bool get isPending => status == 'created' && !expired;
}
