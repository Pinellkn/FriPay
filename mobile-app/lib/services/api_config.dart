import 'dart:io' show Platform;

import 'package:shared_preferences/shared_preferences.dart';

/// Configuration centrale de l'accès à l'API FriPay (via le gateway).
///
/// Le backend tourne en local sur le port 8080 (php -S 0.0.0.0:8080) et
/// expose /api/v1. Toutes les routes métier passent par là.
///
/// ADRESSE DU SERVEUR — deux niveaux :
/// 1. Valeur compilée ([kLanHost]) : IP LAN de la machine qui héberge le
///    backend, vérifiée avec `ipconfig`. C'est la valeur par défaut.
/// 2. SURCHARGE RUNTIME : l'écran Profil > Interface technique permet de
///    changer l'adresse SANS recompiler (enregistrée dans
///    SharedPreferences). Indispensable en test sur téléphone physique :
///    une box en DHCP peut changer l'IP du PC à chaque redémarrage, et
///    recompiler un APK à chaque fois n'est pas tenable.
class ApiConfig {
  ApiConfig._();

  /// Hôte à utiliser par défaut selon la plateforme d'exécution :
  /// - Émulateur Android : 10.0.2.2 pointe vers le localhost de la machine hôte.
  /// - iOS simulator / desktop / web : localhost fonctionne directement.
  /// - Appareil physique (Android/iOS) : nécessite l'IP LAN de la machine
  ///   qui héberge le backend, le device et le PC devant être sur le même
  ///   réseau. `Platform.isAndroid` ne permet PAS de distinguer un
  ///   émulateur d'un appareil physique — d'où le flag ci-dessous.
  ///
  /// METTRE `kIsPhysicalDevice = false` pour retester sur l'émulateur.
  static const bool kIsPhysicalDevice = true;

  /// IP locale par défaut de la machine qui héberge le backend FriPay.
  /// Vérifier avec `ipconfig` si l'adresse change — ou mieux, utiliser la
  /// surcharge runtime (Profil > Interface technique) qui évite de
  /// recompiler.
  static const String kLanHost = '192.168.0.8';

  static const int gatewayPort = 8080;

  // ---------------------------------------------------------------------------
  // Surcharge runtime de l'adresse du serveur ("192.168.0.8" ou "host:port").
  // ---------------------------------------------------------------------------

  static const _kOverrideKey = 'fripay_server_override';

  /// Adresse saisie dans l'app, normalisée "host[:port]" (sans schéma ni
  /// slash final). Null = utiliser la valeur compilée par défaut.
  static String? _override;

  /// L'adresse surchargée (ou null si aucune). Affichée dans l'interface
  /// technique.
  static String? get serverOverride => _override;

  /// À appeler UNE FOIS au démarrage (main.dart) avant toute requête.
  static Future<void> loadOverride() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _override = prefs.getString(_kOverrideKey);
    } catch (_) {
      _override = null;
    }
  }

  /// Change (ou réinitialise si null/vide) l'adresse du serveur utilisée
  /// par l'app, et la persiste. Lève [FormatException] si le format est
  /// invalide — l'écran appelant affiche l'erreur.
  static Future<void> setServerOverride(String? value) async {
    final normalized = _normalizeHost(value);
    _override = normalized;
    try {
      final prefs = await SharedPreferences.getInstance();
      if (normalized == null) {
        await prefs.remove(_kOverrideKey);
      } else {
        await prefs.setString(_kOverrideKey, normalized);
      }
    } catch (_) {
      // Persistance impossible (stockage indisponible) : la surcharge
      // reste active pour la session en cours.
    }
  }

  /// Normalise une saisie en "host[:port]" : accepte "192.168.0.8",
  /// "192.168.0.8:8080", "http://192.168.0.8:8080", "http://mon-serveur".
  /// Retourne null si vide ; lève FormatException si mal formé.
  static String? _normalizeHost(String? input) {
    var v = input?.trim() ?? '';
    if (v.isEmpty) return null;
    v = v.replaceFirst(RegExp(r'^https?://', caseSensitive: false), '');
    v = v.replaceAll('/', '');
    if (v.isEmpty) {
      throw const FormatException('Adresse vide.');
    }
    String host;
    int? port;
    final idx = v.lastIndexOf(':');
    if (idx >= 0) {
      host = v.substring(0, idx);
      final p = v.substring(idx + 1);
      port = int.tryParse(p);
      if (port == null || port < 1 || port > 65535) {
        throw FormatException('Port invalide : "$p".');
      }
    } else {
      host = v;
    }
    // Host : IPv4, nom d'hôte ou domaine — on refuse juste espaces et
    // caractères évidemment invalides.
    if (RegExp(r'[\s@]').hasMatch(host) || host.isEmpty) {
      throw FormatException('Hôte invalide : "$host".');
    }
    return port == null ? host : '$host:$port';
  }

  /// Hôte effectif : surcharge runtime si définie, sinon valeur compilée
  /// selon la plateforme.
  static String get _host {
    final o = _override;
    if (o != null && o.isNotEmpty) {
      final idx = o.lastIndexOf(':');
      return idx >= 0 ? o.substring(0, idx) : o;
    }
    if (Platform.isAndroid) {
      return kIsPhysicalDevice ? kLanHost : '10.0.2.2';
    }
    if (Platform.isIOS && kIsPhysicalDevice) return kLanHost;
    return '127.0.0.1';
  }

  /// Port effectif : surcharge runtime si définie, sinon 8080.
  static int get _port {
    final o = _override;
    if (o != null && o.isNotEmpty) {
      final idx = o.lastIndexOf(':');
      if (idx >= 0) return int.tryParse(o.substring(idx + 1)) ?? gatewayPort;
    }
    return gatewayPort;
  }

  static String get baseUrl => 'http://$_host:$_port/api/v1';

  /// Racine du backend, sans /api/v1 — utilisée par l'interface technique
  /// (§8) pour appeler /__gateway/status, qui n'est pas une route métier.
  static String get gatewayRootUrl => 'http://$_host:$_port';

  /// Clé partagée avec le backend (voir fripay/.env
  /// FRIPAY_GATEWAY_DEBUG_KEY) pour consulter /__gateway/status depuis
  /// l'app sans être sur l'IP whitelistée côté serveur.
  static const String gatewayDebugKey = 'fripay-dev-2026-technique';
}
