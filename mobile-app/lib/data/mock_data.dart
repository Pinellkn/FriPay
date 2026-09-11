import '../models/models.dart';

/// Données de démonstration — miroir de src/lib/fripay-data.ts (web).
/// À remplacer par de vrais appels API FriPay (fripay-users / fripay-payments)
/// quand le client mobile sera branché — voir README §Prochaines étapes.

final List<Tx> transactions = [
  const Tx(
    id: 'TX-90412',
    label: 'Transfert à Ange',
    counterparty: '+229 95 08 71 33',
    from: OperatorId.mtn,
    to: OperatorId.moov,
    amount: 15000,
    direction: TxDirection.sortant,
    date: "Aujourd'hui · 14:32",
    status: TxStatus.reussi,
    category: TxCategory.transfert,
  ),
  const Tx(
    id: 'TX-90408',
    label: 'Facture SBEE',
    counterparty: 'Abonné 4410992',
    from: OperatorId.fripay,
    to: OperatorId.fripay,
    amount: 23400,
    direction: TxDirection.sortant,
    date: "Aujourd'hui · 09:11",
    status: TxStatus.reussi,
    category: TxCategory.facture,
  ),
  const Tx(
    id: 'TX-90391',
    label: 'Reçu de Koffi',
    counterparty: '+229 01 62 30 19',
    from: OperatorId.celtiis,
    to: OperatorId.fripay,
    amount: 40000,
    direction: TxDirection.entrant,
    date: 'Hier · 19:48',
    status: TxStatus.reussi,
    category: TxCategory.transfert,
  ),
  const Tx(
    id: 'TX-90387',
    label: 'Boutique du Pagne',
    counterparty: 'Marchand · Dantokpa',
    from: OperatorId.moov,
    to: OperatorId.fripay,
    amount: 5000,
    direction: TxDirection.sortant,
    date: 'Hier · 17:02',
    status: TxStatus.reussi,
    category: TxCategory.marchand,
  ),
  const Tx(
    id: 'TX-90370',
    label: 'Transfert à Mariam',
    counterparty: '+229 97 44 12 60',
    from: OperatorId.fripay,
    to: OperatorId.mtn,
    amount: 7500,
    direction: TxDirection.sortant,
    date: '12 juil. · 11:20',
    status: TxStatus.horsLigne,
    category: TxCategory.transfert,
  ),
  const Tx(
    id: 'TX-90362',
    label: 'Dépôt agent Godomey',
    counterparty: 'Agent #2214',
    from: OperatorId.mtn,
    to: OperatorId.fripay,
    amount: 100000,
    direction: TxDirection.entrant,
    date: '11 juil. · 08:05',
    status: TxStatus.reussi,
    category: TxCategory.recharge,
  ),
  const Tx(
    id: 'TX-90341',
    label: 'Retrait agent Calavi',
    counterparty: 'Agent #1183',
    from: OperatorId.fripay,
    to: OperatorId.celtiis,
    amount: 30000,
    direction: TxDirection.sortant,
    date: '09 juil. · 16:44',
    status: TxStatus.echoue,
    category: TxCategory.retrait,
  ),
];

const List<Biller> billers = [
  Biller(id: 'sbee', name: 'SBEE', kind: 'Électricité'),
  Biller(id: 'soneb', name: 'SONEB', kind: 'Eau'),
  Biller(id: 'canal', name: 'Canal+ Bénin', kind: 'TV'),
  Biller(id: 'mtn-data', name: 'Forfait MTN', kind: 'Internet'),
  Biller(id: 'moov-data', name: 'Forfait Moov', kind: 'Internet'),
  Biller(id: 'scolarite', name: 'Scolarité', kind: 'Éducation'),
];

const List<AppContact> contacts = [
  AppContact(name: 'Ange Dossou', phone: '+229 95 08 71 33', op: OperatorId.moov),
  AppContact(name: 'Koffi Adjovi', phone: '+229 01 62 30 19', op: OperatorId.celtiis),
  AppContact(name: 'Mariam Bio', phone: '+229 97 44 12 60', op: OperatorId.mtn),
  AppContact(name: 'Rachidath L.', phone: '+229 96 55 20 87', op: OperatorId.mtn),
  AppContact(name: 'Sègbè Houns.', phone: '+229 94 31 09 55', op: OperatorId.moov),
];

final List<Ticket> tickets = [
  const Ticket(
    id: 'TCK-1',
    reference: 'TCK-202607-0001',
    reason: TicketReason.notReceived,
    subject: "Transfert effectué mais je n'ai rien reçu",
    description:
        "J'ai envoyé 7 500 FCFA à Mariam le 12 juillet mais elle n'a rien reçu de son côté.",
    status: TicketStatus.inProgress,
    linkedTransactionId: 'TX-90370',
    refundRequested: true,
    refundStatus: RefundStatus.requested,
    refundAmount: 7500,
    createdAt: '13 juil. · 09:14',
  ),
  const Ticket(
    id: 'TCK-2',
    reference: 'TCK-202607-0002',
    reason: TicketReason.duplicateCharge,
    subject: "J'ai été débité en double",
    description:
        "Le retrait chez l'agent de Calavi a échoué mais j'ai vu le montant débité deux fois.",
    status: TicketStatus.resolved,
    linkedTransactionId: 'TX-90341',
    refundRequested: true,
    refundStatus: RefundStatus.processed,
    refundAmount: 30000,
    createdAt: '09 juil. · 17:20',
  ),
];
