import 'dart:io';

import 'package:image/image.dart' as img;
import 'package:mobile_scanner/mobile_scanner.dart';

/// Décodage robuste d'un QR code depuis une image téléversée (galerie,
/// capture WhatsApp…). Le décodage natif (ML Kit) échoue souvent sur les
/// photos compressées : on retente alors sur des RECOMPOSITIONS de
/// l'image (mise à l'échelle ×2/×3, contraste renforcé, miroir), écrites
/// en fichiers temporaires puisque `analyzeImage` ne prend qu'un chemin.
class QrImageDecoder {
  QrImageDecoder._();
  static final QrImageDecoder instance = QrImageDecoder._();

  /// Retourne le contenu du QR, ou null si aucun décodage n'aboutit.
  Future<String?> decode(String path) async {
    // 1) Tentative directe sur l'original (cas le plus courant).
    final direct = await _analyze(path);
    if (direct != null) return direct;

    // 2) Recompositions locales : upscale / contraste / miroir.
    final bytes = await File(path).readAsBytes();
    final src = img.decodeImage(bytes);
    if (src == null) return null;

    final dir = await Directory.systemTemp.createTemp('fripay_qr');
    try {
      var i = 0;
      for (final variant in _variants(src)) {
        final tmp = File('${dir.path}/v${i++}.png');
        await tmp.writeAsBytes(img.encodePng(variant));
        final value = await _analyze(tmp.path);
        if (value != null) return value;
      }
      return null;
    } finally {
      await dir.delete(recursive: true);
    }
  }

  Future<String?> _analyze(String path) async {
    try {
      // NB : méthode d'instance du controller — on passe par une instance
      // jetable (sans démarrage caméra) pour analyser un fichier image.
      final capture = await MobileScannerController().analyzeImage(path);
      return _extract(capture);
    } catch (_) {
      return null;
    }
  }

  String? _extract(BarcodeCapture? capture) {
    if (capture == null) return null;
    for (final barcode in capture.barcodes) {
      final v = barcode.rawValue;
      if (v != null && v.isNotEmpty) return v;
    }
    return null;
  }

  /// Recompositions à tenter, de la plus probable à la moins probable.
  /// Le QR téléversé est souvent trop petit (upscale ×2-3) ou trop
  /// délavé (contraste renforcé) pour le décodeur natif.
  static Iterable<img.Image> _variants(img.Image src) sync* {
    for (final scale in const [2.0, 3.0, 0.5]) {
      final w = (src.width * scale).round();
      final h = (src.height * scale).round();
      if (w < 16 || h < 16 || w > 4000 || h > 4000) continue;
      yield img.copyResize(src, width: w, height: h, interpolation: img.Interpolation.cubic);
    }
    final contrasted = img.copyResize(src); // copie indépendante
    img.contrast(contrasted, contrast: 160);
    yield contrasted;
    yield img.flip(img.copyResize(src), direction: img.FlipDirection.horizontal);
  }
}
