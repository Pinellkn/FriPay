import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/contact_service.dart';
import '../../services/merchant_qr_service.dart';
import '../../services/payment_link_service.dart';
import '../../services/qr_download_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/add_contact_sheet.dart';
import '../../widgets/section_header.dart';
import 'receive_qr_screen.dart';

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
  final _requestReasonCtrl = TextEditingController();

  @override
  void dispose() {
    _tab.dispose();
    _whoCtrl.dispose();
    _amountCtrl.dispose();
    _requestReasonCtrl.dispose();
    super.dispose();
  }

  MerchantQr? _merchantQr;
  String _displayName = '…';
  String _phone = '';
  bool _regenerating = false;
  String? _qrError;
  bool _firstLoad = true;
  List<FripayContact> _contacts = [];

  // Demande de paiement : un VRAI lien FriPay (web1, /pay/{token}) est
  // créé et partagé — plus un simple snackbar factice.
  bool _creatingRequest = false;
  FripayLink? _createdRequest;

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  /// Partage le QR marchand via la feuille native du système : l'image PNG
  /// du QR + un message explicite (destinataire + N° FriPay). Avant ce
  /// correctif, le bouton "Partager" ne faisait qu'afficher un snackbar
  /// "Lien de paiement partagé" sans rien partager du tout — il n'existe
  /// d'ailleurs aucun lien de paiement côté API, le QR est l'objet partageable.
  Future<void> _shareQr() async {
    final qr = _merchantQr;
    if (qr == null) return;
    try {
      final buffer = StringBuffer('Reçois-moi sur FriPay ! Scanne ce QR pour me payer')
        ..writeln();
      if (_phone.isNotEmpty) {
        buffer.writeln('Tél : $_phone');
      }
      final shared = await QrDownloadService.instance.shareQrImage(qr.qrCode, text: buffer.toString().trim());
      if (!mounted) return;
      _snack(shared ? 'QR partagé' : 'Partage annulé ou indisponible sur cet appareil.');
    } on QrDownloadException catch (e) {
      if (!mounted) return;
      _snack(e.message);
    } catch (_) {
      if (!mounted) return;
      _snack("Impossible de partager le QR pour le moment.");
    }
  }

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
      appBar: AppBar(
        title: const Text('Recevoir'),
        actions: [
          IconButton(
            tooltip: 'Recevoir un QR',
            icon: const Icon(Icons.qr_code_scanner_rounded),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ReceiveQrScreen()),
            ),
          ),
        ],
      ),
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
              AnimatedBuilder(
                animation: _tab,
                builder: (context, _) => _tab.index == 0 ? _qrTab() : _requestTab(),
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
                  LayoutBuilder(builder: (context, constraints) {
                    final qrSize = constraints.maxWidth * 0.75;
                    return Container(
                      width: qrSize + 32,
                      height: qrSize + 32,
                      padding: const EdgeInsets.all(16),
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
                      child: _merchantQr == null
                          ? (_regenerating
                              ? const CircularProgressIndicator()
                              : const Icon(Icons.qr_code_2_rounded, size: 48, color: AppColors.mutedForeground))
                          : QrImageView(
                              data: _merchantQr!.qrCode,
                              size: qrSize,
                              eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.primaryDeep),
                              dataModuleStyle: const QrDataModuleStyle(
                                  dataModuleShape: QrDataModuleShape.square, color: AppColors.primaryDeep),
                            ),
                    );
                  }),
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
                        onPressed: _merchantQr == null ? null : _shareQr,
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

  /// Crée la demande de paiement : un lien FriPay réel (montant verrouillé
  /// côté serveur) dont l'URL pointe vers la page web publique web1
  /// (/pay/{token}) — cliquable, partageable, payable même sans l'appli.
  Future<void> _createPaymentRequest() async {
    final amount = int.tryParse(_amountCtrl.text) ?? 0;
    if (amount < 100) {
      _snack('Montant minimum : 100 FCFA');
      return;
    }
    setState(() => _creatingRequest = true);
    try {
      final link = await PaymentLinkService.instance.create(
        amount: amount,
        description: _requestReasonCtrl.text.trim().isEmpty ? null : _requestReasonCtrl.text.trim(),
      );
      if (!mounted) return;
      setState(() {
        _creatingRequest = false;
        _createdRequest = link;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _creatingRequest = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _creatingRequest = false);
      _snack('Création de la demande impossible. Réessayez.');
    }
  }

  /// Ouvre la page web1 du lien (aperçu de ce que verra le payeur).
  Future<void> _openRequestLink(FripayLink link) async {
    final url = Uri.tryParse(link.shareUrl ?? 'https://fripay.bj/pay/${link.token}');
    if (url == null) return;
    try {
      final ok = await launchUrl(url, mode: LaunchMode.externalApplication);
      if (!ok && mounted) _snack("Impossible d'ouvrir le navigateur.");
    } catch (_) {
      if (mounted) _snack("Impossible d'ouvrir le navigateur.");
    }
  }

  /// Partage le lien web1 (WhatsApp, SMS…) — avec le numéro du payeur en
  /// tête de message s'il a été renseigné.
  Future<void> _shareRequestLink(FripayLink link) async {
    final url = link.shareUrl ?? 'https://fripay.bj/pay/${link.token}';
    final who = _whoCtrl.text.trim();
    final text = who.isEmpty
        ? 'Paiement de ${formatFCFA(link.amount)} via FriPay : $url'
        : 'Salut ! Envoie ${formatFCFA(link.amount)} via FriPay : $url';
    try {
      await SharePlus.instance.share(ShareParams(text: text, title: 'Demande de paiement FriPay'));
    } catch (_) {
      await Clipboard.setData(ClipboardData(text: url));
      if (mounted) _snack('Lien copié dans le presse-papiers');
    }
  }

  Widget _requestTab() {
    final created = _createdRequest;
    if (created != null) return _requestResult(created);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Numéro du payeur (facultatif)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(controller: _whoCtrl, decoration: const InputDecoration(hintText: '01 97 00 00 00')),
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
            const SizedBox(height: 16),
            const Text('Motif (facultatif)', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 8),
            TextField(
              controller: _requestReasonCtrl,
              maxLength: 255,
              decoration: const InputDecoration(hintText: 'Ex : part du loyer, facture…', counterText: ''),
            ),
            const SizedBox(height: 18),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: _creatingRequest ? null : _createPaymentRequest,
                icon: _creatingRequest                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))                    : const Icon(Icons.link_rounded, size: 18),
                label: Text(_creatingRequest ? 'Création…' : 'Générer le lien de paiement'),
              ),
            ),
            const SizedBox(height: 10),
            const Text(
              'Un lien sécurisé sera créé : cliquable, il ouvre une page web '
              'où le payeur règle via FriPay ou son compte mobile.',
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 11.5),
            ),
          ],
        ),
      ),
    );
  }

  /// Résultat : le lien web1 généré, affiché CLIQUABLE + partage.
  Widget _requestResult(FripayLink link) {
    final url = link.shareUrl ?? 'https://fripay.bj/pay/${link.token}';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            const Icon(Icons.check_circle_rounded, size: 48, color: AppColors.primary),
            const SizedBox(height: 10),
            const Text('Demande créée !', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            const SizedBox(height: 4),
            Text(
              'Partagez ce lien : il ouvre une page web où le payeur règle ${formatFCFA(link.amount)}.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5),
            ),
            const SizedBox(height: 16),
            if (link.description != null && link.description!.isNotEmpty) ...[
              Text(link.description!, style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
            ],
            Text(formatFCFA(link.amount),
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.primary)),
            const SizedBox(height: 14),
            // LIEN CLIQUABLE → web1
            InkWell(
              onTap: () => _openRequestLink(link),
              borderRadius: BorderRadius.circular(10),
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                decoration: BoxDecoration(color: AppColors.muted, borderRadius: BorderRadius.circular(10)),
                child: Row(
                  children: [
                    const Icon(Icons.link_rounded, size: 16, color: AppColors.primary),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        url,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 12.5,
                          color: AppColors.primary,
                          fontWeight: FontWeight.w600,
                          decoration: TextDecoration.underline,
                          decorationColor: AppColors.primary,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    const Icon(Icons.open_in_new_rounded, size: 14, color: AppColors.primary),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () => _shareRequestLink(link),
                icon: const Icon(Icons.share_rounded, size: 18),
                label: const Text('Partager le lien'),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _openRequestLink(link),
                    icon: const Icon(Icons.open_in_new_rounded, size: 16),
                    label: const Text('Ouvrir'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      await Clipboard.setData(ClipboardData(text: url));
                      if (mounted) _snack('Lien copié');
                    },
                    icon: const Icon(Icons.copy_rounded, size: 16),
                    label: const Text('Copier'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => setState(() {
                _createdRequest = null;
                _amountCtrl.clear();
                _requestReasonCtrl.clear();
              }),
              child: const Text('Nouvelle demande'),
            ),
          ],
        ),
      ),
    );
  }
}
