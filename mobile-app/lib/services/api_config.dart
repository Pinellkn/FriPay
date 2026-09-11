import 'dart:io' show Platform;

/// Configuration centrale de l'accès à l'API FriPay (via le gateway).
///
/// Le gateway PHP tourne en local sur le port 8080 et route vers les
/// services fripay-users (8000), fripay-payments (8001) et fripay-admin
/// (8002). Toutes les routes métier passent par le gateway, jamais par
/// les services directement.
class ApiConfig {
  ApiConfig._();

  /// Hôte à utiliser selon la plateforme d'exécution :
  /// - Émulateur Android : 10.0.2.2 pointe vers le localhost de la machine hôte.
  /// - iOS simulator / desktop / web : localhost fonctionne directement.
  /// - Appareil physique (Android/iOS) : nécessite l'IP LAN de la machine
  ///   qui héberge le gateway, le device et le PC devant être sur le même
  ///   réseau Wi-Fi. `Platform.isAndroid` ne permet PAS de distinguer un
  ///   émulateur d'un appareil physique — d'où le flag ci-dessous.
  ///
  /// METTRE `kIsPhysicalDevice = false` pour retester sur l'émulateur.
  static const bool kIsPhysicalDevice = true;

  /// IP locale (Wi-Fi) de la machine qui héberge le backend FriPay.
  /// Vérifier avec `ipconfig` si l'adresse change (ex. reconnexion Wi-Fi).
  static const String kLanHost = '192.168.1.66';

  static String get _host {
    if (Platform.isAndroid) {
      return kIsPhysicalDevice ? kLanHost : '10.0.2.2';
    }
    if (Platform.isIOS && kIsPhysicalDevice) return kLanHost;
    return '127.0.0.1';
  }

  static const int gatewayPort = 8080;

  static String get baseUrl => 'http://$_host:$gatewayPort/api/v1';
}
