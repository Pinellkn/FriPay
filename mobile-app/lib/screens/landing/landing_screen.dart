import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../models/models.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/fripay_logo.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/fripay_refresh.dart';
import '../auth/login_screen.dart';

/// Page d'accueil GÉNÉRALE de l'application (portage de src/routes/index.tsx
/// du web) — affichée avant la connexion. Distincte du tableau de bord
/// (`HomeScreen`) qui lui s'affiche une fois l'utilisateur connecté.
///
/// Consigne respectée : toutes les images sont montrées en INTÉGRALITÉ
/// (BoxFit.contain dans un cadre au format fixe), jamais rognées, quel que
/// soit le téléphone.
class LandingScreen extends StatelessWidget {
  const LandingScreen({super.key});

  void _openLogin(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LoginScreen()));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      body: SafeArea(
        child: FripayRefresh(
          child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _TopBar(onLogin: () => _openLogin(context)),
              _Hero(onCta: () => _openLogin(context)),
              const SizedBox(height: 28),
              const _StatsStrip(),
              const SizedBox(height: 8),
              const _StepsSection(),
              const SizedBox(height: 8),
              const _FeaturesSection(),
              const SizedBox(height: 8),
              _MerchantSection(onCta: () => _openLogin(context)),
              const SizedBox(height: 30),
            ],
          ),
          ),
        ),
      ),
    );
  }
}

/// Cadre qui affiche une image en entier (jamais rognée), quelle que soit
/// la largeur de l'écran : le ratio est fixé et l'image utilise `contain`.
class _FullImage extends StatelessWidget {
  final String asset;
  final double ratio;
  final BorderRadius radius;
  const _FullImage({required this.asset, this.ratio = 4 / 5, this.radius = const BorderRadius.all(Radius.circular(24))});

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: radius,
      child: AspectRatio(
        aspectRatio: ratio,
        child: Container(
          color: AppColors.secondary,
          child: Image.asset(asset, fit: BoxFit.contain),
        ),
      ),
    );
  }
}

