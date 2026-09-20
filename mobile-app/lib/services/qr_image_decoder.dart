import 'dart:io';
import 'dart:isolate';
import 'dart:ui' as ui;

import 'package:image/image.dart' as img;
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:path_provider/path_provider.dart';

/// Décodage robuste d'un QR code depuis une image téléversée (galerie,
/// capture WhatsApp…). Le décodage natif (ML Kit) échoue parfois sur les
/// photos compressées : on retente alors sur des RECOMPOSITIONS de
/// l'image (réduction, contraste renforcé, upscale, inversion).
///
/// Performance — pipeline en 3 paliers, du plus rapide au plus cher :
/// 1. Analyse native DIRECTE de l'original (cas le plus courant, ~0 ms).
/// 2. Réduction native Skia (`instantiateImageCodec`) : décode + resize en
///    code natif (quelques dizaines de ms même sur 12 MP), puis analyse de
///    cette version propre. La majorité des échecs du palier 1 (photo trop
///    lourde / trop compressée) se résolvent ici, SANS payer le decodeur
///    Dart pur.
/// 3. Recompositions Dart (`image` package) DERNIER RECOURS, exécutées dans
///    un isolate de fond et travaillant sur la version réduite du palier 2
///    (et non sur la photo 12 MP d'origine) : grayscale + contraste,
///    upscale ×2, inversion. Encodage JPEG (bien plus rapide que PNG).
///
/// Robustesse — [decode] ne lève JAMAIS d'exception : toute erreur
/// (image illisible, format non supporté comme HEIC, isolate qui échoue…)
/// est avalée et renvoie null. C'est ce qui provoquait le message
/// « Impossible de lire cette image » en remontant d'un crash Dart.
class QrImageDecoder {
  QrImageDecoder._();
  static final QrImageDecoder instance = QrImageDecoder._();

  /// Contrôleur jetable SANS caméra, dédié à l'analyse d'images.
  /// UN SEUL contrôleur pour toutes les tentatives, disposé à la fin —
  /// créer un contrôleur par tentative accumulait les instances natives
  /// jusqu'au crash de l'application.
  MobileScannerController? _analysisController;

  /// Retourne le contenu du QR, ou null si aucun décodage n'aboutit.
  Future<String?> decode(String path) async {
    Directory? workDir;
    try {
      // 1) Tentative directe sur l'original.
      final direct = await _analyze(path);
      if (direct != null) return direct;

      // Dossier de travail temporaire dans le cache de l'app.
      final tmp = await getTemporaryDirectory();
      workDir = Directory(
        '${tmp.path}${Platform.pathSeparator}fripay_qr_${DateTime.now().microsecondsSinceEpoch}',
      );
      await workDir.create(recursive: true);

      // 2) Réduction NATIVE (Skia) puis analyse de cette version propre.
      final downscaledPath = await _skiaDownscale(
        path,
        '${workDir.path}${Platform.pathSeparator}down.png',
        maxSide: 1400,
      );
      if (downscaledPath != null) {
        final value = await _analyze(downscaledPath);
        if (value != null) return value;

        // 3) Recompositions Dart en isolate, DERNIER recours, sur la
        //    version réduite (rapide) plutôt que sur l'original (12 MP).
        final variantPaths = await Isolate.run(
          () => _prepareVariants(downscaledPath, workDir!.path),
        );
        for (final variantPath in variantPaths) {
          final v = await _analyze(variantPath);
          if (v != null) return v;
        }
      }
      return null;
    } catch (_) {
      // Image illisible, format non supporté, erreur d'I/O… : on renvoie
      // null, l'écran appelant affichera un message clair. Ne JAMAIS
      // laisser une exception remonter (c'était la cause du message
      // générique « Impossible de lire cette image »).
      return null;
    } finally {
      if (workDir != null) {
        try {
          await workDir.delete(recursive: true);
        } catch (_) {
          // Nettoyage best-effort des fichiers temporaires.
        }
      }
      await _disposeAnalysisController();
    }
  }

