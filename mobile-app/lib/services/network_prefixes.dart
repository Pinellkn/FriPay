/// MODULE CENTRALISÉ DE GESTION DES PRÉFIXES RÉSEAU (cahier des charges §4).
///
/// Toute la connaissance « quel préfixe appartient à quel réseau » vit ICI et
/// nulle part ailleurs. Aucun écran ne doit coder un préfixe en dur : il
/// appelle `NetworkPrefixes`.
///
/// 4 catégories de numéros :
///   - FriPay  : 10 chiffres commençant par `30` (numéro interne, §1)
///   - MTN / Moov / Celtiis : `01` + préfixe propre à l'opérateur (§2)
///
/// Source des préfixes opérateurs : liste ARCEP Bénin publiée en février 2026
/// (plan national de numérotation à 10 chiffres, en vigueur depuis le
/// 30/11/2024). Voir `prefixesSource` / `prefixesUpdatedAt` ci-dessous.
///
/// ⚠️ Portabilité des numéros : l'ARCEP rappelle qu'un abonné peut conserver
/// son numéro en changeant d'opérateur. La détection par préfixe reste donc
/// une *présomption* — fiable pour pré-remplir et pour bloquer une saisie
/// manifestement incohérente, mais jamais une preuve absolue du réseau réel.
library;

import 'package:fripay_app/models/models.dart';

class NetworkPrefixes {
  NetworkPrefixes._();

  /// Indicatif pays, pré-rempli et NON modifiable par l'utilisateur (§2).
  static const String countryCode = '+229';

  /// Préfixe national obligatoire devant tout numéro d'opérateur (§2).
  static const String nationalPrefix = '01';

  /// Préfixe des numéros FriPay internes (§1) : 30 + 8 chiffres = 10 chiffres.
  static const String fripayPrefix = '30';

  /// Longueur totale d'un numéro FriPay (§1).
  static const int fripayLength = 10;

  /// Nombre de chiffres après `01` pour un numéro d'opérateur béninois.
  /// Format final : 229 01 XX XX XX XX → `01` + 8 chiffres.
  static const int operatorDigitsAfterNationalPrefix = 8;

  static const String prefixesSource =
      'ARCEP Bénin — liste des préfixes attribués aux opérateurs mobiles';
  static const String prefixesUpdatedAt = '2026-02';

  /// Préfixes à 2 chiffres, tels qu'ils apparaissent JUSTE APRÈS le `01`.
  /// (Ex. : `01 97 99 98 88` → préfixe `97` → MTN.)
  static const Map<OperatorId, List<String>> byOperator = {
    OperatorId.mtn: [
      '42', '46', '50', '51', '52', '53', '54', '56', '57',
      '59', '61', '62', '66', '67', '69', '90', '91', '96', '97',
    ],
    OperatorId.moov: [
      '45', '55', '58', '60', '63', '64', '65', '68', '94', '95', '98', '99',
    ],
    OperatorId.celtiis: [
      '20', '21', '22', '23', '24', '28', '29', '40',
      '41', '43', '44', '47', '48', '49', '92', '93',
    ],
  };

  /// Liste des préfixes d'un opérateur (vide pour OperatorId.fripay, qui
  /// n'utilise pas le plan `01`).
  static List<String> forOperator(OperatorId id) => byOperator[id] ?? const [];

  /// Tous les préfixes opérateurs connus, tous réseaux confondus.
  static List<String> get all =>
      byOperator.values.expand((l) => l).toList(growable: false);

  // ---------------------------------------------------------------------
  // Normalisation
  // ---------------------------------------------------------------------

  /// Ne garde que les chiffres et un éventuel `+` de tête.
  static String digitsOnly(String input) => input.replaceAll(RegExp(r'[^0-9]'), '');

  /// Ramène n'importe quelle saisie à la forme canonique `+22901XXXXXXXX`.
  /// Pour un numéro FriPay, retourne `30XXXXXXXX` SANS indicatif +229 — le
  /// numéro FriPay n'en porte jamais (cahier §1 : "30" + 8 chiffres, point).
  ///
  /// Accepte : `0197999888`, `+229 01 97 99 98 88`, `229 01 97 99 98 88`,
  /// `97 99 98 88` (ancien format 8 chiffres, le `01` est alors ajouté),
  /// `3012345678` ou `+22930 12 34 56 78` (numéro FriPay, avec ou sans
  /// indicatif saisi par erreur).
  static String normalize(String input) {
    var d = digitsOnly(input);
    if (d.startsWith('229')) d = d.substring(3);
    // Numéro FriPay : jamais d'indicatif +229, quelle que soit la saisie.
    if (d.length == fripayLength && d.startsWith(fripayPrefix)) {
      return d;
    }
    // Ancien format à 8 chiffres : on rétablit le `01` du nouveau plan.
    if (d.length == operatorDigitsAfterNationalPrefix &&
        !d.startsWith(nationalPrefix)) {
      d = '$nationalPrefix$d';
    }
    return '$countryCode$d';
  }

