-- Préfixes opérateurs Bénin — plan national de numérotation (réforme du 30/11/2024).
-- Format : « 01 » + 8 chiffres, préfixe stocké en E.164 sans « + » : 229 + 01 + XX.
-- Aligné sur les seeders OperatorSeeder (fripay-users/payments/admin) et le
-- module mobile lib/services/network_prefixes.dart (cahier des charges §4).
--
-- ⚠️ Script de MAINTENANCE (base existante) : pour une base neuve, utiliser
--    php artisan db:seed --class=OperatorSeeder dans chaque service.
--
-- Les IDs opérateurs sont résolus par CODE (MTN/MOOV/CELTIIS) et non
-- hardcodés : le script fonctionne quelle que soit l'historique de seed
-- de la base (ids 1/2/3 ou 4/5/6, etc.).

DELETE pp FROM phone_prefixes pp
JOIN operators o ON o.id = pp.operator_id
WHERE o.code IN ('MTN', 'MOOV', 'CELTIIS');

-- MTN Bénin
INSERT INTO phone_prefixes (operator_id, prefix, country_code)
SELECT o.id, t.prefix, 'BJ' FROM operators o
JOIN (
  SELECT '2290142' AS prefix UNION ALL SELECT '2290146' UNION ALL SELECT '2290150' UNION ALL
  SELECT '2290151' UNION ALL SELECT '2290152' UNION ALL SELECT '2290153' UNION ALL
  SELECT '2290154' UNION ALL SELECT '2290156' UNION ALL SELECT '2290157' UNION ALL
  SELECT '2290159' UNION ALL SELECT '2290161' UNION ALL SELECT '2290162' UNION ALL
  SELECT '2290166' UNION ALL SELECT '2290167' UNION ALL SELECT '2290169' UNION ALL
  SELECT '2290190' UNION ALL SELECT '2290191' UNION ALL SELECT '2290196' UNION ALL
  SELECT '2290197'
) t
WHERE o.code = 'MTN';

-- Moov Africa Bénin
INSERT INTO phone_prefixes (operator_id, prefix, country_code)
SELECT o.id, t.prefix, 'BJ' FROM operators o
JOIN (
  SELECT '2290145' AS prefix UNION ALL SELECT '2290155' UNION ALL SELECT '2290158' UNION ALL
  SELECT '2290160' UNION ALL SELECT '2290163' UNION ALL SELECT '2290164' UNION ALL
  SELECT '2290165' UNION ALL SELECT '2290168' UNION ALL SELECT '2290194' UNION ALL
  SELECT '2290195' UNION ALL SELECT '2290198' UNION ALL SELECT '2290199'
) t
WHERE o.code = 'MOOV';

-- Celtiis Bénin / SBIN
INSERT INTO phone_prefixes (operator_id, prefix, country_code)
SELECT o.id, t.prefix, 'BJ' FROM operators o
JOIN (
  SELECT '2290120' AS prefix UNION ALL SELECT '2290121' UNION ALL SELECT '2290122' UNION ALL
  SELECT '2290123' UNION ALL SELECT '2290124' UNION ALL SELECT '2290128' UNION ALL
  SELECT '2290129' UNION ALL SELECT '2290140' UNION ALL SELECT '2290141' UNION ALL
  SELECT '2290143' UNION ALL SELECT '2290144' UNION ALL SELECT '2290147' UNION ALL
  SELECT '2290148' UNION ALL SELECT '2290149' UNION ALL SELECT '2290192' UNION ALL
  SELECT '2290193'
) t
WHERE o.code = 'CELTIIS';
