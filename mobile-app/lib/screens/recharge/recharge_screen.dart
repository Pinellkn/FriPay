import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/wallet_service.dart' as wallet_api;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';

/// Portage de src/routes/app.recharge.tsx (Dépôt / Retrait) — branché sur
/// POST /wallet/topup et POST /wallet/withdraw (fripay-payments).
///
/// ⚠️ Il n'existe pas encore de vrai réseau d'agents FriPay : le champ
/// "Code agent" n'est donc PAS validé contre un registre — n'importe quelle
/// valeur (ou aucune) est acceptée, il est juste gardé à titre indicatif
/// dans l'historique. Le dépôt/retrait est débité/crédité immédiatement sur
/// le solde FriPay dès validation du PIN (mode dev/test, voir README).
class RechargeScreen extends StatefulWidget {
  const RechargeScreen({super.key});

  @override
  State<RechargeScreen> createState() => _RechargeScreenState();
}

class _RechargeScreenState extends State<RechargeScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 2, vsync: this);
  OperatorId _operator = OperatorId.mtn;

  // Contrôleurs séparés par onglet : TabBarView construit les deux formulaires
  // en même temps, partager un seul contrôleur ferait fuiter le texte saisi
  // d'un onglet vers l'autre.
  final _depositAgentCtrl = TextEditingController();
  final _depositAmountCtrl = TextEditingController();
  final _withdrawAgentCtrl = TextEditingController();
  final _withdrawAmountCtrl = TextEditingController();

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void dispose() {
    _tab.dispose();
    _depositAgentCtrl.dispose();
    _depositAmountCtrl.dispose();
    _withdrawAgentCtrl.dispose();
    _withdrawAmountCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Dépôt / Retrait')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'Dépôt & retrait agent',
                subtitle: "Alimentez votre solde FriPay ou retirez du cash chez un agent partenaire.",
              ),
              Container(
                decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(999)),
                padding: const EdgeInsets.all(4),
                child: TabBar(
                  controller: _tab,
                  indicator: BoxDecoration(color: AppColors.card, borderRadius: BorderRadius.circular(999)),
                  labelColor: AppColors.foreground,
                  unselectedLabelColor: AppColors.mutedForeground,
                  dividerColor: Colors.transparent,
                  onTap: (_) => setState(() {}), // pour rafraîchir le libellé du bouton "Continuer"
                  tabs: const [Tab(text: 'Dépôt'), Tab(text: 'Retrait')],
                ),
              ),
              const SizedBox(height: 20),
              SizedBox(
                height: 420,
                child: TabBarView(
                  controller: _tab,
                  children: [_form(isDeposit: true), _form(isDeposit: false)],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _form({required bool isDeposit}) {
    final agentCtrl = isDeposit ? _depositAgentCtrl : _withdrawAgentCtrl;
    final amountCtrl = isDeposit ? _depositAmountCtrl : _withdrawAmountCtrl;

    return SingleChildScrollView(
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Réseau', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 10),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [OperatorId.mtn, OperatorId.moov, OperatorId.celtiis].map((id) {
                  final selected = id == _operator;
                  return ChoiceChip(
                    selected: selected,
                    onSelected: (_) => setState(() => _operator = id),
                    avatar: OperatorDot(id: id, size: 8),
                    label: Text(operatorById(id).short),
                    selectedColor: AppColors.primary.withValues(alpha: 0.14),
                    labelStyle: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: selected ? AppColors.primary : AppColors.foreground,
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 16),
              Text('Code agent (optionnel)', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(controller: agentCtrl, decoration: const InputDecoration(hintText: 'Ex : Agent #2214 · Godomey')),
              const SizedBox(height: 16),
              const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: amountCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(hintText: '50 000'),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [10000, 25000, 50000, 100000].map((v) {
                  return ActionChip(label: Text(formatFCFA(v)), onPressed: () => setState(() => amountCtrl.text = '$v'));
                }).toList(),
              ),
              const SizedBox(height: 20),
              ElevatedButton(
                onPressed: () => _submit(isDeposit: isDeposit, agentCtrl: agentCtrl, amountCtrl: amountCtrl),
                child: Text(isDeposit ? 'Déposer' : 'Retirer'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _submit({
    required bool isDeposit,
    required TextEditingController agentCtrl,
    required TextEditingController amountCtrl,
  }) {
    final amount = int.tryParse(amountCtrl.text) ?? 0;
    if (amount <= 0) {
      _snack('Indiquez un montant valide.');
      return;
    }
    showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: isDeposit
          ? 'Confirmez le dépôt de ${formatFCFA(amount)} via ${operatorById(_operator).name}.'
          : 'Confirmez le retrait de ${formatFCFA(amount)} via ${operatorById(_operator).name}.',
      onConfirm: (pin) async {
        try {
          if (isDeposit) {
            await wallet_api.WalletService.instance.topup(
              amount,
              pin: pin,
              agentCode: agentCtrl.text.trim().isEmpty ? null : agentCtrl.text.trim(),
            );
          } else {
            await wallet_api.WalletService.instance.withdraw(
              amount,
              pin: pin,
              agentCode: agentCtrl.text.trim().isEmpty ? null : agentCtrl.text.trim(),
            );
          }
        } on ApiException catch (e) {
          return e.userMessage;
        } catch (_) {
          return 'Une erreur est survenue. Réessayez.';
        }
        if (mounted) {
          _snack(isDeposit
              ? 'Dépôt de ${formatFCFA(amount)} effectué. Solde mis à jour.'
              : 'Retrait de ${formatFCFA(amount)} effectué. Solde mis à jour.');
          setState(() {
            agentCtrl.clear();
            amountCtrl.clear();
          });
        }
        return null;
      },
    );
  }
}
