import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/merchant_qr_service.dart';
import '../../services/qr_router.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';

/// Paiement d'un QR marchand (MPM) scanné — POST /qr/mpm/scan pour la
/// prévisualisation puis /qr/mpm/pay pour confirmer (PIN + compte à débiter).
/// Atteint depuis la zone de scan centrale (ScanHubScreen) quand le routeur
/// identifie un QR marchand.
class MerchantPayScreen extends StatefulWidget {
  final QrRoute route;

  const MerchantPayScreen({super.key, required this.route});

  @override
  State<MerchantPayScreen> createState() => _MerchantPayScreenState();
}

class _MerchantPayScreenState extends State<MerchantPayScreen> {
  final _amountCtrl = TextEditingController();
  final _pinCtrl = TextEditingController();

  bool _checking = true;
  bool _paying = false;
  String? _error;

  String? _uuid;
  String? _qrType;
  int? _amount;
  String? _merchantName;
  String? _description;
  List<LinkedAccount>? _accounts;
  String? _selectedAccountId;

  @override
  void initState() {
    super.initState();
    _loadAccounts();
    _checkQr();
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _pinCtrl.dispose();
    super.dispose();
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  Future<void> _loadAccounts() async {
    try {
      final accounts = await AccountService.instance.listAccounts();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
        _selectedAccountId = accounts.where((a) => a.isPrimary).firstOrNull?.id ?? accounts.firstOrNull?.id;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    }
  }

  /// POST /qr/mpm/scan — validation serveur du QR + infos marchand.
  /// Pour un QR statique sans montant pré-rempli, l'API répond
  /// `amount_required: true` : l'utilisateur saisit le montant.
  Future<void> _checkQr() async {
    setState(() => _checking = true);
    try {
      final res = await MerchantQrService.instance.scan(widget.route.rawContent);
      if (!mounted) return;
      setState(() {
        _checking = false;
        _uuid = res['uuid'] as String?;
        _qrType = (res['qr_type'] as String?) ?? (widget.route.payload['type'] as String?);
        _amount = (res['amount'] as num?)?.toInt();
        _merchantName = res['merchant_name'] as String?;
        _description = res['description'] as String?;
        if (res['amount_required'] == true) {
          _amount = null;
        }
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _checking = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _checking = false;
        _error = 'QR invalide ou expiré.';
      });
    }
  }

  Future<void> _pay() async {
    final uuid = _uuid;
    final account = _selectedAccountId;
    // QR statique : montant saisi ; QR dynamique : montant fixé par le marchand.
    final amount = _qrType == 'static' ? (int.tryParse(_amountCtrl.text) ?? 0) : (_amount ?? 0);
    final pin = _pinCtrl.text.trim();

    if (uuid == null || amount < 100) return _snack('Montant invalide (min 100 FCFA).');
    if (account == null) return _snack('Sélectionnez un compte à débiter.');
    if (pin.length < 4 || pin.length > 6) return _snack('Code PIN requis (4 à 6 chiffres).');

    setState(() => _paying = true);
    try {
      await MerchantQrService.instance.pay(uuid: uuid, amount: amount, pin: pin, senderAccountId: account);
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Paiement effectué'),
          content: Text('${formatFCFA(amount)} payés à ${_merchantName ?? 'marchand'}.'),
          actions: [ElevatedButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('OK'))],
        ),
      );
      if (!mounted) return;
      Navigator.of(context).pop(); // retour à l'écran précédent (scan hub ou autre)
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _paying = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _paying = false);
      _snack('Paiement impossible pour le moment. Réessayez.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Payer le marchand')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'Paiement marchand',
                subtitle: 'Vérifiez les informations avant de confirmer — un paiement validé ne peut pas être annulé.',
              ),
              const SizedBox(height: 20),
              if (_checking)
                const Card(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Center(child: CircularProgressIndicator()),
                  ),
                )
              else if (_error != null) ...[
                Card(
                  color: AppColors.destructive.withValues(alpha: 0.07),
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      children: [
                        const Icon(Icons.error_outline_rounded, color: AppColors.destructive, size: 34),
                        const SizedBox(height: 10),
                        Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)),
                        const SizedBox(height: 14),
                        OutlinedButton(onPressed: _checkQr, child: const Text('Réessayer')),
                      ],
                    ),
                  ),
                ),
              ] else ...[
                // Carte marchand
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      children: [
                        Container(
                          width: 54,
                          height: 54,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.1), shape: BoxShape.circle),
                          child: Text(
                            (_merchantName ?? 'M').characters.first.toUpperCase(),
                            style: GoogleFonts.sora(fontWeight: FontWeight.w800, fontSize: 20, color: AppColors.primary),
                          ),
                        ),
                        const SizedBox(height: 12),
                        Text(_merchantName ?? 'Marchand', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                        if (_description != null && _description!.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(_description!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5)),
                        ],
                        const SizedBox(height: 14),
                        Text(
                          _amount != null ? formatFCFA(_amount!) : 'Montant libre',
                          style: GoogleFonts.sora(fontWeight: FontWeight.w800, fontSize: 26, color: AppColors.primary),
                        ),
                        if (_qrType == 'dynamic')
                          const Padding(
                            padding: EdgeInsets.only(top: 4),
                            child: Text('Montant fixé par le marchand', style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5)),
                          ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                // Montant (QR statique uniquement)
                if (_qrType == 'static') ...[
                  const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _amountCtrl,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(hintText: '25 000'),
                  ),
                  const SizedBox(height: 16),
                ],
                // Compte à débiter
                const Text('Compte à débiter', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                const SizedBox(height: 8),
                if (_accounts == null)
                  const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: LinearProgressIndicator())
                else if (_accounts!.isEmpty)
                  const Text(
                    'Aucun compte mobile lié — liez-en un dans Portefeuilles.',
                    style: TextStyle(color: AppColors.destructive, fontSize: 12.5),
                  )
                else
                  ..._accounts!.map((a) => _accountTile(a)),
                const SizedBox(height: 16),
                // PIN
                const Text('Code PIN', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                const SizedBox(height: 8),
                TextField(
                  controller: _pinCtrl,
                  keyboardType: TextInputType.number,
                  obscureText: true,
                  maxLength: 6,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(hintText: '••••', counterText: ''),
                ),
                const SizedBox(height: 22),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: _paying ? null : _pay,
                    icon: _paying
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Icon(Icons.lock_rounded, size: 18),
                    label: Text(_paying ? 'Paiement…' : 'Confirmer le paiement'),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _accountTile(LinkedAccount a) {
    final selected = a.id == _selectedAccountId;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => setState(() => _selectedAccountId = a.id),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: selected ? AppColors.primary.withValues(alpha: 0.07) : AppColors.card,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.6 : 1),
          ),
          child: Row(
            children: [
              Icon(
                selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                size: 20,
                color: selected ? AppColors.primary : AppColors.mutedForeground,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  '${a.operatorCode} · ${a.msisdn}${a.isPrimary ? '  (principal)' : ''}',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
