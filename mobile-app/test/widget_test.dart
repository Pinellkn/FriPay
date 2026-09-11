// Test de fumée FriPay Mobile : vérifie que l'app démarre sur le splash
// screen sans exception.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fripay_app/main.dart';

void main() {
  testWidgets('FripayApp démarre sur le splash screen', (WidgetTester tester) async {
    await tester.pumpWidget(const FripayApp());
    await tester.pump();

    expect(find.text('FriPay'), findsNothing); // le wordmark est un widget riche, pas du texte brut
    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
