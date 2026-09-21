import 'api_client.dart';

/// Compte mobile lié au compte FriPay de l'utilisateur.
/// Miroir de LinkedAccountResource (fripay-users).
/// Remarque : le backend n'expose pas de solde (pas d'endpoint balance) —
/// FriPay orchestre des transferts vers ces comptes mais ne stocke pas
/// leur solde réel, qui reste consultable uniquement chez l'opérateur.
class LinkedAccount {
  final String id;
  final String operatorCode; // 'MTN' | 'MOOV' | 'CELTIIS'
  final String msisdn;
  final bool isPrimary;
  final String status;

  LinkedAccount({
    required this.id,
    required this.operatorCode,
    required this.msisdn,
    required this.isPrimary,
    required this.status,
  });

  factory LinkedAccount.fromJson(Map<String, dynamic> j) => LinkedAccount(
        id: '${j['id']}',
        operatorCode: (j['operator'] as String?) ?? '',
        msisdn: (j['msisdn'] as String?) ?? '',
        isPrimary: j['is_primary'] == true,
        status: (j['status'] as String?) ?? 'active',
      );
}

class AccountService {
  AccountService._();
  static final AccountService instance = AccountService._();
  final _api = ApiClient.instance;

  /// GET /users/me/accounts
  Future<List<LinkedAccount>> listAccounts() async {
    final res = await _api.get('/users/me/accounts');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => LinkedAccount.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// POST /users/me/accounts
  Future<LinkedAccount> linkAccount(String msisdn) async {
    final res = await _api.post('/users/me/accounts', body: {'msisdn': msisdn});
    return LinkedAccount.fromJson(res as Map<String, dynamic>);
  }

  /// DELETE /users/me/accounts/{id}
  Future<void> unlinkAccount(String accountId) async {
    await _api.delete('/users/me/accounts/$accountId');
  }
}
