import 'api_client.dart';

/// Devis de transfert — réponse de POST /transfers/quote.
class TransferQuote {
  final String quoteToken;
  final String recipientOperator; // 'MTN' | 'MOOV' | 'CELTIIS'
  final double amount;
  final double feeAmount;
  final double totalDebited;
  final int estimatedDeliverySeconds;

  TransferQuote({
    required this.quoteToken,
    required this.recipientOperator,
    required this.amount,
    required this.feeAmount,
    required this.totalDebited,
    required this.estimatedDeliverySeconds,
  });

  factory TransferQuote.fromJson(Map<String, dynamic> j) => TransferQuote(
        quoteToken: j['quote_token'] as String,
        recipientOperator: (j['recipient_operator'] as String?) ?? '',
        amount: (j['amount'] as num).toDouble(),
        feeAmount: (j['fee_amount'] as num).toDouble(),
        totalDebited: (j['total_debited'] as num).toDouble(),
        estimatedDeliverySeconds: (j['estimated_delivery_seconds'] as num?)?.toInt() ?? 0,
      );
}

/// Résultat de l'initiation — réponse (202) de POST /transfers.
class TransferInitiated {
  final String transactionId;
  final String reference;
  final String status;
  TransferInitiated({required this.transactionId, required this.reference, required this.status});

  factory TransferInitiated.fromJson(Map<String, dynamic> j) => TransferInitiated(
        transactionId: '${j['transaction_id']}',
        reference: j['reference'] as String,
        status: j['status'] as String,
      );
}

/// Transaction complète — TransactionResource (GET /transfers, /transfers/{id}).
class Transaction {
  final String id;
  final String reference;
  final double amount;
  final double feeAmount;
  final double totalDebited;
  final String currency;
  final String status; // pending|processing|completed|failed|cancelled
  final String? railUsed;
  final String recipientPhone;
  final String? recipientOperator;
  final String? recipientName;
  final String? failureReason;
  final String? initiatedAt;
  final String? completedAt;

  Transaction({
    required this.id,
    required this.reference,
    required this.amount,
    required this.feeAmount,
    required this.totalDebited,
    required this.currency,
    required this.status,
    this.railUsed,
    required this.recipientPhone,
    this.recipientOperator,
    this.recipientName,
    this.failureReason,
    this.initiatedAt,
    this.completedAt,
  });

  factory Transaction.fromJson(Map<String, dynamic> j) => Transaction(
        id: '${j['id']}',
        reference: (j['reference'] as String?) ?? '',
        amount: (j['amount'] as num?)?.toDouble() ?? 0,
        feeAmount: (j['fee_amount'] as num?)?.toDouble() ?? 0,
        totalDebited: (j['total_debited'] as num?)?.toDouble() ?? 0,
        currency: (j['currency'] as String?) ?? 'XOF',
        status: (j['status'] as String?) ?? 'pending',
        railUsed: j['rail_used'] as String?,
        recipientPhone: (j['recipient_phone'] as String?) ?? '',
        recipientOperator: j['recipient_operator'] as String?,
        recipientName: j['recipient_name'] as String?,
        failureReason: j['failure_reason'] as String?,
        initiatedAt: j['initiated_at'] as String?,
        completedAt: j['completed_at'] as String?,
      );
}

/// Service de transfert FriPay — miroir de TransferController (fripay-payments).
class TransferService {
  TransferService._();
  static final TransferService instance = TransferService._();
  final _api = ApiClient.instance;

  /// POST /transfers/quote — simulation des frais, valable 2 minutes
  /// (quote_token à réutiliser tel quel dans initiate()).
  Future<TransferQuote> quote({
    required String senderAccountId,
    required String recipientPhone,
    required double amount,
  }) async {
    final res = await _api.post('/transfers/quote', body: {
      'sender_account_id': senderAccountId,
      'recipient_phone': recipientPhone,
      'amount': amount,
    });
    return TransferQuote.fromJson(res as Map<String, dynamic>);
  }

  /// POST /transfers — initie le transfert (nécessite le PIN de l'utilisateur).
  Future<TransferInitiated> initiate({
    required String quoteToken,
    required String senderAccountId,
    required String recipientPhone,
    required double amount,
    required String pin,
  }) async {
    final res = await _api.post('/transfers', body: {
      'quote_token': quoteToken,
      'sender_account_id': senderAccountId,
      'recipient_phone': recipientPhone,
      'amount': amount,
      'pin': pin,
    });
    return TransferInitiated.fromJson(res as Map<String, dynamic>);
  }

  /// GET /transfers — historique paginé de l'utilisateur.
  Future<List<Transaction>> list({int size = 20}) async {
    final res = await _api.get('/transfers', query: {'size': size});
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => Transaction.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// GET /transfers/{id}
  Future<Transaction> show(String transactionId) async {
    final res = await _api.get('/transfers/$transactionId');
    return Transaction.fromJson(res as Map<String, dynamic>);
  }

  /// POST /transfers/{id}/cancel
  Future<Transaction> cancel(String transactionId) async {
    final res = await _api.post('/transfers/$transactionId/cancel');
    return Transaction.fromJson(res as Map<String, dynamic>);
  }
}
