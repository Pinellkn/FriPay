import 'api_client.dart';

/// QR marchand généré (MPM — Merchant Present Mode) : le titulaire du
/// compte génère ce QR, quiconque le scanne peut lui payer un montant
/// (statique : montant saisi par le payeur ; dynamique : montant fixé
/// à la génération). Miroir de MerchantQrController::generateMpm.
class MerchantQr {
  final String qrCode; // contenu JSON signé à encoder dans le QR
  final String uuid;
  final String qrType; // 'static' | 'dynamic'
  final int? amount; // null pour un QR statique
  final String currency;
  final String? description;
  final String expiresAt;

  MerchantQr({
    required this.qrCode,
    required this.uuid,
    required this.qrType,
    required this.amount,
    required this.currency,
    required this.description,
    required this.expiresAt,
  });

  factory MerchantQr.fromJson(Map<String, dynamic> j) => MerchantQr(
        qrCode: j['qr_code'] as String,
        uuid: j['uuid'] as String,
        qrType: (j['qr_type'] as String?) ?? 'static',
        amount: (j['amount'] as num?)?.toInt(),
        currency: (j['currency'] as String?) ?? 'XOF',
        description: j['description'] as String?,
        expiresAt: (j['expires_at'] as String?) ?? '',
      );
}

/// Entrée de l'historique des QR générés par l'utilisateur (marchand).
class MerchantQrHistoryEntry {
  final String uuid;
  final String qrType;
  final String qrMode; // 'mpm' | 'cpm'
  final int amount;
  final String status; // active | redeemed | expired | revoked...
  final int useCount;
  final String createdAt;
  final String? redeemedAt;

  MerchantQrHistoryEntry({
    required this.uuid,
    required this.qrType,
    required this.qrMode,
    required this.amount,
    required this.status,
    required this.useCount,
    required this.createdAt,
    this.redeemedAt,
  });

  factory MerchantQrHistoryEntry.fromJson(Map<String, dynamic> j) => MerchantQrHistoryEntry(
        uuid: j['uuid'] as String,
        qrType: (j['qr_type'] as String?) ?? '',
        qrMode: (j['qr_mode'] as String?) ?? '',
        amount: (j['amount'] as num?)?.toInt() ?? 0,
        status: (j['status'] as String?) ?? '',
        useCount: (j['use_count'] as num?)?.toInt() ?? 0,
        createdAt: (j['created_at'] as String?) ?? '',
        redeemedAt: j['redeemed_at'] as String?,
      );
}

/// Service des QR Codes marchand FriPay (MPM + CPM) — miroir de
/// MerchantQrController (fripay-payments).
class MerchantQrService {
  MerchantQrService._();
  static final MerchantQrService instance = MerchantQrService._();
  final _api = ApiClient.instance;

  /// POST /qr/mpm/generate — QR statique (le payeur saisit le montant).
  Future<MerchantQr> generateStatic({String? description}) async {
    final res = await _api.post('/qr/mpm/generate', body: {
      'qr_type': 'static',
      if (description != null && description.isNotEmpty) 'description': description,
    });
    return MerchantQr.fromJson(res as Map<String, dynamic>);
  }

  /// POST /qr/mpm/generate — QR dynamique (montant fixé d'avance).
  Future<MerchantQr> generateDynamic({
    required int amount,
    String? description,
    int expiresMinutes = 30,
  }) async {
    final res = await _api.post('/qr/mpm/generate', body: {
      'qr_type': 'dynamic',
      'amount': amount,
      'expires_minutes': expiresMinutes,
      if (description != null && description.isNotEmpty) 'description': description,
    });
    return MerchantQr.fromJson(res as Map<String, dynamic>);
  }

  /// GET /qr/merchant/history — QR déjà générés par l'utilisateur.
  Future<List<MerchantQrHistoryEntry>> history({int size = 50}) async {
    final res = await _api.get('/qr/merchant/history', query: {'size': size});
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => MerchantQrHistoryEntry.fromJson(e as Map<String, dynamic>)).toList();
  }

  // ── Côté payeur (onglet "Scanner un QR" d'Envoyer) ──────────────────

  /// POST /qr/mpm/scan — vérifie un QR marchand scanné.
  Future<Map<String, dynamic>> scan(String qrContent, {int? amount}) async {
    final res = await _api.post('/qr/mpm/scan', body: {
      'qr_content': qrContent,
      'amount': ?amount,
    });
    return res as Map<String, dynamic>;
  }

  /// POST /qr/mpm/pay — confirme et paie un QR marchand scanné.
  Future<Map<String, dynamic>> pay({
    required String uuid,
    required int amount,
    required String pin,
    required String senderAccountId,
  }) async {
    final res = await _api.post('/qr/mpm/pay', body: {
      'uuid': uuid,
      'amount': amount,
      'pin': pin,
      'sender_account_id': senderAccountId,
    });
    return res as Map<String, dynamic>;
  }
}
