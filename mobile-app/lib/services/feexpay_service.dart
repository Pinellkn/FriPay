import 'api_client.dart';

/// Recharge du portefeuille FriPay via l'agrégateur FeexPay.
///
/// Miroir des endpoints fripay-payments (préfixe /api/v1) :
///   POST /wallet/topup/feexpay          -> initie la recharge (push sur le téléphone)
///   GET  /wallet/topup/feexpay/{id}     -> statut temps réel (crédite si confirmé)
///   GET  /wallet/topup/feexpay          -> historique des recharges
///
/// Le wallet n'est crédité côté serveur qu'après la CONFIRMATION du paiement
/// mobile money sur le téléphone (MTN/Moov) — pas à l'initiation.
class FeexpayService {
  FeexpayService._();
  static final FeexpayService instance = FeexpayService._();
  final _api = ApiClient.instance;

  /// Modèle interne : miroir de la sérialisation backend de wallet_topups.
  static WalletTopup fromJson(Map<String, dynamic> j) => WalletTopup(
        id: '${j['id']}',
        amount: (j['amount'] as num?)?.toInt() ?? 0,
        operator: (j['operator'] as String?) ?? '',
        status: (j['status'] as String?) ?? 'pending',
        reference: (j['reference'] as String?) ?? '',
        failureReason: j['failure_reason'] as String?,
        paymentUrl: j['payment_url'] as String?,
      );

  /// POST /wallet/topup/feexpay
  /// [operator] : "MTN" ou "MOOV" (collecte FeexPay ; Celtiis non couvert).
  /// Retourne le topup créé (status processing si accepté par FeexPay).
  Future<WalletTopup> initiate({
    required int amount,
    required String operator,
  }) async {
    final res = await _api.post('/wallet/topup/feexpay', body: {
      'amount': amount,
      'operator': operator,
    });
    final map = res as Map<String, dynamic>;
    final topup = (map['topup'] as Map<String, dynamic>?) ?? const {};
    return fromJson({...topup, 'payment_url': map['payment_url']});
  }

  /// GET /wallet/topup/feexpay/{id} — vérifie le statut et crédite le
  /// solde si le paiement vient d'être confirmé (idempotent côté serveur).
  Future<WalletTopup> checkStatus(String topupId) async {
    final res = await _api.get('/wallet/topup/feexpay/$topupId');
    final map = res as Map<String, dynamic>;
    final topup = (map['topup'] as Map<String, dynamic>?) ?? const {};
    return fromJson(topup);
  }

  /// GET /wallet/topup/feexpay — historique des recharges.
  Future<List<WalletTopup>> history() async {
    final res = await _api.get('/wallet/topup/feexpay');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? [])
        .map((e) => fromJson(e as Map<String, dynamic>))
        .toList();
  }
}

/// Miroir de la table wallet_topups (fripay-payments).
class WalletTopup {
  final String id;
  final int amount;
  final String operator; // MTN | MOOV
  final String status; // pending | processing | completed | failed
  final String reference;
  final String? failureReason;
  final String? paymentUrl;

  WalletTopup({
    required this.id,
    required this.amount,
    required this.operator,
    required this.status,
    required this.reference,
    this.failureReason,
    this.paymentUrl,
  });

  bool get isCompleted => status == 'completed';
  bool get isFailed => status == 'failed';
  bool get isPending => status == 'pending' || status == 'processing';
}
