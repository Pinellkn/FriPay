import 'package:flutter/material.dart';

import '../../services/qr_router.dart';
import '../../theme/app_colors.dart';
import 'qr_scan_screen.dart';
import 'merchant_pay_screen.dart';
import '../receive/receive_qr_screen.dart';

/// Zone de scannage CENTRALE de l'app — §6 : un seul endroit pour scanner
/// n'importe quel QR FriPay. Le routeur ([QrRouter]) inspecte le payload
/// et oriente automatiquement :
/// - QR marchand (MPM)  -> MerchantPayScreen (paiement avec PIN)
/// - QR « argent » (CPM)-> ReceiveQrScreen (coffre : encaisser / transmettre)
/// - QR étranger        -> message d'erreur clair, pas de plantage.
///
/// Les zones de scan spécifiques restent disponibles : la réception d'un
/// QR argent (ReceiveQrScreen) et le paiement marchand gardent leur propre
/// accès direct à QrScanScreen quand l'utilisateur sait déjà ce qu'il
/// scanne — la zone centrale est un raccourci universel, pas une contrainte.
class ScanHubScreen extends StatefulWidget {
  const ScanHubScreen({super.key});

  @override
  State<ScanHubScreen> createState() => _ScanHubScreenState();
}

class _ScanHubScreenState extends State<ScanHubScreen> {
  /// Lance le scanner puis route le résultat selon le payload inspecté.
  /// Retourne true si un écran de destination a été poussé (le hub se
  /// referme alors pour laisser place au flux choisi).
  Future<void> _scanAndRoute() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const QrScanScreen()),
    );
    if (code == null || code.isEmpty || !mounted) return;

    final route = QrRouter.route(code);
    switch (route.kind) {
      case QrRouteKind.merchant:
        await Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => MerchantPayScreen(route: route)),
        );
        // Au retour du paiement, on reste dans le hub pour un éventuel
        // scan suivant (usage marchand : enchaîner les clients).
        if (mounted) setState(() {});
      case QrRouteKind.money:
        // ReceiveQrScreen gère lui-même le scan ; on lui passe directement
        // le contenu déjà scanné via la route « pre-scanned ».
        await Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => ReceiveQrScreen(preScannedCode: code)),
        );
        if (mounted) setState(() {});
      case QrRouteKind.unknown:
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('QR non reconnu'),
            content: Text(route.error ?? "Ce QR n'est pas un QR FriPay."),
            actions: [ElevatedButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('OK'))],
          ),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scanner')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Scanner un QR', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: AppColors.foreground)),
              const SizedBox(height: 6),
              Text(
                'Payez un marchand ou réclamez un QR argent — FriPay reconnaît automatiquement le type de QR.',
                style: TextStyle(color: AppColors.mutedForeground, fontSize: 13.5, height: 1.4),
              ),
              const SizedBox(height: 24),
              // Grande zone d'appel du scan — point d'entrée unique.
              InkWell(
                borderRadius: BorderRadius.circular(24),
                onTap: _scanAndRoute,
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: 44),
                  decoration: BoxDecoration(
                    gradient: AppColors.gradientEmerald,
                    borderRadius: BorderRadius.circular(24),
                    boxShadow: [
                      BoxShadow(
                        color: AppColors.primaryDeep.withValues(alpha: 0.25),
                        blurRadius: 22,
                        offset: const Offset(0, 10),
                      ),
                    ],
                  ),
                  child: Column(
                    children: [
                      const Icon(Icons.qr_code_scanner_rounded, size: 56, color: Colors.white),
                      const SizedBox(height: 14),
                      Text(
                        'Toucher pour scanner',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Caméra ou galerie — flash disponible',
                        style: TextStyle(color: Colors.white.withValues(alpha: 0.75), fontSize: 12),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 22),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.secondary.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.verified_rounded, size: 18, color: AppColors.primary),
                        const SizedBox(width: 8),
                        Text('Reconnaissance automatique', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'QR marchand : vous paierez avec votre code PIN après vérification du montant. '
                      'QR argent : le montant est réservé et arrive dans votre coffre — encaissez-le '
                      'quand vous voulez ou transmettez-le.',
                      style: TextStyle(color: AppColors.mutedForeground, fontSize: 12, height: 1.45),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
