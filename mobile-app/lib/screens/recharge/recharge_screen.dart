import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../models/models.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/network_prefixes.dart';
import '../../services/token_storage.dart';
import '../../services/wallet_service.dart' as wallet_api;
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';

/// Portage de src/routes/app.recharge.tsx (Recharge / Retrait) — branché sur
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
  final _depositPhoneCtrl = TextEditingController();
  final _depositAmountCtrl = TextEditingController();
  final _withdrawPhoneCtrl = TextEditingController();
  final _withdrawAmountCtrl = TextEditingController();

  String? _phoneError;

  @override
  void initState() {
    super.initState();
    _loadUserPhone();
  }

  Future<void> _loadUserPhone() async {
    final phone = await TokenStorage.instance.phoneNumber;
    if (phone != null && mounted) {
      final national = NetworkPrefixes.nationalDigits(phone);
      setState(() {
        _depositPhoneCtrl.text = national;
        _withdrawPhoneCtrl.text = national;
      });
    }
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void dispose() {
    _tab.dispose();
    _depositPhoneCtrl.dispose();
    _depositAmountCtrl.dispose();
    _withdrawPhoneCtrl.dispose();
    _withdrawAmountCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recharge / Retrait')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'Recharge & retrait agent',
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
                  tabs: const [Tab(text: 'Recharge'), Tab(text: 'Retrait')],
                ),
              ),
              const SizedBox(height: 20),
              AnimatedBuilder(
                animation: _tab,
                builder: (context, _) => _tab.index == 0 ? _form(isDeposit: true) : _form(isDeposit: false),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _form({required bool isDeposit}) {
    final phoneCtrl = isDeposit ? _depositPhoneCtrl : _withdrawPhoneCtrl;
    final amountCtrl = isDeposit ? _depositAmountCtrl : _withdrawAmountCtrl;

    return Card(
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
                    onSelected: (_) {
                      setState(() {
                        _operator = id;
                        _phoneError = null;
                      });
                    },
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
              const Text('Numéro de téléphone', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: phoneCtrl,
                keyboardType: TextInputType.phone,
                onChanged: (_) => setState(() => _phoneError = null),
                inputFormatters: [
                  FilteringTextInputFormatter.digitsOnly,
                  LengthLimitingTextInputFormatter(NetworkPrefixes.fripayLength),
                ],
                decoration: InputDecoration(
                  hintText: '01 97 00 00 00',
                  errorText: _phoneError,
                ),
              ),
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
                onPressed: () => _submit(isDeposit: isDeposit, phoneCtrl: phoneCtrl, amountCtrl: amountCtrl),
                child: Text(isDeposit ? 'Recharger' : 'Retirer'),
              ),
            ],
          ),
        ),
      );
  }

  void _submit({
    required bool isDeposit,
    required TextEditingController phoneCtrl,
    required TextEditingController amountCtrl,
  }) {
    final phone = phoneCtrl.text.trim();
    final phoneErr = NetworkPrefixes.validationError(phone, expected: _operator);
    if (phoneErr != null) {
      setState(() => _phoneError = phoneErr);
      return;
    }

    final amount = int.tryParse(amountCtrl.text) ?? 0;
    if (amount <= 0) {
      _snack('Indiquez un montant valide.');
      return;
    }
    showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: isDeposit
          ? 'Confirmez la recharge de ${formatFCFA(amount)} via ${operatorById(_operator).name}.'
          : 'Confirmez le retrait de ${formatFCFA(amount)} via ${operatorById(_operator).name}.',
      onConfirm: (pin) async {
        try {
          if (isDeposit) {
            await wallet_api.WalletService.instance.topup(
              amount,
              pin: pin,
              phoneNumber: AuthService.normalizePhone(phone),
            );
          } else {
            await wallet_api.WalletService.instance.withdraw(
              amount,
              pin: pin,
              phoneNumber: AuthService.normalizePhone(phone),
            );
          }
        } on ApiException catch (e) {
          return e.userMessage;
        } catch (_) {
          return 'Une erreur est survenue. Réessayez.';
        }
        if (mounted) {
          _snack(isDeposit
              ? 'Recharge de ${formatFCFA(amount)} effectuée. Solde mis à jour.'
              : 'Retrait de ${formatFCFA(amount)} effectué. Solde mis à jour.');
          setState(() {
            amountCtrl.clear();
          });
        }
        return null;
      },
    );
  }
}
