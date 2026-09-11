import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_client.dart';
import '../../services/bill_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';
import '../../widgets/fripay_refresh.dart';

const Map<String, IconData> _billerIcons = {
  'sbee': Icons.bolt_rounded,
  'soneb': Icons.water_drop_rounded,
  'canal': Icons.tv_rounded,
  'mtn-data': Icons.wifi_rounded,
  'moov-data': Icons.wifi_rounded,
  'scolarite': Icons.school_rounded,
};

/// Portage de src/routes/app.factures.tsx — branché sur GET /bills/billers
/// et POST /bills/pay (fripay-payments), qui débite réellement le solde
/// FriPay via WalletService.
class BillsScreen extends StatefulWidget {
  const BillsScreen({super.key});

  @override
  State<BillsScreen> createState() => _BillsScreenState();
}

class _BillsScreenState extends State<BillsScreen> {
  String? _billerId;
  final _refCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();

  List<ApiBiller> _billers = [];
  bool _loadingBillers = true;
  String? _billersError;

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void initState() {
    super.initState();
    _loadBillers();
  }

  Future<void> _loadBillers() async {
    setState(() {
      _loadingBillers = true;
      _billersError = null;
    });
    try {
      final billers = await BillService.instance.listBillers();
      if (!mounted) return;
      setState(() {
        _billers = billers;
        _loadingBillers = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _billersError = e.userMessage;
        _loadingBillers = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _billersError = 'Une erreur est survenue. Tirez pour réessayer.';
        _loadingBillers = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Factures')),
      body: SafeArea(
        child: FripayRefresh(
          onRefresh: _loadBillers,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
            children: [
              const SectionHeader(
                title: 'Payer une facture',
                subtitle: 'Électricité, eau, TV, internet ou scolarité — en un versement depuis votre solde FriPay.',
              ),
              if (_loadingBillers)
                const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Center(child: CircularProgressIndicator()))
              else if (_billersError != null)
                Text(_billersError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5))
              else
                GridView.count(
                  crossAxisCount: 3,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: 0.92,
                  children: _billers.map((b) {
                    final selected = b.id == _billerId;
                    return InkWell(
                      onTap: () => setState(() => _billerId = b.id),
                      borderRadius: BorderRadius.circular(16),
                      child: Container(
                        decoration: BoxDecoration(
                          color: selected ? AppColors.primary.withValues(alpha: 0.08) : AppColors.card,
                          border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.6 : 1),
                          borderRadius: BorderRadius.circular(16),
                        ),
                        padding: const EdgeInsets.all(10),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(_billerIcons[b.code] ?? Icons.receipt_rounded, color: AppColors.primary, size: 24),
                            const SizedBox(height: 8),
                            Text(b.name, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 11.5)),
                            Text(b.kind, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 10)),
                          ],
                        ),
                      ),
                    );
                  }).toList(),
                ),
              const SizedBox(height: 20),
              if (_billerId != null)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Numéro / référence abonné', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                        const SizedBox(height: 8),
                        TextField(controller: _refCtrl, decoration: const InputDecoration(hintText: 'Ex : 4410992')),
                        const SizedBox(height: 16),
                        const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _amountCtrl,
                          keyboardType: TextInputType.number,
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          decoration: const InputDecoration(hintText: '23 400'),
                        ),
                        const SizedBox(height: 18),
                        ElevatedButton(
                          onPressed: _onPayPressed,
                          child: const Text('Payer la facture'),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  void _onPayPressed() {
    final amount = int.tryParse(_amountCtrl.text) ?? 0;
    if (_refCtrl.text.isEmpty || amount <= 0) {
      _snack('Référence et montant requis.');
      return;
    }
    final biller = _billers.firstWhere((b) => b.id == _billerId);
    showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: 'Confirmez le paiement de ${formatFCFA(amount)} pour ${biller.name}.',
      onConfirm: (pin) async {
        try {
          await BillService.instance.pay(
            billerId: biller.id,
            subscriberReference: _refCtrl.text.trim(),
            amount: amount,
            pin: pin,
          );
        } on ApiException catch (e) {
          return e.userMessage;
        } catch (_) {
          return 'Une erreur est survenue. Réessayez.';
        }
        if (mounted) {
          _snack('Facture ${biller.name} payée (${formatFCFA(amount)}).');
          setState(() {
            _billerId = null;
            _refCtrl.clear();
            _amountCtrl.clear();
          });
        }
        return null;
      },
    );
  }
}
