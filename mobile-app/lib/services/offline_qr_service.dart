import 'api_client.dart';

/// QR Code "argent" généré par l'envoyeur — miroir de la réponse de
/// OfflineQrController::generate (POST /qr/generate). Contient le montant,
/// réservé (débité) dès la génération, et un code de validation à 5
/// chiffres si le destinataire n'a pas de compte FriPay (§6.e).
class GeneratedQr {
  final String qrCode; // contenu JSON signé à encoder dans le QR
  final String uuid;
  final int amount;
  final String currency;
  final String? expiresAt;
  final bool? hasRecipientAccount;
  final String? externalValidationCode;

  /// Numéro Fripay de l'ENVOYEUR (constituant du QR, cahier des charges).
  final String? senderFripayNumber;

  GeneratedQr({
    required this.qrCode,
    required this.uuid,
    required this.amount,
    required this.currency,
    this.expiresAt,
    this.hasRecipientAccount,
    this.externalValidationCode,
    this.senderFripayNumber,
  });

  factory GeneratedQr.fromJson(Map<String, dynamic> j) => GeneratedQr(
        qrCode: j['qr_code'] as String,
        uuid: j['uuid'] as String,
        amount: (j['amount'] as num).toInt(),
        currency: (j['currency'] as String?) ?? 'XOF',
        expiresAt: j['expires_at'] as String?,
        hasRecipientAccount: j['has_recipient_account'] as bool?,
        externalValidationCode: j['external_validation_code'] as String?,
        senderFripayNumber: j['sender_fripay_number'] as String?,
      );
}

/// Résumé d'un QR "coffre" — pour "mine()" (mes QR envoyés actifs).
class MyQrSummary {
  final String uuid;
  final int amount;
  final String currency;
  final String status;
  final String qrMode;
  final String qrType;
  final String? expiresAt;
  final String createdAt;

  MyQrSummary({
    required this.uuid,
    required this.amount,
    required this.currency,
    required this.status,
    required this.qrMode,
    required this.qrType,
    this.expiresAt,
    required this.createdAt,
  });

  factory MyQrSummary.fromJson(Map<String, dynamic> j) => MyQrSummary(
        uuid: j['uuid'] as String,
        amount: (j['amount'] as num).toInt(),
        currency: (j['currency'] as String?) ?? 'XOF',
        status: (j['status'] as String?) ?? '',
        qrMode: (j['qr_mode'] as String?) ?? '',
        qrType: (j['qr_type'] as String?) ?? '',
        expiresAt: j['expires_at'] as String?,
        createdAt: (j['created_at'] as String?) ?? '',
      );
}

/// Service des QR Codes "argent" hors-ligne FriPay (§6 du cahier des
/// charges) — miroir de OfflineQrController (fripay-payments).
/// Différent de MerchantQrService (paiement marchand MPM/CPM) : ici le QR
/// "contient" un montant réservé, transmis d'un utilisateur à un autre.
class OfflineQrService {
  OfflineQrService._();
  static final OfflineQrService instance = OfflineQrService._();
  final _api = ApiClient.instance;

  /// POST /qr/generate — §6.a : génère le QR, débite immédiatement
  /// l'envoyeur (l'argent est "dans" le QR). [recipientPhone] permet la
  /// détection auto du compte du receveur (§6.e) ; si fourni et que le
  /// receveur n'a pas de compte, un code à 5 chiffres est retourné pour
  /// transmission hors appli (WhatsApp, etc.).
  /// [validationCode] : code de vérification à 5 chiffres DÉFINI PAR
  /// L'ENVOYEUR (cahier des charges) — il le transmet lui-même au receveur
  /// sans compte Fripay, qui devra le saisir sur la page web de retrait.
  Future<GeneratedQr> generate({
    required int amount,
    String? recipientPhone,
    String? recipientHint,
    int? expiresMinutes,
    String? validationCode,
  }) async {
    final res = await _api.post('/qr/generate', body: {
      'amount': amount,
      // Compound null+isNotEmpty checks; kept explicit for readability.
      // ignore: use_null_aware_elements
      if (recipientPhone != null && recipientPhone.isNotEmpty) 'recipient_phone': recipientPhone,
      // ignore: use_null_aware_elements
      if (recipientHint != null && recipientHint.isNotEmpty) 'recipient_hint': recipientHint,
      // ignore: use_null_aware_elements
      if (expiresMinutes != null) 'expires_minutes': expiresMinutes,
      // ignore: use_null_aware_elements
      if (validationCode != null && validationCode.isNotEmpty) 'external_validation_code': validationCode,
    });
    return GeneratedQr.fromJson(res as Map<String, dynamic>);
  }

  /// POST /qr/receive — §6.d : le receveur scanne/téléverse le QR, il est
  /// stocké dans son "coffre" (status devient "received").
  Future<Map<String, dynamic>> receive(String qrContent) async {
    final res = await _api.post('/qr/receive', body: {'qr_content': qrContent});
    return res as Map<String, dynamic>;
  }

  /// POST /qr/redeem — encaisse un QR déjà reçu (ou reçu directement par
  /// le destinataire visé) : le règlement final passera par le connecteur
  /// opérateur.
  Future<Map<String, dynamic>> redeem(String uuid) async {
    final res = await _api.post('/qr/redeem', body: {'uuid': uuid});
    return res as Map<String, dynamic>;
  }

  /// POST /qr/transfer — §6.c : au lieu d'encaisser, transmettre le QR à
  /// un tiers (qui devient le nouveau destinataire, puis "envoyeur" à son
  /// tour s'il le retransmet).
  Future<Map<String, dynamic>> transfer({required String uuid, required String recipientPhone}) async {
    final res = await _api.post('/qr/transfer', body: {'uuid': uuid, 'recipient_phone': recipientPhone});
    return res as Map<String, dynamic>;
  }

  /// POST /qr/revoke — l'envoyeur annule son QR non encore réclamé ;
  /// l'argent réservé est reversé sur son solde.
  Future<Map<String, dynamic>> revoke(String uuid) async {
    final res = await _api.post('/qr/revoke', body: {'uuid': uuid});
    return res as Map<String, dynamic>;
  }

  /// GET /qr/mine/active — mes QR "argent" envoyés, actifs (non réclamés,
  /// non expirés).
  Future<List<MyQrSummary>> mine() async {
    final res = await _api.get('/qr/mine/active');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => MyQrSummary.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// GET /qr/{uuid}/status — détail + historique d'événements d'un QR
  /// (accessible à l'envoyeur, au receveur ou au marchand concerné).
  Future<Map<String, dynamic>> status(String uuid) async {
    final res = await _api.get('/qr/$uuid/status');
    return res as Map<String, dynamic>;
  }
}
