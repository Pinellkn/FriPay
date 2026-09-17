import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/offline_qr_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';

/// §6.a/b/e — Génération d'un QR "argent" : l'envoyeur fixe un montant
/// (débité immédiatement, réservé dans le QR — POST /qr/generate), et
/// optionnellement le numéro du receveur. Si ce numéro n'a pas de compte
/// FriPay, un code de validation à 5 chiffres est retourné : à transmettre
/// hors appli (WhatsApp, etc.) — c'est ce code que la page web publique
/// (§6.e) demandera au receveur sans compte.
class SendQrScreen extends StatefulWidget {
  const SendQrScreen({super.key});

  @override
  State<SendQrScreen> createState() => _SendQrScreenState();
}

class _SendQrScreenState extends State<SendQrScreen> {
  final _amountCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();

  bool _generating = false;
  bool _revoking = false;
  String? _error;
  GeneratedQr? _qr;

  @override
  void dispose() {
    _amountCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  Future<void> _generate() async {
    final amount = int.tryParse(_amountCtrl.text) ?? 0;
    if (amount < 100) {
      setState(() => _error = 'Montant minimum : 100 FCFA.');
      return;
    }
    final phone = _phoneCtrl.text.trim();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Confirmer la génération'),
        content: Text(
          '${formatFCFA(amount)} seront débités de votre solde et réservés dans ce QR '
          "jusqu'à ce qu'il soit réclamé ou annulé. Continuer ?",
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Annuler')),
          ElevatedButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('Confirmer')),
        ],
      ),
    );
    if (confirmed != true) return;

    setState(() {
      _generating = true;
      _error = null;
    });
    try {
      final qr = await OfflineQrService.instance.generate(
        amount: amount,
        recipientPhone: phone.isNotEmpty ? AuthService.normalizePhone(phone) : null,
      );
      if (!mounted) return;
      setState(() {
        _generating = false;
        _qr = qr;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _generating = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _generating = false;
        _error = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _revoke() async {
    final qr = _qr;
    if (qr == null) return;
    setState(() => _revoking = true);
    try {
      await OfflineQrService.instance.revoke(qr.uuid);
      if (!mounted) return;
      _snack('QR annulé — ${formatFCFA(qr.amount)} recrédités sur votre solde.');
      setState(() {
        _revoking = false;
        _qr = null;
        _amountCtrl.clear();
        _phoneCtrl.clear();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _revoking = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _revoking = false);
      _snack('Impossible d\'annuler ce QR pour le moment.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Envoyer par QR')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'QR argent',
                subtitle: "Le montant est réservé dans le QR jusqu'à son retrait, sans délai imposé.",
              ),
              const SizedBox(height: 20),
              _qr == null ? _formCard() : _resultCard(_qr!),
            ],
          ),
        ),
      ),
    );
  }

  Widget _formCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(
              controller: _amountCtrl,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(hintText: '25 000'),
            ),
            const SizedBox(height: 16),
            const Text('Numéro du receveur (optionnel)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(hintText: '+229 95 00 00 00'),
            ),
            const SizedBox(height: 6),
            const Text(
              "Si renseigné, l'appli détecte s'il a un compte FriPay. Sinon, un code à 5 chiffres "
              "vous sera fourni à transmettre vous-même (WhatsApp, etc.).",
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5, height: 1.4),
            ),
            if (_error != null) ...[
              const SizedBox(height: 10),
              Text(_error!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 18),
            ElevatedButton.icon(
              onPressed: _generating ? null : _generate,
              icon: _generating
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.qr_code_2_rounded, size: 18),
              label: Text(_generating ? 'Génération…' : 'Générer le QR'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _resultCard(GeneratedQr qr) {
    final size = MediaQuery.of(context).size;
    final qrSize = size.width * 0.55;
    final needsExternalCode = qr.hasRecipientAccount == false && qr.externalValidationCode != null;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Container(
              width: qrSize + 30,
              height: qrSize + 30,
              padding: const EdgeInsets.all(14),
              alignment: Alignment.center,
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
              child: QrImageView(
                data: qr.qrCode,
                size: qrSize,
                eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.primaryDeep),
                dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: AppColors.primaryDeep),
              ),
            ),
            const SizedBox(height: 14),
            Text(formatFCFA(qr.amount), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
            Text('Réservé jusqu\'au retrait ou à l\'annulation', style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12)),
            if (qr.hasRecipientAccount == true) ...[
              const SizedBox(height: 10),
              const Text('✅ Le receveur a un compte FriPay — il pourra le scanner directement.',
                  textAlign: TextAlign.center, style: TextStyle(fontSize: 12.5, color: AppColors.primary, fontWeight: FontWeight.w600)),
            ],
            if (needsExternalCode) ...[
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(14)),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text("Le receveur n'a pas de compte FriPay",
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
                    const SizedBox(height: 6),
                    const Text(
                      "Transmettez-lui vous-même ce code (WhatsApp, SMS…). Il en aura besoin sur la "
                      "page web qui s'ouvrira quand il scannera le QR.",
                      style: TextStyle(color: AppColors.mutedForeground, fontSize: 12, height: 1.4),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: Container(
                            alignment: Alignment.center,
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            decoration: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(10)),
                            child: Text(qr.externalValidationCode!,
                                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20, letterSpacing: 4)),
                          ),
                        ),
                        const SizedBox(width: 8),
                        IconButton(
                          onPressed: () {
                            Clipboard.setData(ClipboardData(text: qr.externalValidationCode!));
                            _snack('Code copié');
                          },
                          icon: const Icon(Icons.copy_rounded),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 18),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                OutlinedButton.icon(
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: qr.qrCode));
                    _snack('Contenu du QR copié');
                  },
                  icon: const Icon(Icons.copy_all_rounded, size: 16),
                  label: const Text('Copier le QR'),
                ),
                TextButton.icon(
                  onPressed: _revoking ? null : _revoke,
                  icon: _revoking
                      ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.cancel_outlined, size: 16, color: AppColors.destructive),
                  label: const Text('Annuler et recréditer', style: TextStyle(color: AppColors.destructive)),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
