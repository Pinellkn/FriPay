import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Fait apparaître de vraies notifications système FriPay dans la barre de
/// notifications du téléphone (et pas seulement dans l'écran "Notifications"
/// interne à l'app).
class NotificationService {
  NotificationService._();
  static final NotificationService instance = NotificationService._();

  final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  bool _ready = false;

  static const _channel = AndroidNotificationChannel(
    'fripay_default',
    'FriPay',
    description: 'Transferts, factures et alertes de sécurité FriPay.',
    importance: Importance.high,
  );

  /// À appeler une fois au démarrage de l'app (voir main.dart).
  Future<void> init() async {
    if (_ready) return;
    const androidInit = AndroidInitializationSettings('@mipmap/launcher_icon');
    const iosInit = DarwinInitializationSettings();
    await _plugin.initialize(
      settings: const InitializationSettings(android: androidInit, iOS: iosInit),
    );

    final androidImpl = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await androidImpl?.createNotificationChannel(_channel);
    try {
      await androidImpl?.requestNotificationsPermission();
    } catch (e) {
      debugPrint('Permission notifications refusée ou indisponible : $e');
    }
    _ready = true;
  }
  /// Affiche une notification FriPay dans la barre de notifications du
  /// téléphone (id unique pour ne pas écraser les précédentes).
  Future<void> show({required int id, required String title, required String body}) async {
    if (!_ready) await init();
    const androidDetails = AndroidNotificationDetails(
      'fripay_default',
      'FriPay',
      channelDescription: 'Transferts, factures et alertes de sécurité FriPay.',
      importance: Importance.high,
      priority: Priority.high,
      icon: '@mipmap/launcher_icon',
      color: Color(0xFF1E7A5C),
    );
    const details = NotificationDetails(android: androidDetails, iOS: DarwinNotificationDetails());
    await _plugin.show(id: id, title: title, body: body, notificationDetails: details);
  }

  static const _shownKey = 'fripay_notifications_already_shown';

  /// Comme [show], mais ne déclenche la notification système qu'une seule
  /// fois pour une même [dedupeKey] (mémorisé sur l'appareil) — évite que la
  /// même alerte réapparaisse à chaque fois qu'on rouvre l'app ou l'écran.
  Future<void> showOnce({
    required String dedupeKey,
    required int id,
    required String title,
    required String body,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final already = prefs.getStringList(_shownKey) ?? <String>[];
    if (already.contains(dedupeKey)) return;
    await show(id: id, title: title, body: body);
    already.add(dedupeKey);
    await prefs.setStringList(_shownKey, already);
  }
}
