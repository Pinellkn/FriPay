import 'dart:io' show Platform;

/// Configuration centrale de l'accès à l'API FriPay.
///
/// Depuis la fusion en une seule application Laravel (MVC), tout tourne
/// sur UN SEUL port (8080, conservé pour ne rien changer côté réseau) —
/// il n'y a plus de gateway séparé ni de services distincts à router.
class ApiConfig {
  ApiConfig._();

  /// Hôte à utiliser selon la plateforme d'exécution :
  /// - Émulateur Android : 10.0.2.2 pointe vers le localhost de la machine hôte.
  /// - iOS simulator / desktop / web : localhost fonctionne directement.
  /// - Appareil physique (Android/iOS) : nécessite l'IP LAN de la machine
  ///   qui héberge le backend, le device et le PC devant être sur le même
  ///   réseau Wi-Fi. `Platform.isAndroid` ne permet PAS de distinguer un
  ///   émulateur d'un appareil physique — d'où le flag ci-dessous.
  ///
  /// METTRE `kIsPhysicalDevice = false` pour retester sur l'émulateur.
  static const bool kIsPhysicalDevice = true;

  /// IP locale (Wi-Fi) de la machine qui héberge le backend FriPay.
  /// Vérifier avec `ipconfig` si l'adresse change (ex. reconnexion Wi-Fi).
  static const String kLanHost = '192.168.0.3';

  static String get _host {
    if (Platform.isAndroid) {
      return kIsPhysicalDevice ? kLanHost : '10.0.2.2';
    }
    if (Platform.isIOS && kIsPhysicalDevice) return kLanHost;
    return '127.0.0.1';
  }

  static const int appPort = 8080;

  static String get baseUrl => 'http://$_host:$appPort/api/v1';

  /// Racine de l'appli, sans /api/v1 — utilisée par l'interface technique
  /// (§8) pour le simple health-check (/up), qui n'est pas une route métier.
  static String get appRootUrl => 'http://$_host:$appPort';
}
