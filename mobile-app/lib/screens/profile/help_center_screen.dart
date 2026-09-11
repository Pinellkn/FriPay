import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../theme/app_colors.dart';

/// Portage de src/routes/faq.tsx — Centre d'aide (questions fréquentes +
/// contact rapide).
class HelpCenterScreen extends StatelessWidget {
  const HelpCenterScreen({super.key});

  static const _faqs = [
    ("Dois-je changer d'opérateur ?",
        'Non. Vous gardez vos numéros MTN, Moov ou Celtiis. FriPay les relie simplement à un compte unique.'),
    ('Combien coûte un transfert inter-opérateurs ?',
        '0,9 % du montant, avec un minimum de 25 FCFA. Entre deux comptes FriPay, c\'est 0,5 %.'),
    ('Comment fonctionne le mode hors ligne ?',
        "L'opération est signée sur votre téléphone puis transmise par USSD (*880#) ou SMS chiffré dès "
            "qu'un signal GSM est disponible. La synchronisation évite tout double débit."),
    ('Que se passe-t-il si je perds mon téléphone ?',
        'Composez *880*0*0# ou appelez le 01 40 00 00 00 pour suspendre le compte. Vos fonds restent '
            'cantonnés chez la banque partenaire.'),
    ('Quels sont les plafonds ?',
        '500 000 FCFA par jour en KYC niveau 2. Vous pouvez fixer une limite plus basse depuis votre profil.'),
    ('FriPay est-il agréé ?',
        'FriPay opère avec une banque partenaire et suit une procédure d\'agrément d\'établissement de '
            'paiement auprès de la BCEAO.'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text("Centre d'aide")),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 6, 18, 30),
          children: [
            Text('Questions fréquentes', style: GoogleFonts.sora(fontSize: 19, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            Container(
              decoration: BoxDecoration(
                color: AppColors.card,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppColors.border.withValues(alpha: 0.7)),
              ),
              child: Theme(
                data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                child: Column(
                  children: List.generate(_faqs.length, (i) {
                    final f = _faqs[i];
                    return Container(
                      decoration: BoxDecoration(
                        border: i == _faqs.length - 1
                            ? null
                            : const Border(bottom: BorderSide(color: AppColors.border, width: 0.6)),
                      ),
                      child: ExpansionTile(
                        tilePadding: const EdgeInsets.symmetric(horizontal: 14),
                        childrenPadding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
                        title: Text(f.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                        expandedAlignment: Alignment.topLeft,
                        children: [
                          Align(
                            alignment: Alignment.topLeft,
                            child: Text(f.$2, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.5)),
                          ),
                        ],
                      ),
                    );
                  }),
                ),
              ),
            ),
            const SizedBox(height: 26),
            Text('Besoin de parler à quelqu\'un ?', style: GoogleFonts.sora(fontSize: 16, fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _ContactRow(icon: Icons.call_rounded, label: 'Appeler le support', value: '01 40 00 00 00'),
                    const Divider(height: 22),
                    _ContactRow(icon: Icons.mail_outline_rounded, label: 'Écrire par e-mail', value: 'support@fripay.bj'),
                    const Divider(height: 22),
                    _ContactRow(icon: Icons.chat_bubble_outline_rounded, label: 'Chat en direct', value: 'Tous les jours, 8h–20h'),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ContactRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _ContactRow({required this.icon, required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: AppColors.primary, size: 20),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 2),
              Text(value, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12)),
            ],
          ),
        ),
      ],
    );
  }
}