  /// Les 10 chiffres nationaux (sans `+229`) d'un numéro normalisé.
  static String nationalDigits(String input) {
    final n = normalize(input);
    return n.startsWith(countryCode) ? n.substring(countryCode.length) : n;
  }

  // ---------------------------------------------------------------------
  // Numéro FriPay (§1)
  // ---------------------------------------------------------------------

  /// `true` si la saisie est un numéro FriPay : 10 chiffres, préfixe `30`.
  static bool isFripayNumber(String input) {
    final d = digitsOnly(input);
    final n = d.startsWith('229') ? d.substring(3) : d;
    return n.length == fripayLength && n.startsWith(fripayPrefix);
  }

  // ---------------------------------------------------------------------
  // Détection / validation opérateur (§2, §4)
  // ---------------------------------------------------------------------

  /// Extrait le préfixe à 2 chiffres qui suit le `01`, ou `null` si la saisie
  /// n'a pas encore cette forme.
  static String? extractPrefix(String input) {
    final n = nationalDigits(input);
    if (!n.startsWith(nationalPrefix) || n.length < 4) return null;
    return n.substring(2, 4);
  }

  /// Devine l'opérateur d'un numéro. `null` si inconnu ou saisie incomplète.
  /// Un numéro FriPay renvoie `OperatorId.fripay`.
  static OperatorId? detectOperator(String input) {
    if (isFripayNumber(input)) return OperatorId.fripay;
    final p = extractPrefix(input);
    if (p == null) return null;
    for (final entry in byOperator.entries) {
      if (entry.value.contains(p)) return entry.key;
    }
    return null;
  }

  /// `true` si le préfixe saisi appartient bien à l'opérateur choisi (§2).
  static bool matchesOperator(String input, OperatorId operator) {
    final p = extractPrefix(input);
    if (p == null) return false;
    return forOperator(operator).contains(p);
  }

  /// Numéro d'opérateur complet et valide : `01` + 8 chiffres, préfixe connu.
  static bool isValidOperatorNumber(String input) {
    final n = nationalDigits(input);
    if (n.length != fripayLength) return false;
    if (!n.startsWith(nationalPrefix)) return false;
    return detectOperator(input) != null;
  }

  // ---------------------------------------------------------------------
  // Messages d'erreur automatiques (§2)
  // ---------------------------------------------------------------------

  /// Message d'erreur à afficher, ou `null` si le numéro est accepté.
  ///
  /// [expected] : opérateur choisi par l'utilisateur à l'inscription. S'il est
  /// fourni, la cohérence préfixe ↔ opérateur est vérifiée en plus du format.
  static String? validationError(String input, {OperatorId? expected}) {
    final n = nationalDigits(input);

    if (n.isEmpty) return 'Saisissez votre numéro de téléphone.';

    if (!n.startsWith(nationalPrefix)) {
      return 'Le numéro doit commencer par $nationalPrefix '
          '(nouveau plan de numérotation du Bénin).';
    }

    if (n.length < fripayLength) {
      return 'Numéro incomplet : il doit contenir $fripayLength chiffres '
          'après l\'indicatif $countryCode.';
    }
    if (n.length > fripayLength) {
      return 'Numéro trop long : $fripayLength chiffres attendus '
          'après l\'indicatif $countryCode.';
    }

    final detected = detectOperator(input);
    if (detected == null) {
      final p = extractPrefix(input);
      return 'Préfixe $p inconnu. Vérifiez le numéro : aucun opérateur '
          'béninois n\'utilise ce préfixe.';
    }

    if (expected != null && detected != expected) {
      return 'Ce numéro est un numéro ${operatorById(detected).short}, '
          'pas ${operatorById(expected).short}. Choisissez le bon réseau '
          'ou corrigez le numéro.';
    }

    return null;
  }

  // ---------------------------------------------------------------------
  // Affichage
  // ---------------------------------------------------------------------

  /// Formate en 10 chiffres espacés (ex: `01 97 00 00 00` ou `30 12 34 56 78`).
  /// Retire l'indicatif pays pour l'affichage local (cahier des charges).
  static String format(String input) {
    final n = nationalDigits(input);
    if (n.length != fripayLength) return n;
    final pairs = <String>[];
    for (var i = 0; i < n.length; i += 2) {
      pairs.add(n.substring(i, i + 2));
    }
    return pairs.join(' ');
  }
}
