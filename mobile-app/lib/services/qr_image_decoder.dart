import 'dart:io';
import 'dart:isolate';

import 'package:image/image.dart' as img;
import 'package:mobile_scanner/mobile_scanner.dart';

/// Décodage robuste d'un QR code depuis une image téléversée (galerie,
/// capture WhatsApp…). Le décodage natif (ML Kit) échoue souvent sur les
/// photos compressées : on retente alors sur des RECOMPOSITIONS de
/// l'image (mise à l'échelle, contraste renforcé, miroir).
///
/// Deux pièges corrigés ici (crashs remontés sur l'upload de QR) :
/// 1. Un MobileScannerController unique (autoStart: false) est créé pour
///    TOUTES les tentatives puis disposé. La version précédente créait un
///    contrôleur caméra par tentative (jusqu'à 6 par upload) sans jamais
///    les disposer : les instances natives s'accumulaient et finissaient
///    par tuer l'application (crash natif, non rattrapable en Dart).
/// 2. La recomposition d'image tourne dans un isolate séparé
///    ([Isolate.run]) : décoder une photo 12 MP + resize cubique sur
///    l'isolate principal gelait l'UI plusieurs secondes (ANR -> kill).
class QrImageDecoder {
  QrImageDecoder._();
  static final QrImageDecoder instance = QrImageDecoder._();

  /// Contrôleur jetable SANS caméra, dédié à l'analyse d'images.
  MobileScannerController? _analysisController;

  /// Retourne le contenu du QR, ou null si aucun décodage n'aboutit.
  Future<String?> decode(String path) async {
    try {
      // 1) Tentative directe sur l'original (cas le plus courant) —
      //    ML Kit gère nativement les grands JPEG, rapide et fiable.
      final direct = await _analyze(path);
      if (direct != null) return direct;

      // 2) Recompositions en isolate de fond, écrites en fichiers
      //    temporaires (analyzeImage ne prend qu'un chemin).
      final (dirPath, variantPaths) = await Isolate.run(() => _prepareVariants(path));
      try {
        for (final variantPath in variantPaths) {
          final value = await _analyze(variantPath);
          if (value != null) return value;
        }
        return null;
      } finally {
        try {
          await Directory(dirPath).delete(recursive: true);
        } catch (_) {
          // Nettoyage best-effort des fichiers temporaires.
        }
      }
    } finally {
      await _disposeAnalysisController();
    }
  }

  Future<String?> _analyze(String path) async {
    try {
      _analysisController ??= MobileScannerController(autoStart: false);
      final capture = await _analysisController!.analyzeImage(path);
      return _extract(capture);
    } catch (_) {
      // Image illisible ou décodeur indisponible : on tentera une variante.
      return null;
    }
  }

  Future<void> _disposeAnalysisController() async {
    final controller = _analysisController;
    _analysisController = null;
    if (controller == null) return;
    try {
      await controller.dispose();
    } catch (_) {
      // Déjà disposé ou pas encore initialisé côté natif.
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

  /// Prépare les variantes à tester (fonction STATIQUE exécutée dans
  /// l'isolate de fond — I/O synchrones acceptables ici, l'UI reste
  /// fluide). Retourne (cheminDuDossier, cheminsDesVariantes).
  static (String, List<String>) _prepareVariants(String path) {
    final dir = Directory.systemTemp.createTempSync('fripay_qr');
    final bytes = File(path).readAsBytesSync();
    final src = img.decodeImage(bytes);
    if (src == null) return (dir.path, const []);

    final paths = <String>[];
    var i = 0;
    for (final variant in _variants(src)) {
      final tmp = File('${dir.path}/v${i++}.png');
      tmp.writeAsBytesSync(img.encodePng(variant));
      paths.add(tmp.path);
    }
    return (dir.path, paths);
  }

  /// Recompositions à tenter, de la plus probable à la moins probable.
  /// La source est d'abord ramenée à 1600 px max (une photo 12 MP est
  /// inutilement lourde pour un décodage et coûte cher à recomposer) ;
  /// puis upscale ×2 pour les QR trop petits, contraste renforcé pour
  /// les images délavées, miroir pour les QR inversés.
  static Iterable<img.Image> _variants(img.Image src) sync* {
    final capped = _capSize(src, 1600);
    yield capped;

    // Upscale ×2 (les captures d'écran de petits QR sont parfois trop
    // denses pour le décodeur natif).
    final upW = (capped.width * 2).round();
    final upH = (capped.height * 2).round();
    if (upW <= 3200 && upH <= 3200) {
      yield img.copyResize(capped, width: upW, height: upH, interpolation: img.Interpolation.cubic);
    }

    final contrasted = img.copyResize(capped);
    img.contrast(contrasted, contrast: 160);
    yield contrasted;

    yield img.flip(img.copyResize(capped), direction: img.FlipDirection.horizontal);
  }

  static img.Image _capSize(img.Image src, int maxSide) {
    final largest = src.width > src.height ? src.width : src.height;
    if (largest <= maxSide) return src;
    final ratio = maxSide / largest;
    return img.copyResize(
      src,
      width: (src.width * ratio).round(),
      height: (src.height * ratio).round(),
      interpolation: img.Interpolation.average,
    );
  }
}
