import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../models/models.dart';
import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/contact_service.dart';
import '../../services/merchant_qr_service.dart';
import '../../services/transfer_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/add_contact_sheet.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';
import '../scan/qr_scan_screen.dart';
import '../wallets/wallets_screen.dart';

/// Portage de src/routes/app.envoyer.tsx — branché sur l'API réelle :
/// POST /transfers/quote (simulation des frais) puis POST /transfers
/// (initiation, PIN requis). Le compte d'envoi est un compte mobile money
/// réellement lié (GET /users/me/accounts), pas un solde FriPay mock.
class SendScreen extends StatefulWidget {
  /// Si vrai, ouvre directement l'onglet "Scanner un QR" (utilisé par
  /// l'icône QR de l'Accueil) au lieu de l'onglet "Numéro" par défaut.
  final bool startOnQrTab;

  const SendScreen({super.key, this.startOnQrTab = false});

  @override
  State<SendScreen> createState() => _SendScreenState();
}

class _SendScreenState extends State<SendScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 2, vsync: this, initialIndex: widget.startOnQrTab ? 1 : 0);
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();

  List<LinkedAccount>? _accounts;
  LinkedAccount? _sender;
  String? _accountsError;
  List<FripayContact> _contacts = [];

  TransferQuote? _quote;
  String? _quoteError;
  bool _quoting = false;
  _PendingSend? _pending;

  // ── Onglet "Scanner un QR" (paiement marchand MPM) ────────────────
  Map<String, dynamic>? _qrScanned; // résultat brut de POST /qr/mpm/scan
  String? _qrError;
  bool _qrLoading = false;
  final _qrAmountCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadAccounts();
    _loadContacts();
  }

  @override
  void dispose() {
    _tab.dispose();
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _qrAmountCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadAccounts() async {
    try {
      final accounts = await AccountService.instance.listAccounts();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
        _sender = accounts.isNotEmpty
            ? accounts.firstWhere((a) => a.isPrimary, orElse: () => accounts.first)
            : null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _accountsError = e.userMessage);
    }
  }

  Future<void> _loadContacts() async {
    try {
      final contacts = await ContactService.instance.list();
      if (!mounted) return;
      setState(() => _contacts = contacts);
    } on ApiException catch (_) {
      // Pas bloquant : les chips de raccourci disparaissent simplement.
    }
  }

  OperatorId _operatorIdFromCode(String code) => switch (code.toUpperCase()) {
        'MTN' => OperatorId.mtn,
        'MOOV' => OperatorId.moov,
        'CELTIIS' => OperatorId.celtiis,
        _ => OperatorId.fripay,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Envoyer')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: _pending != null
              ? _confirmCard()
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const SectionHeader(
                      title: "Envoyer de l'argent",
                      subtitle:
                          "Vers n'importe quel numéro FriPay, MTN, Moov ou Celtiis — ou en scannant un QR marchand.",
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
                        tabs: const [Tab(text: 'Numéro'), Tab(text: 'Scanner un QR')],
                      ),
                    ),
                    const SizedBox(height: 20),
                    SizedBox(
                      height: 520,
                      child: TabBarView(controller: _tab, children: [_manualTab(), _qrTab()]),
                    ),
                  ],
                ),
        ),
      ),
    );
  }

  Widget _manualTab() {
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text("Compte d'envoi", style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 10),
          if (_accountsError != null)
            Text(_accountsError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5))
          else if (_accounts == null)
            const Padding(padding: EdgeInsets.symmetric(vertical: 12), child: LinearProgressIndicator())
          else if (_accounts!.isEmpty)
            const Text('Aucun compte mobile money lié. Ajoutez-en un depuis Portefeuilles.',
                style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5))
          else
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: _accounts!.map((a) {
                final id = _operatorIdFromCode(a.operatorCode);
                final selected = a.id == _sender?.id;
                return InkWell(
                  onTap: () => setState(() => _sender = a),
                  borderRadius: BorderRadius.circular(14),
                  child: Container(
                    width: 155,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.6 : 1),
                      color: selected ? AppColors.primary.withValues(alpha: 0.06) : null,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      children: [
                        OperatorDot(id: id, size: 10),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(operatorById(id).name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                              Text(a.msisdn, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }).toList(),
            ),

          const SizedBox(height: 18),
          const Text('Numéro du destinataire', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _phoneCtrl,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(hintText: '+229 95 00 00 00'),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ..._contacts.take(4).map((c) {
                return ActionChip(label: Text(c.name), onPressed: () => setState(() => _phoneCtrl.text = c.phone));
              }),
              ActionChip(
                avatar: const Icon(Icons.add_rounded, size: 16),
                label: const Text('Ajouter'),
                onPressed: () async {
                  final created = await showAddContactSheet(context);
                  if (created != null && mounted) {
                    setState(() {
                      _contacts = [..._contacts, created];
                      _phoneCtrl.text = created.phone;
                    });
                  }
                },
              ),
            ],
          ),
          const SizedBox(height: 16),
          const Text('Montant (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 8),
          TextField(
            controller: _amountCtrl,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            decoration: const InputDecoration(hintText: '25 000'),
          ),
          if (_quoteError != null) ...[
            const SizedBox(height: 8),
            Text(_quoteError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
          ],
          const SizedBox(height: 20),
          ElevatedButton.icon(
            onPressed: (_quoting || _sender == null) ? null : _requestQuote,
            icon: _quoting
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.arrow_forward_rounded, size: 18),
            label: const Text('Continuer'),
          ),
          if (_accounts != null && _accounts!.isEmpty) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _goLinkAccount,
              icon: const Icon(Icons.add_card_rounded, size: 18),
              label: const Text('Lier un compte mobile money'),
            ),
          ],
        ],
      ),
    );
  }

  /// Ouvre Portefeuilles pour lier un compte, puis recharge la liste des
  /// comptes au retour — évite d'avoir à quitter/rouvrir Envoyer pour que
  /// le bouton "Continuer"/"Scanner un QR" se débloque après la liaison.
  Future<void> _goLinkAccount() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const WalletsScreen()),
    );
    if (mounted) _loadAccounts();
  }

  Widget _qrTab() {
    if (_qrScanned != null) return _qrConfirmCard();

    return SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 24),
          Container(
            width: 84,
            height: 84,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.1), shape: BoxShape.circle),
            child: const Icon(Icons.qr_code_scanner_rounded, size: 38, color: AppColors.primary),
          ),
          const SizedBox(height: 18),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 24),
            child: Text(
              'Scannez le QR code affiché par un marchand FriPay pour lui payer '
              'un montant.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 13),
            ),
          ),
          if (_qrError != null) ...[
            const SizedBox(height: 14),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Text(_qrError!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
            ),
          ],
          const SizedBox(height: 22),
          ElevatedButton.icon(
            onPressed: (_qrLoading || _sender == null) ? null : _startQrScan,
            icon: _qrLoading
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.qr_code_scanner_rounded, size: 18),
            label: Text(_qrLoading ? 'Vérification…' : 'Scanner un QR marchand'),
          ),
          if (_accounts != null && _accounts!.isEmpty) ...[
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: _goLinkAccount,
              icon: const Icon(Icons.add_card_rounded, size: 18),
              label: const Text('Lier un compte mobile money'),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _startQrScan() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const QrScanScreen()),
    );
    if (code == null || code.isEmpty || !mounted) return;

    setState(() {
      _qrLoading = true;
      _qrError = null;
    });
    try {
      final res = await MerchantQrService.instance.scan(code);
      if (!mounted) return;
      setState(() {
        _qrLoading = false;
        _qrScanned = res;
        final amount = (res['amount'] as num?)?.toInt();
        _qrAmountCtrl.text = amount != null && amount > 0 ? amount.toString() : '';
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _qrLoading = false;
        _qrError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _qrLoading = false;
        _qrError = 'QR invalide ou illisible. Réessayez.';
      });
    }
  }

  Widget _qrConfirmCard() {
    final res = _qrScanned!;
    final merchantName = (res['merchant_name'] ?? res['description'] ?? 'Marchand FriPay').toString();
    final fixedAmount = (res['amount'] as num?)?.toInt();
    final isDynamic = fixedAmount != null && fixedAmount > 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('Payer ce marchand', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                IconButton(
                  onPressed: () => setState(() {
                    _qrScanned = null;
                    _qrAmountCtrl.clear();
                    _qrError = null;
                  }),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(14)),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.12), shape: BoxShape.circle),
                    child: const Icon(Icons.storefront_rounded, color: AppColors.primary, size: 20),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(merchantName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            if (isDynamic) ...[
              const Text('Montant à payer', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 6),
              Text(formatFCFA(fixedAmount), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
            ] else ...[
              const Text('Montant à payer (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: _qrAmountCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(hintText: '5 000'),
              ),
            ],
            if (_qrError != null) ...[
              const SizedBox(height: 10),
              Text(_qrError!, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 18),
            ElevatedButton.icon(
              onPressed: _qrLoading ? null : () => _confirmQrPay(res, isDynamic ? fixedAmount : null),
              icon: _qrLoading
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check_rounded, size: 18),
              label: const Text('Continuer'),
            ),
          ],
        ),
      ),
    );
  }

  void _confirmQrPay(Map<String, dynamic> res, int? fixedAmount) {
    final amount = fixedAmount ?? int.tryParse(_qrAmountCtrl.text) ?? 0;
    if (amount <= 0) {
      setState(() => _qrError = 'Indiquez un montant valide.');
      return;
    }
    setState(() => _qrError = null);
    final uuid = res['uuid'].toString();
    showPinConfirmSheet(
      context: context,
      title: 'Code PIN requis',
      description: 'Confirmez le paiement de ${formatFCFA(amount)} par QR marchand.',
      onConfirm: (pin) async {
        try {
          await MerchantQrService.instance.pay(
            uuid: uuid,
            amount: amount,
            pin: pin,
            senderAccountId: _sender!.id,
          );
        } on ApiException catch (e) {
          return e.userMessage;
        } catch (_) {
          return 'Une erreur est survenue. Réessayez.';
        }
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Paiement de ${formatFCFA(amount)} envoyé au marchand.')),
          );
          setState(() {
            _qrScanned = null;
            _qrAmountCtrl.clear();
          });
        }
        return null;
      },
    );
  }

  Future<void> _requestQuote() async {
    final amount = double.tryParse(_amountCtrl.text) ?? 0;
    final sender = _sender;
    if (_phoneCtrl.text.trim().isEmpty || amount <= 0 || sender == null) {
      setState(() => _quoteError = 'Compte d\'envoi, numéro du destinataire et montant requis.');
      return;
    }
    setState(() {
      _quoting = true;
      _quoteError = null;
    });
    final recipientPhone = AuthService.normalizePhone(_phoneCtrl.text.trim());
    try {
      final quote = await TransferService.instance.quote(
        senderAccountId: sender.id,
        recipientPhone: recipientPhone,
        amount: amount,
      );
      if (!mounted) return;
      final contact = _contacts.where((c) => c.phone == recipientPhone).toList();
      setState(() {
        _quoting = false;
        _quote = quote;
        _pending = _PendingSend(
          phone: recipientPhone,
          name: contact.isNotEmpty ? contact.first.name : null,
          amount: amount,
        );
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _quoting = false;
        _quoteError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _quoting = false;
        _quoteError = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Widget _confirmCard() {
    final p = _pending!;
    final q = _quote!;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('Confirmer le transfert', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                IconButton(
                  onPressed: () => setState(() {
                    _pending = null;
                    _quote = null;
                  }),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(14)),
              child: Column(
                children: [
                  _row('Destinataire', p.name ?? p.phone),
                  if (p.name != null) _row('Numéro', p.phone),
                  _row('Montant', formatFCFA(p.amount.round())),
                  _row('Frais', formatFCFA(q.feeAmount.round())),
                  const Divider(height: 20),
                  _row('Total débité', formatFCFA(q.totalDebited.round()), bold: true),
                ],
              ),
            ),
            const SizedBox(height: 18),
            ElevatedButton.icon(
              onPressed: () => showPinConfirmSheet(
                context: context,
                title: 'Code PIN requis',
                description: "Confirmez l'envoi de ${formatFCFA(q.totalDebited.round())} à ${p.name ?? p.phone}.",
                onConfirm: (pin) async {
                  try {
                    await TransferService.instance.initiate(
                      quoteToken: q.quoteToken,
                      senderAccountId: _sender!.id,
                      recipientPhone: p.phone,
                      amount: p.amount,
                      pin: pin,
                    );
                  } on ApiException catch (e) {
                    return e.userMessage;
                  } catch (_) {
                    return 'Une erreur est survenue. Réessayez.';
                  }
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Transfert de ${formatFCFA(p.amount.round())} vers ${p.name ?? p.phone} en cours.')),
                    );
                    setState(() {
                      _pending = null;
                      _quote = null;
                      _phoneCtrl.clear();
                      _amountCtrl.clear();
                    });
                  }
                  return null;
                },
              ),
              icon: const Icon(Icons.check_rounded, size: 18),
              label: const Text('Confirmer'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 13)),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w700, fontSize: bold ? 15 : 13)),
        ],
      ),
    );
  }
}

class _PendingSend {
  final String phone;
  final String? name;
  final double amount;
  _PendingSend({required this.phone, this.name, required this.amount});
}
