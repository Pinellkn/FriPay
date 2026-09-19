import 'dart:async';
import 'package:http/http.dart' as http;

import 'api_client.dart';
import 'api_config.dart';

/// État d'un microservice remonté par le gateway.
class ServiceStatus {
  final String key;
  final String name;
  final String baseUrl;
  final String circuit; // closed | open | half_open
  final int failures;

  ServiceStatus({
    required this.key,
    required this.name,
    required this.baseUrl,
    required this.circuit,
    required this.failures,
  });

  bool get isHealthy => circuit == 'closed';

  factory ServiceStatus.fromEntry(String key, Map<String, dynamic> json) {
    return ServiceStatus(
      key: key,
      name: json['name']?.toString() ?? key,
      baseUrl: json['base_url']?.toString() ?? '',
      circuit: json['circuit']?.toString() ?? 'unknown',
      failures: (json['failures'] as num?)?.toInt() ?? 0,
    );
  }
}

/// Un préfixe réseau groupé par opérateur (§4/§8).
class OperatorPrefixes {
  final String code;
  final String name;
  final bool active;
  final List<String> prefixes;

  OperatorPrefixes({
    required this.code,
    required this.name,
    required this.active,
    required this.prefixes,
  });

  factory OperatorPrefixes.fromJson(Map<String, dynamic> json) {
    return OperatorPrefixes(
      code: json['code']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      active: json['active'] == true,
      prefixes: (json['prefixes'] as List? ?? []).map((e) => e.toString()).toList(),
    );
  }
}

/// §8 - Interface technique : suivi de l'API et gestion des préfixes réseau.
class SystemService {
  SystemService._();
  static final SystemService instance = SystemService._();

  final http.Client _http = http.Client();
  static const _timeout = Duration(seconds: 8);

  /// Interroge le health-check /up de l'application (hors /api/v1, pas via
  /// ApiClient qui ajoute le préfixe et exige un token). Depuis la fusion
  /// en une seule appli, il n'y a plus qu'un seul "service" à surveiller —
  /// on renvoie une liste à un élément pour ne pas changer l'écran appelant.
  Future<List<ServiceStatus>> fetchGatewayStatus() async {
    final uri = Uri.parse('${ApiConfig.appRootUrl}/up');
    String circuit;
    try {
      final response = await _http.get(uri).timeout(_timeout);
      circuit = response.statusCode == 200 ? 'closed' : 'open';
    } catch (_) {
      circuit = 'open';
    }

    return [
      ServiceStatus(
        key: 'fripay',
        name: 'FriPay API',
        baseUrl: ApiConfig.appRootUrl,
        circuit: circuit,
        failures: 0,
      ),
    ];
  }

  /// Liste des préfixes réseau par opérateur (via le backend, authentifié).
  Future<List<OperatorPrefixes>> fetchNetworkPrefixes() async {
    final result = await ApiClient.instance.get('/network/prefixes');
    final operators = (result['operators'] as List? ?? []);
    return operators
        .map((e) => OperatorPrefixes.fromJson(e as Map<String, dynamic>))
        .toList();
  }
}
