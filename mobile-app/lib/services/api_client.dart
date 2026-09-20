import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'package:http/http.dart' as http;

import 'api_config.dart';
import 'token_storage.dart';

/// Exception levée pour toute erreur API — reflète le format d'erreur
/// standardisé du backend (style RFC 7807) :
/// { type, title, status, detail, request_id }.
class ApiException implements Exception {
  final int status;
  final String type;
  final String title;
  final String detail;

  ApiException({
    required this.status,
    required this.type,
    required this.title,
    required this.detail,
  });

  /// Message prêt à afficher à l'utilisateur.
  String get userMessage => detail.isNotEmpty ? detail : title;

  @override
  String toString() => 'ApiException($status, $type): $title — $detail';
}

/// Client HTTP générique pour l'API FriPay (via le gateway).
///
/// - Ajoute automatiquement l'en-tête Authorization: Bearer si un
///   access_token est présent en session.
/// - Sur 401 (token expiré), tente un rafraîchissement automatique via
///   /auth/refresh-token puis rejoue la requête une seule fois.
/// - Convertit toute réponse d'erreur JSON en [ApiException].
class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  final http.Client _http = http.Client();

  /// Un seul rafraîchissement de token à la fois : si plusieurs requêtes
  /// échouent en 401 simultanément (ex. au chargement du dashboard, 4-5
  /// appels partent en parallèle), elles doivent toutes attendre le MÊME
  /// appel /auth/refresh-token au lieu d'en déclencher chacune un — le
  /// refresh_token est à usage unique côté serveur, donc la 2e tentative
  /// en parallèle échouerait en 401 et ferait planter cet écran avec un
  /// message d'erreur générique, alors que la session est en fait valide.
  Future<bool>? _refreshInFlight;

  /// Délai max avant d'abandonner une requête. Sans ça, si le téléphone
  /// n'arrive pas à joindre le gateway (mauvais réseau, pare-feu Windows
  /// qui bloque le port, IP LAN obsolète...), le Future ne se termine
  /// jamais et le bouton reste bloqué en "chargement" indéfiniment.
  static const _timeout = Duration(seconds: 12);

  Uri _uri(String path, [Map<String, dynamic>? query]) {
    final base = ApiConfig.baseUrl;
    return Uri.parse('$base$path').replace(
      queryParameters: query?.map((k, v) => MapEntry(k, '$v')),
    );
  }

  Future<Map<String, String>> _headers({bool authenticated = true, String? idempotencyKey}) async {
    final headers = {'Content-Type': 'application/json', 'Accept': 'application/json'};
    if (authenticated) {
      final token = await TokenStorage.instance.accessToken;
      if (token != null) headers['Authorization'] = 'Bearer $token';
    }
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;
    return headers;
  }

  /// Certaines routes (ex. POST /users/me/accounts) exigent un en-tête
  /// Idempotency-Key. On en génère un pour tout POST/PUT — sans effet sur
  /// les routes qui ne le demandent pas.
  String _newIdempotencyKey() {
    final rand = Random();
    final ts = DateTime.now().microsecondsSinceEpoch.toRadixString(16);
    final rnd = List.generate(8, (_) => rand.nextInt(16).toRadixString(16)).join();
    return '$ts-$rnd';
  }

  Future<dynamic> get(String path, {Map<String, dynamic>? query, bool authenticated = true}) =>
      _send('GET', path, query: query, authenticated: authenticated);

  Future<dynamic> post(String path, {Map<String, dynamic>? body, bool authenticated = true}) =>
      _send('POST', path, body: body, authenticated: authenticated);

  Future<dynamic> put(String path, {Map<String, dynamic>? body, bool authenticated = true}) =>
      _send('PUT', path, body: body, authenticated: authenticated);

  Future<dynamic> delete(String path, {bool authenticated = true}) =>
      _send('DELETE', path, authenticated: authenticated);

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    bool authenticated = true,
    bool isRetry = false,
    String? idempotencyKey,
  }) async {
    final uri = _uri(path, query);
    final key = (method == 'POST' || method == 'PUT') ? (idempotencyKey ?? _newIdempotencyKey()) : null;
    final encodedBody = body != null ? jsonEncode(body) : null;

    late final http.Response response;
    try {
      // La lecture du token (flutter_secure_storage, canal de plateforme)
      // est incluse dans ce try/catch : juste après un démarrage à froid
      // de l'app, ce canal peut ne pas être encore prêt et lever une
      // PlatformException — sans ça, cette erreur n'était pas convertie
      // en ApiException et remontait telle quelle jusqu'à l'écran, qui
      // affichait alors un message générique sans rapport avec la cause
      // réelle ("Une erreur est survenue. Tirez pour réessayer.").
      final headers = await _headers(authenticated: authenticated, idempotencyKey: key);
      switch (method) {
        case 'GET':
          response = await _http.get(uri, headers: headers).timeout(_timeout);
          break;
        case 'POST':
          response = await _http.post(uri, headers: headers, body: encodedBody).timeout(_timeout);
          break;
        case 'PUT':
          response = await _http.put(uri, headers: headers, body: encodedBody).timeout(_timeout);
          break;
        case 'DELETE':
          response = await _http.delete(uri, headers: headers).timeout(_timeout);
          break;
        default:
          throw ApiException(status: 0, type: 'INVALID_METHOD', title: 'Méthode invalide', detail: method);
      }
    } on TimeoutException {
      throw ApiException(
        status: 0,
        type: 'TIMEOUT',
        title: 'Le serveur ne répond pas',
        detail: "FriPay n'a pas pu joindre le serveur (${ApiConfig.baseUrl}) après ${_timeout.inSeconds}s. "
            "Vérifiez que le backend tourne sur le PC et que le téléphone est sur le même Wi-Fi.",
      );
    } catch (e) {
      if (e is ApiException) rethrow;
      throw ApiException(
        status: 0,
        type: 'NETWORK_ERROR',
        title: 'Connexion impossible',
        detail: "Impossible de joindre le serveur FriPay. Vérifiez que le backend tourne et que l'appareil "
            "est sur le même réseau (voir ApiConfig).",
      );
    }

    // Token expiré : tenter un rafraîchissement automatique une seule fois.
    if (response.statusCode == 401 && authenticated && !isRetry) {
      final refreshed = await _tryRefresh();
      if (refreshed) {
        return _send(method, path,
            query: query, body: body, authenticated: authenticated, isRetry: true, idempotencyKey: key);
      }
    }

    if (response.statusCode == 204 || response.body.isEmpty) {
      if (response.statusCode >= 200 && response.statusCode < 300) return null;
    }

    dynamic decoded;
    try {
      decoded = response.body.isNotEmpty ? jsonDecode(response.body) : null;
    } catch (_) {
      decoded = null;
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    if (decoded is Map<String, dynamic>) {
      // Deux formes d'erreur coexistent côté backend :
      // - standard : {type, title, detail} (erreurs de validation, etc.) ;
      // - métier :   {error, message}   (QR, portefeuille...).
      // On lit donc 'detail' PUIS 'message' en repli, sinon les erreurs
      // métier n'affichent qu'un générique « Erreur » à l'utilisateur.
      final detail = decoded['detail']?.toString() ??
          (decoded['message']?.toString() ?? '');
      throw ApiException(
        status: response.statusCode,
        type: decoded['type']?.toString() ?? decoded['error']?.toString() ?? 'UNKNOWN_ERROR',
        title: decoded['title']?.toString() ?? 'Erreur',
        detail: detail,
      );
    }

    throw ApiException(
      status: response.statusCode,
      type: 'UNKNOWN_ERROR',
      title: 'Erreur inattendue',
      detail: 'Le serveur a répondu avec le code ${response.statusCode}.',
    );
  }

  /// Point d'entrée appelé par toute requête qui reçoit un 401 : si un
  /// rafraîchissement est déjà en cours (déclenché par une autre requête
  /// partie en parallèle), on s'y attache au lieu d'en lancer un second.
  Future<bool> _tryRefresh() {
    return _refreshInFlight ??= _doRefresh().whenComplete(() => _refreshInFlight = null);
  }

  Future<bool> _doRefresh() async {
    final refreshToken = await TokenStorage.instance.refreshToken;
    if (refreshToken == null) return false;
    try {
      final result = await post(
        '/auth/refresh-token',
        body: {'refresh_token': refreshToken},
        authenticated: false,
      );
      if (result is Map<String, dynamic> && result['access_token'] != null) {
        await TokenStorage.instance.saveTokens(
          accessToken: result['access_token'],
          refreshToken: result['refresh_token'],
        );
        return true;
      }
    } catch (_) {
      // Refresh token invalide/expiré -> l'utilisateur devra se reconnecter.
    }
    return false;
  }
}
