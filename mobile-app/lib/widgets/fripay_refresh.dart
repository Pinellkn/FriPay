import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// Enveloppe standard "tirer pour rafraîchir" utilisée sur toutes les pages
/// de données de l'app (Accueil, Historique, Portefeuilles, Factures,
/// Notifications, Plaintes, Hors ligne, Profil, Accueil général...).
///
/// [child] doit être un widget scrollable (ListView / SingleChildScrollView /
/// CustomScrollView) avec `physics: AlwaysScrollableScrollPhysics()` pour que
/// le geste fonctionne même quand le contenu ne remplit pas tout l'écran.
class FripayRefresh extends StatelessWidget {
  final Widget child;
  final Future<void> Function()? onRefresh;

  const FripayRefresh({super.key, required this.child, this.onRefresh});

  Future<void> _handleRefresh() async {
    // Petit délai pour un retour visuel net, même quand il n'y a pas de
    // véritable appel réseau derrière (données locales de démonstration).
    await Future.delayed(const Duration(milliseconds: 700));
    await onRefresh?.call();
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      color: AppColors.primary,
      backgroundColor: AppColors.card,
      onRefresh: _handleRefresh,
      child: child,
    );
  }
}