class _TopBar extends StatelessWidget {
  final VoidCallback onLogin;
  const _TopBar({required this.onLogin});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 10, 14, 4),
      child: Row(
        children: [
          const FripayLogo(),
          const Spacer(),
          TextButton(
            onPressed: onLogin,
            child: const Text('Se connecter', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  final VoidCallback onCta;
  const _Hero({required this.onCta});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(
              color: AppColors.accent.withValues(alpha: 0.14),
              border: Border.all(color: AppColors.accent.withValues(alpha: 0.4)),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.bolt_rounded, size: 14, color: AppColors.accentForeground),
                const SizedBox(width: 6),
                Text('Interopérabilité mobile money · Bénin',
                    style: GoogleFonts.manrope(fontSize: 11.5, fontWeight: FontWeight.w700, color: AppColors.accentForeground)),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Text.rich(
            TextSpan(
              children: [
                TextSpan(
                  text: 'MTN, Moov et Celtiis.\n',
                  style: GoogleFonts.sora(fontSize: 30, fontWeight: FontWeight.w800, height: 1.12, color: AppColors.foreground),
                ),
                TextSpan(
                  text: 'Une seule application.',
                  style: GoogleFonts.sora(fontSize: 30, fontWeight: FontWeight.w800, height: 1.12, color: AppColors.primary),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Text(
            "Au Bénin, envoyer de l'argent d'un opérateur à un autre reste compliqué. FriPay relie tous vos "
            'portefeuilles, calcule les frais les plus bas et sécurise chaque transfert par QR code.',
            style: GoogleFonts.manrope(fontSize: 14.5, color: AppColors.mutedForeground, height: 1.5),
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: onCta,
                  icon: const Icon(Icons.arrow_forward_rounded, size: 17),
                  label: const Text('Essayer la démo'),
                  style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),
          Stack(
            clipBehavior: Clip.none,
            children: [
              _FullImage(asset: 'assets/images/hero-fripay.jpg'),
              Positioned(
                left: 14,
                right: 14,
                bottom: -22,
                child: Card(
                  elevation: 3,
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      children: [
                        Container(
                          width: 38,
                          height: 38,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.1),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.swap_horiz_rounded, color: AppColors.primary, size: 19),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('Transfert MTN → Moov', style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                              Text(formatFCFA(15000),
                                  style: GoogleFonts.sora(fontWeight: FontWeight.w800, fontSize: 17)),
                              const Text('Reçu en 4 s · frais 135 FCFA',
                                  style: TextStyle(color: AppColors.success, fontSize: 11, fontWeight: FontWeight.w700)),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 38),
          Wrap(
            spacing: 18,
            runSpacing: 8,
            children: [
              ...operators.take(3).map((op) => Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      OperatorDot(id: op.id),
                      const SizedBox(width: 6),
                      Text(op.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5)),
                    ],
                  )),
              const Text('+ banques partenaires', style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
            ],
          ),
        ],
      ),
    );
  }
}

class _StatsStrip extends StatelessWidget {
  const _StatsStrip();

  @override
  Widget build(BuildContext context) {
    const items = [
      ('3', 'opérateurs reliés en un compte'),
      ('< 5 s', "délai moyen d'un transfert croisé"),
      ('100 %', 'des opérations disponibles hors data'),
    ];
    return Container(
      color: AppColors.secondary.withValues(alpha: 0.5),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 22),
      child: Column(
        children: items
            .map((s) => Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: Row(
                    children: [
                      Text(s.$1, style: GoogleFonts.sora(fontSize: 26, fontWeight: FontWeight.w800, color: AppColors.primary)),
                      const SizedBox(width: 14),
                      Expanded(child: Text(s.$2, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5))),
                    ],
                  ),
                ))
            .toList(),
      ),
    );
  }
}

class _StepsSection extends StatelessWidget {
  static const steps = [
    ('Reliez vos numéros', 'Ajoutez vos comptes MTN MoMo, Moov Money et Celtiis Cash en une minute avec un simple code de confirmation.'),
    ('Un solde unifié', 'FriPay agrège vos soldes et choisit automatiquement le portefeuille le moins cher pour chaque opération.'),
    ('Envoyez partout', 'MTN vers Moov, Celtiis vers MTN : le routage inter-opérateurs se fait en arrière-plan, en quelques secondes.'),
  ];
  const _StepsSection();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 26, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Trois étapes, zéro friction', style: GoogleFonts.sora(fontSize: 21, fontWeight: FontWeight.w800)),
          const SizedBox(height: 16),
          ...List.generate(steps.length, (i) {
            final s = steps[i];
            return Card(
              margin: const EdgeInsets.only(bottom: 12),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 34,
                      height: 34,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(11)),
                      child: Text('${i + 1}', style: GoogleFonts.sora(fontWeight: FontWeight.w800, color: AppColors.primary)),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                          const SizedBox(height: 4),
                          Text(s.$2, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
        ],
      ),
    );
  }
}

class _FeaturesSection extends StatelessWidget {
  const _FeaturesSection();

  @override
  Widget build(BuildContext context) {
    const features = [
      (Icons.repeat_rounded, 'Transfert inter-opérateurs', "Un numéro suffit. FriPay détecte l'opérateur du destinataire et route la transaction."),
      (Icons.qr_code_2_rounded, 'Envoi par QR code', "Générez un QR sécurisé : le destinataire le réclame quand il veut, même sans compte FriPay."),
      (Icons.qr_code_rounded, 'Paiement marchand', "Payez par QR, sans frais pour le commerçant sous 10 000 FCFA."),
      (Icons.receipt_long_rounded, 'Factures & forfaits', 'SBEE, SONEB, Canal+, recharges et forfaits data depuis le même écran.'),
      (Icons.groups_rounded, 'Tontines & groupes', 'Cagnottes familiales et tontines de quartier avec rappels automatiques.'),
      (Icons.verified_user_rounded, 'Sécurité BCEAO', 'Chiffrement de bout en bout, PIN à 5 chiffres, biométrie et limites paramétrables.'),
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 30, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text("Tout ce dont un compte a besoin", style: GoogleFonts.sora(fontSize: 21, fontWeight: FontWeight.w800)),
          const SizedBox(height: 16),
          LayoutBuilder(builder: (context, constraints) {
            final double childAspectRatio = constraints.maxWidth < 360 ? 0.8 : 0.92;
            return GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: childAspectRatio,
              children: features
                  .map((f) => Card(
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Icon(f.$1, color: AppColors.primary, size: 22),
                              const SizedBox(height: 10),
                              Text(f.$2, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13), maxLines: 2, overflow: TextOverflow.ellipsis),
                              const SizedBox(height: 5),
                              Expanded(
                                child: Text(f.$3,
                                    style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11, height: 1.35),
                                    maxLines: 4, overflow: TextOverflow.ellipsis),
                              ),
                            ],
                          ),
                        ),
                      ))
                  .toList(),
            );
          }),
        ],
      ),
    );
  }
}

class _MerchantSection extends StatelessWidget {
  final VoidCallback onCta;
  const _MerchantSection({required this.onCta});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 30, 20, 0),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.card,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
        ),
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Pour les marchands aussi', style: GoogleFonts.sora(fontSize: 19, fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            const Text(
              'Un QR imprimé, un compte FriPay Business, et vous encaissez MTN, Moov ou Celtiis sans changer '
              'de terminal. Reversement quotidien vers votre portefeuille ou votre banque.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 13, height: 1.5),
            ),
            const SizedBox(height: 16),
            _FullImage(asset: 'assets/images/merchant-qr.jpg', ratio: 4 / 3, radius: BorderRadius.circular(18)),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(onPressed: onCta, child: const Text('Devenir marchand partenaire')),
            ),
          ],
        ),
      ),
    );
  }
}
