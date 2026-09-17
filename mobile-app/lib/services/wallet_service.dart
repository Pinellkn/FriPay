import 'api_client.dart';

/// Solde du portefeuille interne FriPay (wallet ledger).
/// Miroir de la réponse GET /wallet (fripay-payments).
class Wallet {
  final int balance;
  final String currency;
  final String status;

  Wallet({required this.balance, required this.currency, required this.status});

  factory Wallet.fromJson(Map<String, dynamic> j) => Wallet(
        balance: (j['balance'] as num?)?.toInt() ?? 0,
        currency: (j['currency'] as String?) ?? 'XOF',
        status: (j['status'] as String?) ?? 'active',
      );
}

/// Une ligne de l'historique du wallet (crédit ou débit).
/// Miroir d'un item de GET /wallet/transactions.
class WalletLedgerEntry {
  final String id;
  final String type; // 'credit' | 'debit'
  final int amount;
  final int balanceAfter;
  final String reason;
  final String description;
  final String? transactionId;
  final DateTime createdAt;

  WalletLedgerEntry({
    required this.id,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    required this.reason,
    required this.description,
    required this.transactionId,
    required this.createdAt,
  });

  factory WalletLedgerEntry.fromJson(Map<String, dynamic> j) => WalletLedgerEntry(
        id: '${j['id']}',
        type: (j['type'] as String?) ?? 'debit',
        amount: (j['amount'] as num?)?.toInt() ?? 0,
        balanceAfter: (j['balance_after'] as num?)?.toInt() ?? 0,
        reason: (j['reason'] as String?) ?? '',
        description: (j['description'] as String?) ?? '',
        transactionId: j['transaction_id'] as String?,
        createdAt: DateTime.tryParse('${j['created_at']}') ?? DateTime.now(),
      );
}

class WalletService {
  WalletService._();
  static final WalletService instance = WalletService._();
  final _api = ApiClient.instance;

  /// GET /wallet
  Future<Wallet> getWallet() async {
    final res = await _api.get('/wallet');
    return Wallet.fromJson(res as Map<String, dynamic>);
  }

  /// GET /wallet/transactions
  Future<List<WalletLedgerEntry>> listTransactions() async {
    final res = await _api.get('/wallet/transactions');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => WalletLedgerEntry.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// POST /wallet/topup
  /// Dépôt manuel temporaire, en attendant un vrai rail de cash-in
  /// (voir README backend). [amount] en XOF (entier). [phoneNumber] n'est pas
  /// validé contre un registre réel (aucun réseau d'agents encore construit)
  /// — juste enregistré à titre indicatif.
  Future<Wallet> topup(int amount, {required String pin, String? phoneNumber}) async {
    final res = await _api.post('/wallet/topup', body: {
      'amount': amount,
      'pin': pin,
      if (phoneNumber != null && phoneNumber.isNotEmpty) 'phone_number': phoneNumber,
    });
    return Wallet.fromJson(res as Map<String, dynamic>);
  }

  /// POST /wallet/withdraw — même logique temporaire que topup.
  Future<Wallet> withdraw(int amount, {required String pin, String? phoneNumber}) async {
    final res = await _api.post('/wallet/withdraw', body: {
      'amount': amount,
      'pin': pin,
      if (phoneNumber != null && phoneNumber.isNotEmpty) 'phone_number': phoneNumber,
    });
    return Wallet.fromJson(res as Map<String, dynamic>);
  }
}
