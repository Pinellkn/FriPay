import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/offline_qr_service.dart';
import '../../theme/app_colors.dart';
import '../../utils/formatters.dart';
import '../../widgets/section_header.dart';
import '../scan/qr_scan_screen.dart';

/// §6.d — Réception d'un QR "argent" : scan (caméra) ou upload depuis la
/// galerie (déjà géré par [QrScanScreen], qui propose les deux), puis
/// POST /qr/receive. Une fois reçu ("coffre"), le receveur choisit entre
/// encaisser (§6.b, POST /qr/redeem) ou transmettre à un tiers (§6.c,
/// POST /qr/transfer).
class ReceiveQrScreen extends StatefulWidget {
  /// Contenu QR déjà scanné (via la zone de scan centrale) : si fourni,
  /// l'écran ne redemande pas le scan et traite immédiatement la réception.
  final String? preScannedCode;

  const ReceiveQrScreen({super.key, this.preScannedCode});

  @override
  State<ReceiveQrScreen> createState() => _ReceiveQrScreenState();
}

class _ReceiveQrScreenState extends State<ReceiveQrScreen> {
  bool _receiving = false;
  bool _acting = false;
  String? _error;

  // Résultat de /qr/receive : ce que l'on vient de stocker dans le coffre.
  String? _uuid;
  int? _amount;
  String? _status;

  final _transferPhoneCtrl = TextEditingController();
  bool _showTransferField = false;

  @override
  void initState() {
    super.initState();
    // Arrivée depuis la zone de scan centrale avec un QR déjà lu : on
    // enchaîne directement la réception sans redemander un scan.
    final pre = widget.preScannedCode;
    if (pre != null && pre.isNotEmpty) {
      _receiveCode(pre);
    }
  }

  @override
  void dispose() {
    _transferPhoneCtrl.dispose();
    super.dispose();
  }

  void _snack(String msg) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));

  void _resetResult() {
    setState(() {
      _uuid = null;
      _amount = null;
      _status = null;
      _showTransferField = false;
      _transferPhoneCtrl.clear();
    });
  }

  Future<void> _scanAndReceive() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const QrScanScreen()),
    );
    if (code == null || code.isEmpty || !mounted) return;
    await _receiveCode(code);
  }

  /// Traite la réception d'un contenu QR déjà obtenu (scan direct ou
  /// pré-scanné depuis la zone centrale). Met à jour l'état d'affichage.
  Future<void> _receiveCode(String code) async {
    setState(() {
      _receiving = true;
      _error = null;
    });
    try {
      final res = await OfflineQrService.instance.receive(code);
      if (!mounted) return;
      setState(() {
        _receiving = false;
        _uuid = res['uuid'] as String?;
        _amount = (res['amount'] as num?)?.toInt();
        _status = res['status'] as String?;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _receiving = false;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _receiving = false;
        _error = 'Une erreur est survenue. Réessayez.';
      });
    }
  }

  Future<void> _redeem() async {
    final uuid = _uuid;
    if (uuid == null) return;
    setState(() => _acting = true);
    try {
      final res = await OfflineQrService.instance.redeem(uuid);
      if (!mounted) return;
      final amount = (res['amount'] as num?)?.toInt() ?? _amount ?? 0;
      _snack('${formatFCFA(amount)} encaissés — le règlement sera traité vers votre compte.');
      setState(() {
        _acting = false;
      });
      _resetResult();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _acting = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _acting = false);
      _snack("Impossible d'encaisser ce QR pour le moment.");
    }
  }

  Future<void> _transfer() async {
    final uuid = _uuid;
    final phoneRaw = _transferPhoneCtrl.text.trim();
    if (uuid == null || phoneRaw.isEmpty) {
      _snack('Numéro du nouveau destinataire requis.');
      return;
    }
    setState(() => _acting = true);
    try {
      await OfflineQrService.instance.transfer(
        uuid: uuid,
        recipientPhone: AuthService.normalizePhone(phoneRaw),
      );
      if (!mounted) return;
      _snack('QR transmis avec succès.');
      setState(() => _acting = false);
      _resetResult();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _acting = false);
      _snack(e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _acting = false);
      _snack('Impossible de transmettre ce QR pour le moment.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recevoir un QR')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SectionHeader(
                title: 'QR argent reçu',
                subtitle: "Scannez ou téléversez le QR envoyé par quelqu'un pour le stocker dans votre coffre.",
              ),
              const SizedBox(height: 20),
              _uuid == null ? _scanCard() : _resultCard(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _scanCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Icon(Icons.qr_code_scanner_rounded, size: 48, color: AppColors.primary.withValues(alpha: 0.6)),
            const SizedBox(height: 14),
            const Text(
              "Le montant reste dans votre coffre : vous pourrez l'encaisser quand vous voulez, ou le transmettre à quelqu'un d'autre.",
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.mutedForeground, fontSize: 12.5, height: 1.4),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.destructive, fontSize: 12.5, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 18),
            ElevatedButton.icon(
              onPressed: _receiving ? null : _scanAndReceive,
              icon: _receiving
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.qr_code_2_rounded, size: 18),
              label: Text(_receiving ? 'Réception…' : 'Scanner ou téléverser un QR'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _resultCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Container(
              width: 56,
              height: 56,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.1), shape: BoxShape.circle),
              child: const Icon(Icons.inventory_2_rounded, color: AppColors.primary, size: 26),
            ),
            const SizedBox(height: 12),
            Text(formatFCFA(_amount ?? 0), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
            const SizedBox(height: 4),
            Text(
              _status == 'received' ? 'Reçu et stocké dans votre coffre' : 'Statut : ${_status ?? '—'}',
              style: const TextStyle(color: AppColors.mutedForeground, fontSize: 12.5),
            ),
            const SizedBox(height: 20),
            if (!_showTransferField) ...[
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: _acting ? null : _redeem,
                  icon: _acting
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.check_circle_outline_rounded, size: 18),
                  label: const Text('Encaisser maintenant'),
                ),
              ),
              const SizedBox(height: 10),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: _acting ? null : () => setState(() => _showTransferField = true),
                  icon: const Icon(Icons.forward_rounded, size: 18),
                  label: const Text('Transmettre à quelqu\'un d\'autre'),
                ),
              ),
            ] else ...[
              const Text('Numéro du nouveau destinataire', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 8),
              TextField(
                controller: _transferPhoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(hintText: '+229 95 00 00 00'),
              ),
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: _acting ? null : _transfer,
                  icon: _acting
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.send_rounded, size: 18),
                  label: const Text('Transmettre'),
                ),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: _acting ? null : () => setState(() => _showTransferField = false),
                child: const Text('Annuler'),
              ),
            ],
            const SizedBox(height: 6),
            TextButton(
              onPressed: _acting ? null : _resetResult,
              child: const Text('Scanner un autre QR', style: TextStyle(color: AppColors.mutedForeground)),
            ),
          ],
        ),
      ),
    );
  }
}
