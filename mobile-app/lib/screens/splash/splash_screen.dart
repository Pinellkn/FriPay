import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/auth_service.dart';
import '../../services/biometric_service.dart';
import '../../services/token_storage.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_scaffold.dart';
import '../../widgets/fripay_logo.dart';
import '../auth/biometric_lock_screen.dart';
import '../landing/landing_screen.dart';

/// Écran de démarrage — dégradé emerald plein écran avec le logo animé,
/// message de bienvenue et barre de progression, avant redirection vers
/// l'accueil général. Durée totale ~5 s pour laisser le temps de lire
/// le message sans donner l'impression que l'app rame.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with TickerProviderStateMixin {
  static const _totalDuration = Duration(milliseconds: 5000);

  late final AnimationController _logoCtrl = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 700),
  );
  late final AnimationController _progressCtrl = AnimationController(
    vsync: this,
    duration: _totalDuration,
  );
  late final Animation<double> _logoScale = CurvedAnimation(parent: _logoCtrl, curve: Curves.easeOutBack);
  late final Animation<double> _logoFade = CurvedAnimation(parent: _logoCtrl, curve: Curves.easeIn);

  final List<String> _statusMessages = const [
    'Préparation de votre espace…',
    'Connexion aux portefeuilles…',
    'Vérification de la sécurité…',
    'Presque prêt…',
  ];
  int _statusIndex = 0;

  @override
  void initState() {
    super.initState();
    _logoCtrl.forward();
    _progressCtrl.forward();

    // Fait défiler les 3 messages de statut pendant le chargement.
    final step = _totalDuration.inMilliseconds ~/ _statusMessages.length;
    for (var i = 1; i < _statusMessages.length; i++) {
      Future.delayed(Duration(milliseconds: step * i), () {
        if (!mounted) return;
        setState(() => _statusIndex = i);
      });
    }

    Future.delayed(_totalDuration, () async {
      if (!mounted) return;
      // Session déjà ouverte (access_token/refresh_token en local) ->
      // on saute l'accueil/connexion. Si le déverrouillage biométrique
      // est activé, on passe d'abord par l'écran de verrouillage (empreinte)
      // au lieu d'entrer directement — sinon accès direct comme avant.
      final loggedIn = await AuthService.instance.isLoggedIn;
      // ensureOwnedBy : si la biométrie était activée pour un compte
      // précédent sur cet appareil (session changée sans "Se déconnecter"),
      // elle est neutralisée ici plutôt que de verrouiller ce compte-ci
      // avec un réglage qu'il n'a jamais choisi.
      bool biometricEnabled = false;
      if (loggedIn) {
        final phone = await TokenStorage.instance.phoneNumber;
        biometricEnabled = phone != null && await BiometricService.instance.ensureOwnedBy(phone);
      }
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => !loggedIn
              ? const LandingScreen()
              : (biometricEnabled ? const BiometricLockScreen() : const AppScaffold()),
        ),
      );
    });
  }

  @override
  void dispose() {
    _logoCtrl.dispose();
    _progressCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppColors.gradientEmerald),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
            child: Column(
              children: [
                const Spacer(flex: 3),
                FadeTransition(
                  opacity: _logoFade,
                  child: ScaleTransition(
                    scale: _logoScale,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const FripayLogo(inverted: true, size: 76),
                        const SizedBox(height: 22),
                        Text(
                          'Bienvenue sur FriPay',
                          textAlign: TextAlign.center,
                          style: GoogleFonts.sora(
                            color: Colors.white,
                            fontSize: 22,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Paiement mobile interopérable — Bénin',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white.withValues(alpha: 0.82), fontSize: 13),
                        ),
                      ],
                    ),
                  ),
                ),
                const Spacer(flex: 2),
                AnimatedBuilder(
                  animation: _progressCtrl,
                  builder: (context, _) => ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      value: _progressCtrl.value,
                      minHeight: 5,
                      backgroundColor: Colors.white.withValues(alpha: 0.18),
                      valueColor: const AlwaysStoppedAnimation(AppColors.accent),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 300),
                  child: Text(
                    _statusMessages[_statusIndex],
                    key: ValueKey(_statusIndex),
                    style: TextStyle(color: Colors.white.withValues(alpha: 0.75), fontSize: 12.5),
                  ),
                ),
                const SizedBox(height: 6),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
