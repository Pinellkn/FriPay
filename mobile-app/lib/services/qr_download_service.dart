import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/rendering.dart';
import 'package:gal/gal.dart';
import 'package:path_provider/path_provider.dart' show getTemporaryDirectory;
import 'package:qr_flutter/qr_flutter.dart';
import 'package:share_plus/share_plus.dart';

/// Téléchargement du QR "argent" généré : rendu en PNG haute résolution
/// puis enregistrement dans la galerie du téléphone (MediaStore).
///
/// Utilise les bytes du QR plutôt qu'une capture d'écran : l'image
/// obtenue est nette à toute taille, sans arrière-plan ni cadre autour,
/// donc fiable à la relecture par n'importe quel scanner.
class QrDownloadService {
  QrDownloadService._();
  static final QrDownloadService instance = QrDownloadService._();

  /// Rend le contenu QR en PNG (whitespace included) et l'enregistre dans
  /// la galerie par défaut ("Pictures").
  ///
  /// [data] : contenu JSON signé du QR (qr.qrCode).
  /// [size] : taille cible du PNG en pixels (défaut 1024 — haute
  /// résolution pour un scan fiable même après transfert WhatsApp). Le QR
  /// est centré avec une quiet zone de 4 modules (marge blanche obligatoire).
  Future<void> downloadToGallery(String data, {int size = 1024}) async {
    final bytes = await _renderPng(data, size);
    try {
      // Sur Android 10+, MediaStore n'exige aucune permission pour les
      // images créées par l'app ; sur Android 9 et antérieurs, gal demande
      // lui-même WRITE_EXTERNAL_STORAGE à la volée.
      await Gal.putImageBytes(bytes, name: 'fripay-qr-${DateTime.now().millisecondsSinceEpoch}', album: 'FriPay');
    } on GalException catch (e) {
      throw QrDownloadException._(e.type.message);
    }
  }

  /// Vérifie silencieusement l'accès à la galerie (utilisable pour
  /// désactiver le bouton de téléchargement si refus définitif).
  Future<bool> hasAccess() => Gal.hasAccess();

  /// Partage le QR via la feuille de partage native du système
  /// (WhatsApp, SMS, e-mail, etc.). Stratégie en deux essais :
  /// 1. image PNG rendue à la volée + texte d'accompagnement ;
  /// 2. repli : texte seul (le jeton QR ne sert pas au payeur humain,
  ///    on envoie donc un message explicite plutôt que rien du tout).
  ///
  /// Retourne true si le partage a bien été transmis au système.
  /// Lève [QrDownloadException] si même le rendu a échoué.
  Future<bool> shareQrImage(String data, {String text = '', int size = 1024}) async {
    var fileShared = false;
    try {
      final bytes = await _renderPng(data, size);
      final tmpDir = await getTemporaryDirectory();
      final file = File('${tmpDir.path}/fripay-qr-${DateTime.now().millisecondsSinceEpoch}.png');
      await file.writeAsBytes(bytes);

      final result = await SharePlus.instance.share(
        ShareParams(
          files: [XFile(file.path)],
          text: text.isEmpty ? null : text,
          title: 'Mon QR FriPay',
        ),
      );
      fileShared = result.status == ShareResultStatus.success;
    } on QrDownloadException {
      rethrow;
    } catch (_) {
      // Rendu ou partage de fichier impossible (plugin natif absent du
      // build, permission refusée...) : on retente en texte seul.
    }
    if (fileShared) return true;

    if (text.isEmpty) return false;
    try {
      final result = await SharePlus.instance.share(ShareParams(text: text, title: 'Mon QR FriPay'));
      return result.status == ShareResultStatus.success;
    } catch (_) {
      return false;
    }
  }

  Future<Uint8List> _renderPng(String data, int size) async {
    // QrCode construit en amont pour connaître le nombre exact de modules
    // (nécessaire pour dimensionner la QUIET ZONE en modules, comme l'exige
    // la spec ISO/IEC 18004).
    final qr = QrCode.fromData(data: data, errorCorrectLevel: QrErrorCorrectLevel.Q);
    final painter = QrPainter.withQr(
      qr: qr,
      // gapless : supprime l'écart anti-aliasing d'1px entre modules —
      // indispensable pour un décodage natif (ML Kit) rapide et fiable.
      gapless: true,
      eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: Color(0xFF16332A)),
      dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: Color(0xFF16332A)),
    );

    // QUIET ZONE DE 4 MODULES (spec QR) : qr_flutter peint le QR sur TOUTE
    // la surface fournie, sans aucune marge. Un QR collé aux bords de
    // l'image ralentit ou fait échouer le décodage — c'est exactement la
    // différence avec une capture d'écran, qui inclut naturellement le
    // fond blanc de l'app autour du QR.
    const quietModules = 4;
    final totalModules = qr.moduleCount + quietModules * 2;
    final modulePx = size / totalModules;
    final qrSize = modulePx * qr.moduleCount;
    final quietPx = modulePx * quietModules;

    // FOND BLANC OPAQUE OBLIGATOIRE : peint sans ça, le PNG du QR avait un
    // arrière-plan TRANSPARENT (qr_flutter ne peint que les modules). Un QR
    // sur fond transparent est illisible pour ML Kit et la plupart des
    // décodeurs — c'est pourquoi l'upload du QR téléchargé échouait alors
    // qu'une capture d'écran (QR posé sur le fond blanc de l'app) passait.
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.drawColor(const Color(0xFFFFFFFF), BlendMode.srcOver);
    canvas.translate(quietPx, quietPx);
    painter.paint(canvas, Size(qrSize, qrSize));
    final pic = recorder.endRecording();

    // pixelRatio 1 : [size] est déjà la résolution finale en pixels.
    final image = await pic.toImage(size, size);
    final byteData = await image.toByteData(format: ui.ImageByteFormat.png);
    pic.dispose();
    image.dispose();

    final bytes = byteData?.buffer.asUint8List();
    if (bytes == null || bytes.isEmpty) {
      throw const QrDownloadException._("Impossible de générer l'image du QR.");
    }
    return bytes;
  }
}

/// Erreur utilisateur-friendly pour le téléchargement du QR.
class QrDownloadException implements Exception {
  final String message;
  const QrDownloadException._(this.message);

  @override
  String toString() => message;
}
