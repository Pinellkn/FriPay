import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';
import '../../widgets/fripay_refresh.dart';

const _ussdCodes = [
  ('*880#', 'Menu principal'),
  ('*880*1#', 'Consulter le solde unifié'),
  ('*880*2*numéro*montant#', "Envoyer de l'argent"),
  ('*880*3#', 'Retirer chez un agent'),
  ('*880*9#', 'Valider une demande de paiement'),
];

/// Portage de src/routes/app.hors-ligne.tsx.
class OfflineScreen extends StatefulWidget {
  const OfflineScreen({super.key});

  @override
  State<OfflineScreen> createState() => _OfflineScreenState();
}

class _OfflineScreenState extends State<OfflineScreen> {
  bool _offline = true;
  bool _sms = true;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mode hors ligne')),
      body: SafeArea(
        child: FripayRefresh(
          child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          children: [
            const SectionHeader(
              title: 'Mode hors ligne',
              subtitle: 'Continuez à payer et à envoyer même quand la connexion data disparaît.',
            ),
            ClipRRect(
              borderRadius: BorderRadius.circular(20),
              child: Image.asset('assets/images/offline-ussd.jpg', height: 140, width: double.infinity, fit: BoxFit.cover),
            ),
            const SizedBox(height: 16),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    _toggleRow(
                      icon: Icons.wifi_off_rounded,
                      title: 'Activer le mode hors ligne',
                      subtitle: "Les opérations sont signées localement puis envoyées par USSD dès qu'un signal GSM est détecté.",
                      value: _offline,
                      onChanged: (v) => setState(() => _offline = v),
                    ),
                    const Divider(height: 28),
                    _toggleRow(
                      icon: Icons.sms_rounded,
                      title: 'Confirmations par SMS chiffré',
                      subtitle: 'Le destinataire reçoit un reçu signé, vérifiable même sans internet.',
                      value: _sms,
                      onChanged: (v) => setState(() => _sms = v),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text("File d'attente", style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
                        OutlinedButton.icon(
                          onPressed: () => ScaffoldMessenger.of(context)
                              .showSnackBar(const SnackBar(content: Text('File synchronisée — 1 opération envoyée'))),
                          icon: const Icon(Icons.cloud_upload_rounded, size: 15),
                          label: const Text('Synchroniser', style: TextStyle(fontSize: 12.5)),
                          style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36), padding: const EdgeInsets.symmetric(horizontal: 12)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(color: AppColors.secondary.withValues(alpha: 0.6), borderRadius: BorderRadius.circular(14)),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('Transfert à Mariam Bio', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                                const SizedBox(height: 2),
                                const Text('Q-1188 · 12 juil. · 11:20', style: TextStyle(color: AppColors.mutedForeground, fontSize: 10.5)),
                                const SizedBox(height: 4),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                  decoration: BoxDecoration(color: AppColors.accent.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(999)),
                                  child: const Text('signé, en attente de réseau', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.accentForeground)),
                                ),
                              ],
                            ),
                          ),
                          Text(formatFCFA(7500), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(gradient: AppColors.gradientEmerald, borderRadius: BorderRadius.circular(22)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.tag_rounded, color: Colors.white, size: 22),
                  const SizedBox(height: 12),
                  Text('Codes USSD FriPay', style: GoogleFonts.sora(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  Text(
                    "Sans smartphone ni data, composez ces codes depuis n'importe quel téléphone.",
                    style: TextStyle(color: Colors.white.withValues(alpha: 0.78), fontSize: 12.5),
                  ),
                  const SizedBox(height: 14),
                  ..._ussdCodes.map((u) => Container(
                        margin: const EdgeInsets.only(bottom: 8),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
                        child: Row(
                          children: [
                            Text(u.$1, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12, fontFamily: 'monospace')),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(u.$2, textAlign: TextAlign.right, style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 11)),
                            ),
                          ],
                        ),
                      )),
                ],
              ),
            ),
          ],
          ),
        ),
      ),
    );
  }

  Widget _toggleRow({
    required IconData icon,
    required String title,
    required String subtitle,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: AppColors.primary, size: 20),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
              const SizedBox(height: 3),
              Text(subtitle, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11.5, height: 1.3)),
            ],
          ),
        ),
        Switch(value: value, onChanged: onChanged, activeThumbColor: AppColors.primary),
      ],
    );
  }
}
