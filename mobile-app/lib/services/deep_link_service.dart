import 'package:flutter/material.dart';

import '../screens/link/link_pay_screen.dart';

/// Routage des deep links `fripay://` — ouvre l'écran correspondant par-dessus
/// la navigation existante, quel que soit l'écran affiché (Accueil, splash…).
///
/// Format supporté : `fripay://pay/{token}` — le parcours « Payer via FriPay »
/// de web1 : la page web vérifie que le numéro possède un compte puis ouvre ce
/// lien pour faire payer le lien directement depuis le solde FriPay (PIN).
///
/// Principe : un [GlobalKey<NavigatorState>] partagé permet de pousser un
/// écran SANS reconstruire l'app — le deep link arrivant pendant la session
/// (app ouverte) comme au cold start (après splash/login).
class DeepLinkService {
  DeepLinkService._();

  /// Navigator racine — initialisé dans FripayApp (main.dart).
  static final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

  /// Dernier token en attente : mémorisé si le deep link arrive avant que
  /// l'utilisateur soit connecté (cold start → splash → login) ; l'app le
  /// consomme dès que AppScaffold est affiché.
  static String? _pendingPayToken;

  static String? get pendingPayToken => _pendingPayToken;

  static void clearPending() => _pendingPayToken = null;

  /// Traite une URL entrante (URIS Android / continuation iOS).
  /// Retourne true si l'URL a été reconnue comme un deep link FriPay.
  static bool handleUri(Uri uri) {
    if (uri.scheme != 'fripay') return false;

    // fripay://pay/{token} : la forme canonique d'une URI custom met la
    // ressource dans le host. On tolère aussi fripay://pay/… et
    // fripay://pay?token=… pour les clients qui s'y prennent autrement.
    final host = uri.host.toLowerCase();
    final path = uri.path;

    String? token;
    if (host == 'pay') {
      token = path.isNotEmpty ? path.substring(1) : uri.queryParameters['token'];
    } else if (path.startsWith('/pay/')) {
      token = path.substring('/pay/'.length);
    } else if (path == '/pay') {
      token = uri.queryParameters['token'];
    }

    if (token == null || token.isEmpty) return false;
    _openPayLink(token);
    return true;
  }

  static void _openPayLink(String token) {
    final nav = navigatorKey.currentState;
    if (nav == null) {
      // L'app n'a pas encore de Navigator (cold start très précoce) : on
      // mémorise — AppScaffold consommera à son apparition.
      _pendingPayToken = token;
      return;
    }
    pushPayLinkScreen(nav.context, token);
  }

  /// Consomme le token en attente (AppScaffold à son apparition — après
  /// splash/login).
  static void consumePending(BuildContext context) {
    final token = _pendingPayToken;
    if (token == null) return;
    _pendingPayToken = null;
    pushPayLinkScreen(context, token);
  }

  static void pushPayLinkScreen(BuildContext context, String token) {
    Navigator.of(context, rootNavigator: true).push(
      MaterialPageRoute(builder: (_) => LinkPayScreen(token: token)),
    );
  }
}
