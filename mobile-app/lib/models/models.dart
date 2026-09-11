/// Modèles de données FriPay — miroir de src/lib/fripay-data.ts (web).
library;

enum OperatorId { mtn, moov, celtiis, fripay }

extension OperatorIdX on OperatorId {
  String get id => name;
}

class AppOperator {
  final OperatorId id;
  final String name;
  final String short;

  const AppOperator({required this.id, required this.name, required this.short});
}

const List<AppOperator> operators = [
  AppOperator(id: OperatorId.mtn, name: 'MTN MoMo', short: 'MTN'),
  AppOperator(id: OperatorId.moov, name: 'Moov Money', short: 'Moov'),
  AppOperator(id: OperatorId.celtiis, name: 'Celtiis Cash', short: 'Celtiis'),
  AppOperator(id: OperatorId.fripay, name: 'Solde FriPay', short: 'FriPay'),
];

AppOperator operatorById(OperatorId id) =>
    operators.firstWhere((o) => o.id == id, orElse: () => operators.last);

enum WalletStatus { actif, enAttente, horsLigne }

class Wallet {
  final OperatorId id;
  final String number;
  final int balance;
  final WalletStatus status;
  final bool primary;

  const Wallet({
    required this.id,
    required this.number,
    required this.balance,
    required this.status,
    this.primary = false,
  });
}

final List<Wallet> wallets = [
  const Wallet(
    id: OperatorId.fripay,
    number: 'Compte principal',
    balance: 148500,
    status: WalletStatus.actif,
    primary: true,
  ),
  const Wallet(
    id: OperatorId.mtn,
    number: '+229 97 12 44 08',
    balance: 62300,
    status: WalletStatus.actif,
  ),
  const Wallet(
    id: OperatorId.moov,
    number: '+229 95 08 71 33',
    balance: 24750,
    status: WalletStatus.actif,
  ),
  const Wallet(
    id: OperatorId.celtiis,
    number: '+229 01 62 30 19',
    balance: 8000,
    status: WalletStatus.enAttente,
  ),
];

int get totalBalance => wallets.fold(0, (sum, w) => sum + w.balance);

enum TxDirection { entrant, sortant }

enum TxStatus { reussi, enAttente, echoue, horsLigne }

enum TxCategory { transfert, facture, marchand, recharge, retrait }

class Tx {
  final String id;
  final String label;
  final String counterparty;
  final OperatorId from;
  final OperatorId to;
  final int amount;
  final TxDirection direction;
  final String date;
  final TxStatus status;
  final TxCategory category;

  const Tx({
    required this.id,
    required this.label,
    required this.counterparty,
    required this.from,
    required this.to,
    required this.amount,
    required this.direction,
    required this.date,
    required this.status,
    required this.category,
  });
}

class Biller {
  final String id;
  final String name;
  final String kind;

  const Biller({required this.id, required this.name, required this.kind});
}

class AppContact {
  final String name;
  final String phone;
  final OperatorId op;

  const AppContact({required this.name, required this.phone, required this.op});
}

enum TicketReason { wrongTransfer, notReceived, duplicateCharge, accountIssue, other }

class TicketReasonInfo {
  final TicketReason value;
  final String label;
  final bool refundEligible;

  const TicketReasonInfo({
    required this.value,
    required this.label,
    required this.refundEligible,
  });
}

const List<TicketReasonInfo> ticketReasons = [
  TicketReasonInfo(
    value: TicketReason.wrongTransfer,
    label: "Transfert erroné (mauvais destinataire / montant)",
    refundEligible: true,
  ),
  TicketReasonInfo(
    value: TicketReason.notReceived,
    label: "Transfert effectué mais je n'ai rien reçu",
    refundEligible: true,
  ),
  TicketReasonInfo(
    value: TicketReason.duplicateCharge,
    label: "J'ai été débité en double",
    refundEligible: true,
  ),
  TicketReasonInfo(
    value: TicketReason.accountIssue,
    label: 'Problème de compte / accès',
    refundEligible: false,
  ),
  TicketReasonInfo(value: TicketReason.other, label: 'Autre problème', refundEligible: false),
];

enum TicketStatus { open, inProgress, resolved, rejected }

enum RefundStatus { notApplicable, requested, approved, rejected, processed }

const Map<TicketStatus, String> statusLabel = {
  TicketStatus.open: 'Ouvert',
  TicketStatus.inProgress: 'En cours',
  TicketStatus.resolved: 'Résolu',
  TicketStatus.rejected: 'Rejeté',
};

const Map<RefundStatus, String> refundStatusLabel = {
  RefundStatus.notApplicable: '—',
  RefundStatus.requested: 'Remboursement demandé',
  RefundStatus.approved: 'Remboursement approuvé',
  RefundStatus.rejected: 'Remboursement refusé',
  RefundStatus.processed: 'Remboursement traité',
};

class Ticket {
  final String id;
  final String reference;
  final TicketReason reason;
  final String subject;
  final String description;
  final TicketStatus status;
  final String? linkedTransactionId;
  final bool refundRequested;
  final RefundStatus refundStatus;
  final int? refundAmount;
  final String createdAt;

  const Ticket({
    required this.id,
    required this.reference,
    required this.reason,
    required this.subject,
    required this.description,
    required this.status,
    this.linkedTransactionId,
    required this.refundRequested,
    required this.refundStatus,
    this.refundAmount,
    required this.createdAt,
  });
}
