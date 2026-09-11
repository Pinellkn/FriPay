import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/contact_service.dart';
import '../../services/merchant_qr_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/add_contact_sheet.dart';
import '../../widgets/section_header.dart';

/// Portage de src/routes/app.recevoir.tsx — onglet "Mon QR" branché sur
/// POST /qr/mpm/generate (QR statique : le payeur saisit le montant, comme
/// pour un QR marchand classique) et GET /users/me (identité réelle).
class ReceiveScreen extends StatefulWidget {
  const ReceiveScreen({super.key});

  @override
  State<ReceiveScreen> createState() => _ReceiveScreenState();
}

class _ReceiveScreenState extends State<ReceiveScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab = TabController(length: 2, vsync: this);
  final _whoCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();

  MerchantQr? _merchantQr;
  String _displayName = '…';
  String _phone = '';
  bool _regenerating = false;
  String? _qrError;
  bool _firstLoad = true;
  List<FripayContact> _contacts = [];

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  @override
  void initState() {
    super.initState();
    _loadIdentity();
    _regenerate();
    _loadContacts();
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

  Future<void> _loadIdentity() async {
    try {
      final me = await AuthService.instance.getMe();
      final first = (me['first_name'] as String?)?.trim() ?? '';
      final last = (me['last_name'] as String?)?.trim() ?? '';
      final full = [first, last].where((s) => s.isNotEmpty).join(' ');
      if (!mounted) return;
      setState(() {
        _displayName = full.isNotEmpty ? full : '…';
        _phone = (me['phone_number'] as String?) ?? '';
      });
    } on ApiException catch (_) {
      // On garde le placeholder si l'appel échoue (session/réseau).
    }
  }

  Future<void> _regenerate() async {
    setState(() {
      _regenerating = true;
      _qrError = null;
    });
    try {
      final qr = await MerchantQrService.instance.generateStatic();
      if (!mounted) return;
      setState(() {
        _merchantQr = qr;
        _regenerating = false;
      });
      if (!_firstLoad) _snack("QR régénéré — l'ancien n'est plus valide");
      _firstLoad = false;
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _regenerating = false;
        _qrError = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _regenerating = false;
        _qrError = 'Impossible de générer le QR pour le moment.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recevoir')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: "Recevoir de l'argent",
                subtitle:
                    "Partagez votre QR FriPay ou envoyez une demande de paiement, quel que soit l'opérateur du payeur.",
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
                  tabs: const [Tab(text: 'Mon QR'), Tab(text: 'Demande de paiement')],
                ),
              ),
              const SizedBox(height: 20),
              SizedBox(
                height: _tab.index == 0 ? 720 : 380,
                child: TabBarView(
                  controller: _tab,
                  children: [_qrTab(), _requestTab()],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _qrTab() {
    return SingleChildScrollView(
      child: Column(
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  if (_qrError != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(_qrError!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
                    ),
                  Container(
                    width: 232,
                    height: 232,
                    padding: const EdgeInsets.all(16),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
                    child: _merchantQr == null
                        ? (_regenerating
                            ? const CircularProgressIndicator()
                            : const Icon(Icons.qr_code_2_rounded, size: 48, color: AppColors.mutedForeground))
                        : QrImageView(
                            data: _merchantQr!.qrCode,
                            size: 200,
                            eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.primaryDeep),
                            dataModuleStyle: const QrDataModuleStyle(
                                dataModuleShape: QrDataModuleShape.square, color: AppColors.primaryDeep),
                          ),
                  ),
                  const SizedBox(height: 16),
                  Text(_displayName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  if (_phone.isNotEmpty)
                    Text(_phone, style: const TextStyle(color: AppColors.mutedForeground, fontSize: 13)),
                  const SizedBox(height: 18),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    alignment: WrapAlignment.center,
                    children: [
                      OutlinedButton.icon(
                        onPressed: _merchantQr == null
                            ? null
                            : () {
                                Clipboard.setData(ClipboardData(text: _merchantQr!.qrCode));
                                _snack('Jeton QR copié');
                              },
                        icon: const Icon(Icons.copy_rounded, size: 16),
                        label: const Text('Copier'),
                        style: OutlinedButton.styleFrom(minimumSize: const Size(0, 42)),
                      ),
                      ElevatedButton.icon(
                        onPressed: _merchantQr == null ? null : () => _snack('Lien de paiement partagé'),
                        icon: const Icon(Icons.share_rounded, size: 16),
                        label: const Text('Partager'),
                        style: ElevatedButton.styleFrom(minimumSize: const Size(0, 42)),
                      ),
                      TextButton.icon(
                        onPressed: _regenerating ? null : _regenerate,
                        icon: _regenerating
                            ? const SizedBox(
                                width: 14,
                                height: 14,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.refresh_rounded, size: 16),
                        label: const Text('Régénérer'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Card(
            color: AppColors.secondary.withValues(alpha: 0.5),
            child: const Padding(
              padding: EdgeInsets.all(18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text("Encaissez depuis n'importe quel réseau",
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
                  SizedBox(height: 8),
                  Text(
                    "Un client MTN, Moov ou Celtiis peut scanner ce QR et saisir le montant à vous envoyer, "
                    "quel que soit son opérateur.",
                    style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _requestTab() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Numéro du payeur', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(controller: _whoCtrl, decoration: const InputDecoration(hintText: '+229 95 00 00 00')),
            const SizedBox(height: 8),
            if (_contacts.isNotEmpty)
              Wrap(
                spacing: 8,
                children: _contacts.take(3).map((c) {
                  return ActionChip(label: Text(c.name), onPressed: () => setState(() => _whoCtrl.text = c.phone));
                }).toList(),
              )
            else
              Align(
                alignment: Alignment.centerLeft,
                child: ActionChip(
                  avatar: const Icon(Icons.add_rounded, size: 16),
                  label: const Text('Ajouter un contact'),
                  onPressed: () async {
                    final created = await showAddContactSheet(context);
                    if (created != null && mounted) {
                      setState(() {
                        _contacts = [..._contacts, created];
                        _whoCtrl.text = created.phone;
                      });
                    }
                  },
                ),
              ),
            const SizedBox(height: 16),
            const Text('Montant demandé (FCFA)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(
              controller: _amountCtrl,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(hintText: '25 000'),
            ),
            const SizedBox(height: 18),
            ElevatedButton(
              onPressed: () {
                if (_whoCtrl.text.isEmpty || _amountCtrl.text.isEmpty) {
                  _snack('Numéro et montant requis.');
                  return;
                }
                _snack('Demande de ${formatFCFA(int.parse(_amountCtrl.text))} envoyée à ${_whoCtrl.text}');
                _whoCtrl.clear();
                _amountCtrl.clear();
              },
              child: const Text('Envoyer la demande'),
            ),
            const SizedBox(height: 10),
            const Text(
              'Le payeur reçoit un SMS avec un lien court ; sans data, il valide par USSD *880*9#.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
            ),
          ],
        ),
      ),
    );
  }
}
