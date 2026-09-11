// Générateur d'icône d'application — PAS un vrai test.
// On se sert du moteur de rendu Flutter (via `flutter test`) pour dessiner
// exactement le même badge que le logo utilisé partout dans l'app
// (dégradé emerald + "F"), puis on l'exporte en PNG 1024x1024 pour
// flutter_launcher_icons. Lancer avec :
//   flutter test test/generate_icon.dart
import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fripay_app/theme/app_colors.dart';

// Taille logique du canevas (reste sous la surface de test par défaut
// 800x600, donc pas besoin de redimensionner la vue de test).
const double _logical = 400;
// pixelRatio choisi pour obtenir une sortie PNG de 1024x1024.
const double _pixelRatio = 1024 / _logical;

Future<void> _capture({
  required Widget child,
  required String path,
  required WidgetTester tester,
}) async {
  final key = GlobalKey();
  await tester.pumpWidget(
    Directionality(
      textDirection: TextDirection.ltr,
      child: RepaintBoundary(key: key, child: child),
    ),
  );
  await tester.pump();
  final boundary = key.currentContext!.findRenderObject() as RenderRepaintBoundary;
  // IMPORTANT : toImage()/l'écriture fichier utilisent de vrais Futures
  // (thread de rendu, dart:io) qui ne progressent jamais dans la zone
  // "fake async" par défaut de testWidgets -> on sort via runAsync(),
  // sinon le test reste bloqué indéfiniment.
  await tester.runAsync(() async {
    final ui.Image image = await boundary.toImage(pixelRatio: _pixelRatio);
    final ByteData? bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    final file = File(path);
    await file.create(recursive: true);
    await file.writeAsBytes(bytes!.buffer.asUint8List());
  });
}

void main() {
  testWidgets('generate FriPay app icons', (tester) async {
    // 1) Icône pleine (fond dégradé + "F") — iOS, web, windows et Android legacy.
    await _capture(
      tester: tester,
      path: 'assets/icon/icon.png',
      child: Container(
        width: _logical,
        height: _logical,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          gradient: AppColors.gradientEmerald,
          borderRadius: BorderRadius.circular(_logical * 0.24),
        ),
        child: Text(
          'F',
          style: TextStyle(
            fontSize: _logical * 0.56,
            fontWeight: FontWeight.w900,
            color: Colors.white,
            height: 1.0,
          ),
        ),
      ),
    );

    // 2) Premier plan adaptatif Android : "F" seul, centré dans la zone de
    // sécurité (~66%), fond transparent (couleur gérée par
    // adaptive_icon_background dans flutter_launcher_icons).
    await _capture(
      tester: tester,
      path: 'assets/icon/icon_foreground.png',
      child: Container(
        width: _logical,
        height: _logical,
        color: Colors.transparent,
        alignment: Alignment.center,
        child: Text(
          'F',
          style: TextStyle(
            fontSize: _logical * 0.34,
            fontWeight: FontWeight.w900,
            color: Colors.white,
            height: 1.0,
          ),
        ),
      ),
    );
  });
}