  Future<String?> _analyze(String path) async {
    try {
      _analysisController ??= MobileScannerController(autoStart: false);
      final capture = await _analysisController!.analyzeImage(path);
      return _extract(capture);
    } catch (_) {
      // Image illisible ou décodeur indisponible : on tentera un palier.
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

  /// Réduction NATIVE de l'image via le moteur Flutter (Skia) : décodage
  /// et resize en code natif, ordres de grandeur plus rapides que le
  /// decodeur Dart pur. Retourne le chemin du PNG réduit, ou null en cas
  /// d'échec (format non décodable par Skia, ex. HEIC).
  static Future<String?> _skiaDownscale(
    String path,
    String outPath, {
    required int maxSide,
  }) async {
    ui.ImmutableBuffer? buffer;
    ui.ImageDescriptor? descriptor;
    ui.Codec? codec;
    ui.FrameInfo? frame;
    try {
      final bytes = await File(path).readAsBytes();
      buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
      descriptor = await ui.ImageDescriptor.encoded(buffer);
      final largest = descriptor.width > descriptor.height ? descriptor.width : descriptor.height;
      var targetW = descriptor.width;
      var targetH = descriptor.height;
      if (largest > maxSide) {
        final ratio = maxSide / largest;
        targetW = (targetW * ratio).round();
        targetH = (targetH * ratio).round();
      }
      codec = await descriptor.instantiateCodec(targetWidth: targetW, targetHeight: targetH);
      frame = await codec.getNextFrame();
      final data = await frame.image.toByteData(format: ui.ImageByteFormat.png);
      if (data == null) return null;
      await File(outPath).writeAsBytes(data.buffer.asUint8List(), flush: true);
      return outPath;
    } catch (_) {
      return null;
    } finally {
      try {
        frame?.image.dispose();
        codec?.dispose();
        descriptor?.dispose();
        buffer?.dispose(); // ImmutableBuffer.dispose() est synchrone (void)
      } catch (_) {}
    }
  }

  /// Recompositions last-resort (fonction STATIQUE exécutée dans
  /// l'isolate de fond). Reçoit la version RÉDUITE de l'image (<= 1400 px)
  /// : le décodage Dart pur et les recompositions restent alors rapides.
  /// Retourne les chemins des variantes ; liste vide si tout échoue.
  static List<String> _prepareVariants(String downscaledPath, String dirPath) {
    try {
      final bytes = File(downscaledPath).readAsBytesSync();
      final src = img.decodePng(bytes);
      if (src == null) return const [];

      final paths = <String>[];
      var i = 0;
      for (final variant in _variants(src)) {
        final tmp = File('$dirPath/v${i++}.jpg');
        tmp.writeAsBytesSync(img.encodeJpg(variant, quality: 92));
        paths.add(tmp.path);
      }
      return paths;
    } catch (_) {
      // Toute erreur ici ne doit PAS casser l'upload : on retourne une
      // liste vide (aucune variante à tester) plutôt que de propager.
      return const [];
    }
  }

  /// Recompositions à tenter, de la plus probable à la moins probable :
  /// grayscale + contraste (images délavées / très compressées),
  /// upscale ×2 (QR trop petits dans une grande capture), inversion
  /// (QR clair sur fond sombre, mal géré par certains décodeurs).
  static Iterable<img.Image> _variants(img.Image src) sync* {
    final gray = img.grayscale(src);
    img.contrast(gray, contrast: 160);
    yield gray;

    final upW = src.width * 2;
    final upH = src.height * 2;
    if (upW <= 3200 && upH <= 3200) {
      yield img.copyResize(src, width: upW, height: upH, interpolation: img.Interpolation.cubic);
    }

    final inverted = img.invert(img.copyResize(src));
    yield inverted;
  }
}
