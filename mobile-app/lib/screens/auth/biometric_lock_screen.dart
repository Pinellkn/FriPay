import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/biometric_service.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_scaffold.dart';
import '../../widgets/fripay_logo.dart';
import 'pin_unlock_screen.dart';

/// Écran de verrouillage biométrique — affiché au lancement de l'app
/// quand une session existe déjà ET que l'utilisateur a activé le
/// déverrouillage biométrique dans son profil. Déclenche automatiquement
/// le prompt natif (empreinte / Face ID) ; en cas d'échec ou d'annulation,
/// propose le code PIN en secours (PinUnlockScreen).
class BiometricLockScreen extends StatefulWidget {
  const BiometricLockScreen({super.key});

  @override
  State<BiometricLockScreen> createState() => _BiometricLockScreenState();
}

class _BiometricLockScreenState extends State<BiometricLockScreen> {
  bool _checking = false;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    // Laisse l'écran se dessiner avant de déclencher le prompt natif,
    // sinon il peut apparaître avant que l'UI ne soit prête.
    WidgetsBinding.instance.addPostFrameCallback((_) => _attempt());
  }

  Future<void> _attempt() async {
    setState(() {
      _checking = true;
      _failed = false;
    });
    final ok = await BiometricService.instance.authenticate(
      reason: 'Déverrouillez FriPay avec votre empreinte',
    );
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const AppScaffold()),
        (route) => false,
      );
      return;
    }
    setState(() {
      _checking = false;
      _failed = true;
    });
  }

  void _usePin() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const PinUnlockScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: AppColors.gradientEmerald),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const FripayLogo(inverted: true, size: 60),
                const SizedBox(height: 30),
                Icon(
                  Icons.fingerprint_rounded,
                  size: 84,
                  color: Colors.white.withValues(alpha: _checking ? 1 : 0.85),
                ),
                const SizedBox(height: 18),
                Text(
                  _checking ? 'Vérification en cours…' : 'Déverrouillez FriPay',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.sora(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800),
                ),
                if (_failed) ...[
                  const SizedBox(height: 8),
                  Text(
                    'Authentification annulée ou échouée.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white.withValues(alpha: 0.8), fontSize: 12.5),
                  ),
                ],
                const SizedBox(height: 28),
                if (_failed) ...[
                  ElevatedButton(
                    onPressed: _attempt,
                    style: ElevatedButton.styleFrom(backgroundColor: Colors.white, foregroundColor: AppColors.primary),
                    child: const Text('Réessayer'),
                  ),
                  const SizedBox(height: 10),
                  TextButton(
                    onPressed: _usePin,
                    child: const Text('Utiliser mon code PIN', style: TextStyle(color: Colors.white)),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
