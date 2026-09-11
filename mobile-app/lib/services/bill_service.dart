import 'api_client.dart';

/// Fournisseur de facture (biller). Miroir de GET /bills/billers.
class ApiBiller {
  final String id;
  final String code;
  final String name;
  final String kind;

  ApiBiller({required this.id, required this.code, required this.name, required this.kind});

  factory ApiBiller.fromJson(Map<String, dynamic> j) => ApiBiller(
        id: '${j['id']}',
        code: (j['code'] as String?) ?? '',
        name: (j['name'] as String?) ?? '',
        kind: (j['kind'] as String?) ?? '',
      );
}

/// Paiement de facture. Miroir de la réponse POST/GET /bills.
class BillPayment {
  final String id;
  final String reference;
  final ApiBiller? biller;
  final String subscriberReference;
  final int amount;
  final String status;
  final DateTime? paidAt;

  BillPayment({
    required this.id,
    required this.reference,
    required this.biller,
    required this.subscriberReference,
    required this.amount,
    required this.status,
    required this.paidAt,
  });

  factory BillPayment.fromJson(Map<String, dynamic> j) => BillPayment(
        id: '${j['id']}',
        reference: (j['reference'] as String?) ?? '',
        biller: j['biller'] is Map<String, dynamic> ? ApiBiller.fromJson(j['biller']) : null,
        subscriberReference: (j['subscriber_reference'] as String?) ?? '',
        amount: (j['amount'] as num?)?.toInt() ?? 0,
        status: (j['status'] as String?) ?? '',
        paidAt: j['paid_at'] != null ? DateTime.tryParse('${j['paid_at']}') : null,
      );
}

class BillService {
  BillService._();
  static final BillService instance = BillService._();
  final _api = ApiClient.instance;

  /// GET /bills/billers
  Future<List<ApiBiller>> listBillers() async {
    final res = await _api.get('/bills/billers');
    return (res as List).map((e) => ApiBiller.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// POST /bills/pay
  Future<BillPayment> pay({
    required String billerId,
    required String subscriberReference,
    required int amount,
    required String pin,
  }) async {
    final res = await _api.post('/bills/pay', body: {
      'biller_id': billerId,
      'subscriber_reference': subscriberReference,
      'amount': amount,
      'pin': pin,
    });
    return BillPayment.fromJson(res as Map<String, dynamic>);
  }

  /// GET /bills
  Future<List<BillPayment>> listPayments() async {
    final res = await _api.get('/bills');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => BillPayment.fromJson(e as Map<String, dynamic>)).toList();
  }
}
