import 'api_client.dart';

/// Notification utilisateur telle que renvoyée par
/// GET /notifications (fripay-users) — miroir de NotificationResource.
class UserNotification {
  final String id;
  final String type; // libre côté backend (ex: transfer, security, system...)
  final String? channel;
  final String title;
  final String body;
  final String? relatedTransactionId;
  bool read;
  final String? createdAt;

  UserNotification({
    required this.id,
    required this.type,
    this.channel,
    required this.title,
    required this.body,
    this.relatedTransactionId,
    required this.read,
    this.createdAt,
  });

  factory UserNotification.fromJson(Map<String, dynamic> j) => UserNotification(
        id: j['id'].toString(),
        type: (j['type'] as String?) ?? 'system',
        channel: j['channel'] as String?,
        title: (j['title'] as String?) ?? '',
        body: (j['body'] as String?) ?? '',
        relatedTransactionId: j['related_transaction_id']?.toString(),
        read: j['read'] as bool? ?? false,
        createdAt: j['created_at'] as String?,
      );
}

/// Service des notifications FriPay (fripay-users) — distinct de
/// [NotificationService] qui gère les notifications SYSTÈME (barre du
/// téléphone) ; celui-ci parle au vrai backend.
class UserNotificationService {
  UserNotificationService._();
  static final UserNotificationService instance = UserNotificationService._();
  final _api = ApiClient.instance;

  /// GET /notifications — [unreadOnly] filtre côté serveur si fourni.
  Future<List<UserNotification>> list({int size = 30, bool? unreadOnly}) async {
    final res = await _api.get('/notifications', query: {
      'size': size,
      if (unreadOnly != null) 'read': !unreadOnly,
    });
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => UserNotification.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// PUT /notifications/{id}/read
  Future<void> markAsRead(String id) async {
    await _api.put('/notifications/$id/read');
  }
}
