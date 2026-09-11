import '../models/models.dart';
import '../utils/formatters.dart';
import 'api_client.dart';

TicketReason _reasonFromApi(String v) => switch (v) {
      'wrong_transfer' => TicketReason.wrongTransfer,
      'not_received' => TicketReason.notReceived,
      'duplicate_charge' => TicketReason.duplicateCharge,
      'account_issue' => TicketReason.accountIssue,
      _ => TicketReason.other,
    };

/// Miroir inverse : valeur attendue par POST /complaints (`reason`).
String reasonToApi(TicketReason r) => switch (r) {
      TicketReason.wrongTransfer => 'wrong_transfer',
      TicketReason.notReceived => 'not_received',
      TicketReason.duplicateCharge => 'duplicate_charge',
      TicketReason.accountIssue => 'account_issue',
      TicketReason.other => 'other',
    };

TicketStatus _statusFromApi(String v) => switch (v) {
      'in_progress' => TicketStatus.inProgress,
      'resolved' => TicketStatus.resolved,
      'rejected' => TicketStatus.rejected,
      _ => TicketStatus.open,
    };

RefundStatus _refundStatusFromApi(String v) => switch (v) {
      'requested' => RefundStatus.requested,
      'approved' => RefundStatus.approved,
      'rejected' => RefundStatus.rejected,
      'processed' => RefundStatus.processed,
      _ => RefundStatus.notApplicable,
    };

/// Plainte — miroir de ComplaintResource (fripay-payments).
class ApiComplaint {
  final String id;
  final String reference;
  final String reason; // wrong_transfer|not_received|duplicate_charge|account_issue|other
  final String subject;
  final String description;
  final String status; // open|in_progress|resolved|rejected
  final String? linkedTransactionId;
  final bool refundRequested;
  final String refundStatus; // not_applicable|requested|approved|rejected|processed
  final int? refundAmount;
  final DateTime? createdAt;

  ApiComplaint({
    required this.id,
    required this.reference,
    required this.reason,
    required this.subject,
    required this.description,
    required this.status,
    required this.linkedTransactionId,
    required this.refundRequested,
    required this.refundStatus,
    required this.refundAmount,
    required this.createdAt,
  });

  factory ApiComplaint.fromJson(Map<String, dynamic> j) => ApiComplaint(
        id: '${j['id']}',
        reference: (j['reference'] as String?) ?? '',
        reason: (j['reason'] as String?) ?? 'other',
        subject: (j['subject'] as String?) ?? '',
        description: (j['description'] as String?) ?? '',
        status: (j['status'] as String?) ?? 'open',
        linkedTransactionId: j['linked_transaction_id'] as String?,
        refundRequested: (j['refund_requested'] as bool?) ?? false,
        refundStatus: (j['refund_status'] as String?) ?? 'not_applicable',
        refundAmount: (j['refund_amount'] as num?)?.round(),
        createdAt: j['created_at'] != null ? DateTime.tryParse('${j['created_at']}') : null,
      );

  /// Convertit vers le modèle UI [Ticket] (mêmes écrans que le mock).
  Ticket toTicket() => Ticket(
        id: id,
        reference: reference,
        reason: _reasonFromApi(reason),
        subject: subject,
        description: description,
        status: _statusFromApi(status),
        linkedTransactionId: linkedTransactionId,
        refundRequested: refundRequested,
        refundStatus: _refundStatusFromApi(refundStatus),
        refundAmount: refundAmount,
        createdAt: formatDateTime(createdAt),
      );
}

/// Service Plaintes — miroir de ComplaintController (fripay-payments).
class ComplaintService {
  ComplaintService._();
  static final ComplaintService instance = ComplaintService._();
  final _api = ApiClient.instance;

  /// GET /complaints
  Future<List<ApiComplaint>> list() async {
    final res = await _api.get('/complaints');
    final data = (res is Map<String, dynamic>) ? res['data'] as List? : null;
    return (data ?? []).map((e) => ApiComplaint.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// POST /complaints
  Future<ApiComplaint> create({
    required String reason,
    required String subject,
    required String description,
    String? linkedTransactionId,
  }) async {
    final res = await _api.post('/complaints', body: {
      'reason': reason,
      'subject': subject,
      'description': description,
      if (linkedTransactionId != null) 'linked_transaction_id': linkedTransactionId,
    });
    return ApiComplaint.fromJson(res as Map<String, dynamic>);
  }

  /// GET /complaints/{id}
  Future<ApiComplaint> show(String id) async {
    final res = await _api.get('/complaints/$id');
    return ApiComplaint.fromJson(res as Map<String, dynamic>);
  }
}
