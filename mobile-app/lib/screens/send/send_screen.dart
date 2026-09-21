import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../models/models.dart';
import '../../services/account_service.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/contact_service.dart';
import '../../services/transfer_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/add_contact_sheet.dart';
import '../../widgets/operator_dot.dart';
import '../../widgets/pin_confirm_sheet.dart';
import '../../widgets/section_header.dart';
import '../wallets/wallets_screen.dart';
import 'send_qr_screen.dart';

/// Portage de src/routes/app.envoyer.tsx — branché sur l'API réelle :
/// POST /transfers/quote (simulation des frais) puis POST /transfers
/// (initiation, PIN requis). Le compte d'envoi est un compte mobile
/// réellement lié (GET /users/me/accounts), pas un solde FriPay mock.
///
/// Consigne du boss (§5 — Etat fonctionnel de Fripay.md) : la fonction de
/// scan a été retirée de cet écran (jugée inutile) — seul l'envoi par
/// numéro subsiste ici.
class SendScreen extends StatefulWidget {
  const SendScreen({super.key});

  @override
  State<SendScreen> createState() => _SendScreenState();
}

class _SendScreenState extends State<SendScreen> {
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

  @override
  void initState() {
    super.initState();
    _loadAccounts();
    _loadContacts();
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
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
      appBar: AppBar(
        title: const Text('Envoyer'),
        actions: [
          IconButton(
            tooltip: 'Envoyer par QR',
            icon: const Icon(Icons.qr_code_2_rounded),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const SendQrScreen()),
            ),
          ),
        ],
      ),
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
                      subtitle: "Vers n'importe quel numéro FriPay, MTN, Moov ou Celtiis.",
                    ),
                    const SizedBox(height: 16),
                    _manualTab(),
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
            const Text('Aucun compte mobile lié. Ajoutez-en un depuis Portefeuilles.',
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
                              Text(operatorById(id).name,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                              Text(a.msisdn,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(color: AppColors.mutedForeground, fontSize: 11)),
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
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: (_quoting || _sender == null) ? null : _requestQuote,
              icon: _quoting
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.arrow_forward_rounded, size: 18),
              label: const Text('Continuer'),
            ),
          ),
          if (_accounts != null && _accounts!.isEmpty) ...[
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: _goLinkAccount,
                icon: const Icon(Icons.add_card_rounded, size: 18),
                label: const Text('Lier un compte mobile'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// Ouvre Portefeuilles pour lier un compte, puis recharge la liste des
  /// comptes au retour — évite d'avoir à quitter/rouvrir Envoyer pour que
  /// le bouton "Continuer" se débloque après la liaison.
  Future<void> _goLinkAccount() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const WalletsScreen()),
    );
    if (mounted) _loadAccounts();
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
                const Expanded(
                  child: Text('Confirmer le transfert',
                      overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                ),
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
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
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
          Flexible(
            child: Text(value,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.right,
                style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w700, fontSize: bold ? 15 : 13)),
          ),
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
