import 'package:intl/intl.dart';

import '../models/models.dart';

final NumberFormat _fcfaFormat = NumberFormat.decimalPattern('fr_FR');

/// Miroir de formatFCFA() du web.
String formatFCFA(num n) => '${_fcfaFormat.format(n)} FCFA';

final DateFormat _shortDateFormat = DateFormat('dd/MM/yyyy à HH:mm', 'fr_FR');

/// Formate une date backend (souvent en UTC) en heure locale lisible.
/// [null] -> chaîne vide (ex. date pas encore connue côté serveur).
String formatDateTime(DateTime? d) => d == null ? '' : _shortDateFormat.format(d.toLocal());

/// Miroir de feeFor() du web : frais dégressifs, 0,9% inter-réseaux,
/// 0,5% FriPay <-> même réseau, arrondi au multiple de 5, minimum 25 FCFA.
int feeFor(int amount, OperatorId from, OperatorId to) {
  if (amount <= 0) return 0;
  final crossNetwork =
      from != to && from != OperatorId.fripay && to != OperatorId.fripay;
  final rate = crossNetwork ? 0.009 : 0.005;
  final raw = (amount * rate / 5).round() * 5;
  return raw < 25 ? 25 : raw;
}
