import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../theme/app_colors.dart';
import '../../widgets/fripay_logo.dart';

/// Portage de src/routes/a-propos.tsx.
class AboutScreen extends StatelessWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    const stats = [('2024', 'Création à Cotonou'), ('27', "Personnes dans l'équipe"), ('6', 'Départements couverts')];
    const values = [
      ('Accessible', 'Une appli utilisable avec un téléphone simple, un forfait limité et un français clair.'),
      ('Honnête', 'Les frais sont annoncés avant chaque validation, jamais après.'),
      ('Locale', 'Support à Cotonou, agents de proximité et écoute des usages réels du terrain.'),
    ];
    return Scaffold(
      appBar: AppBar(title: const Text('À propos de FriPay')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 10, 20, 30),
          children: [
            const Center(child: FripayLogo(size: 44)),
            const SizedBox(height: 22),
            ClipRRect(
              borderRadius: BorderRadius.circular(24),
              child: AspectRatio(
                aspectRatio: 16 / 10,
                child: Container(
                  color: AppColors.secondary,
                  child: Image.asset('assets/images/hero-fripay.jpg', fit: BoxFit.contain),
                ),
              ),
            ),
            const SizedBox(height: 22),
            Text("Née d'un problème très concret", style: GoogleFonts.sora(fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            const Text(
              "Au Bénin, trois opérateurs se partagent le mobile et communiquent mal entre eux. "
              "Résultat : des files chez les agents, des frais doubles et des transferts qui échouent. "
              "FriPay a été fondée à Cotonou en 2024 par une équipe d'ingénieurs et d'opérateurs terrain "
              "pour rendre ces réseaux interopérables.",
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 13.5, height: 1.55),
            ),
            const SizedBox(height: 20),
            Row(
              children: stats
                  .map((s) => Expanded(
                        child: Column(
                          children: [
                            Text(s.$1, style: GoogleFonts.sora(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.primary)),
                            const SizedBox(height: 3),
                            Text(s.$2, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 10.5)),
                          ],
                        ),
                      ))
                  .toList(),
            ),
            const SizedBox(height: 28),
            Text('Nos valeurs', style: GoogleFonts.sora(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            ...values.map((v) => Card(
                  margin: const EdgeInsets.only(bottom: 10),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(v.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                        const SizedBox(height: 6),
                        Text(v.$2, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4)),
                      ],
                    ),
                  ),
                )),
          ],
        ),
      ),
    );
  }
}
