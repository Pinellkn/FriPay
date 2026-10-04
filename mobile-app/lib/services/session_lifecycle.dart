import 'dart:async';

/// Cycle de vie de la session côté app : prévient quand la session est
/// DÉFINITIVEMENT morte (401 persistant + refresh token impossible) pour
/// que l'UI puisse rediriger vers l'écran de connexion au lieu de laisser
/// chaque écran afficher l'erreur brute « Unauthenticated. » du backend.
///
/// Le callback est câblé dans main.dart (navigation root via
/// DeepLinkService.navigatorKey) — ApiClient ne dépend d'aucun widget.
class SessionLifecycle {
  SessionLifecycle._();
  static final SessionLifecycle instance = SessionLifecycle._();

  final _controller = StreamController<void>.broadcast();
  bool _handling = false;

  /// Notifie que la session est morte (émission ignorée si déjà en cours
  /// de traitement — plusieurs requêtes en parallèle peuvent l'observer
  /// en même temps, une seule redirection doit avoir lieu).
  void notifySessionExpired() {
    if (_handling) return;
    _handling = true;
    _controller.add(null);
  }

  /// Marque la redirection comme terminée (appelé par l'UI après y être
  /// arrivée), ré-arme la détection pour une session suivante.
  void completeHandling() => _handling = false;

  /// Stream des expirations de session (broadcast : plusieurs écouteurs OK).
  Stream<void> get onSessionExpired => _controller.stream;
}
