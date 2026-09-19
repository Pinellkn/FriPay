import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/rendering.dart';
import 'package:gal/gal.dart';
import 'package:qr_flutter/qr_flutter.dart';

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
  /// [size] : taille cible du PNG en pixels logiques (défaut 1024 —
  /// haute résolution pour un scan fiable même après transfert WhatsApp).
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

  Future<Uint8List> _renderPng(String data, int size) async {
    final painter = QrPainter(
      data: data,
      version: QrVersions.auto,
      // ECC Q (25 %) : même niveau que l'affichage à l'écran — le PNG
      // téléversé reste lisible même après compression WhatsApp.
      errorCorrectionLevel: QrErrorCorrectLevel.Q,
      eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: Color(0xFF16332A)),
      dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: Color(0xFF16332A)),
    );

    // pixelRatio 1 : [size] est déjà la résolution finale en pixels.
    final pic = painter.toPicture(size.toDouble());
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
