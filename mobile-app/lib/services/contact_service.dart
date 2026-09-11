import 'api_client.dart';

/// Contact FriPay réel — miroir de ContactResource (fripay-users).
/// Nouveau : contrairement à mock_data.dart, la liste démarre VIDE pour
/// tout compte tant que l'utilisateur n'a pas ajouté de contact lui-même
/// (pas d'import du répertoire téléphonique côté backend).
class FripayContact {
  final String id;
  final String phone;
  final String name;
  final String? detectedOperator; // code opérateur (MTN, MOOV, CELTIIS...)

  FripayContact({
    required this.id,
    required this.phone,
    required this.name,
    this.detectedOperator,
  });

  factory FripayContact.fromJson(Map<String, dynamic> j) => FripayContact(
        id: j['id'].toString(),
        phone: (j['contact_phone'] as String?) ?? '',
        name: (j['contact_name'] as String?) ?? '',
        detectedOperator: j['detected_operator'] as String?,
      );
}

/// Service des contacts FriPay (GET/POST/DELETE /users/me/contacts).
class ContactService {
  ContactService._();
  static final ContactService instance = ContactService._();
  final _api = ApiClient.instance;

  Future<List<FripayContact>> list() async {
    final res = await _api.get('/users/me/contacts');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => FripayContact.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<FripayContact> add({required String name, required String phone}) async {
    final res = await _api.post('/users/me/contacts', body: {
      'contact_name': name,
      'contact_phone': phone,
    });
    return FripayContact.fromJson(res as Map<String, dynamic>);
  }

  Future<void> remove(String contactId) async {
    await _api.delete('/users/me/contacts/$contactId');
  }
}
