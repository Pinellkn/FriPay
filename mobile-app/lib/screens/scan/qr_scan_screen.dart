import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../services/qr_image_decoder.dart';
import '../../theme/app_colors.dart';

/// Scanner de QR générique (caméra + upload galerie), partagé par les
/// écrans qui ont besoin de lire un QR FriPay — §6.d "Réception" (scan ou
/// upload via bouton « Uploader ») via [ReceiveQrScreen].
///
/// Retourne (`Navigator.pop`) le contenu brut décodé du QR (`String`), ou
/// `null` si l'utilisateur annule.
class QrScanScreen extends StatefulWidget {
  const QrScanScreen({super.key});

  @override
  State<QrScanScreen> createState() => _QrScanScreenState();
}

class _QrScanScreenState extends State<QrScanScreen> {
  final MobileScannerController _controller = MobileScannerController();
  bool _handled = false;
  bool _uploading = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _returnCode(String code) {
    if (_handled || !mounted) return;
    _handled = true;
    Navigator.of(context).pop(code);
  }

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;
    for (final barcode in capture.barcodes) {
      final value = barcode.rawValue;
      if (value != null && value.isNotEmpty) {
        _returnCode(value);
        return;
      }
    }
  }

  Future<void> _uploadFromGallery() async {
    setState(() {
      _uploading = true;
      _error = null;
    });
    try {
      final picked = await ImagePicker().pickImage(source: ImageSource.gallery);
      if (picked == null) {
        if (mounted) setState(() => _uploading = false);
        return;
      }
      // Décodeur robuste : analyse native, puis recompositions (échelle,
      // contraste, miroir) — les QR téléversés (photos WhatsApp) sont
      // souvent trop compressés pour le décodage natif direct.
      final value = await QrImageDecoder.instance.decode(picked.path);
      if (!mounted) return;
      if (value != null && value.isNotEmpty) {
        _returnCode(value);
      } else {
        setState(() {
          _uploading = false;
          _error = 'Aucun QR détecté dans cette image. Réessayez.';
        });
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _uploading = false;
        _error = "Impossible de lire cette image. Vérifiez qu'il s'agit bien d'un QR FriPay.";
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Scanner un QR'),
        actions: [
          IconButton(
            tooltip: 'Activer/désactiver le flash',
            icon: const Icon(Icons.flash_on_rounded),
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: SafeArea(
        child: Stack(
          children: [
            Positioned.fill(
              child: MobileScanner(controller: _controller, onDetect: _onDetect),
            ),
            // Cadre de visée — purement visuel, s'adapte à la taille de
            // l'écran pour rester lisible sur petits et grands appareils.
            Center(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final side = (constraints.maxWidth < constraints.maxHeight ? constraints.maxWidth : constraints.maxHeight) * 0.7;
                  return Container(
                    width: side,
                    height: side,
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 2.5),
                      borderRadius: BorderRadius.circular(20),
                    ),
                  );
                },
              ),
            ),
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 12, 18, 18),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_error != null)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Text(
                            _error!,
                            textAlign: TextAlign.center,
                            style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600),
                          ),
                        ),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton.icon(
                          onPressed: _uploading ? null : _uploadFromGallery,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.card,
                            foregroundColor: AppColors.foreground,
                          ),
                          icon: _uploading
                              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                              : const Icon(Icons.upload_rounded, size: 18),
                          label: Text(_uploading ? 'Analyse…' : 'Uploader depuis la galerie'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
