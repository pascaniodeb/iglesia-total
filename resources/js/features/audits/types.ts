export type Severity = 'critical' | 'warning' | 'info';

export type Acknowledgement = {
    id: string;
    reason: string;
    by: string | null;
    at: string;
};

export type FindingRow = {
    subject: string;
    detail: string;
    fingerprint: string;
    acknowledgement: Acknowledgement | null;
};

export type CheckRow = {
    id: string;
    label: string;
    description: string;
    severity: Severity;
    pending: number;
    acknowledged: number;
    findings: FindingRow[];
};

export type Evaluation = {
    checks: CheckRow[];
    pending_count: number;
    critical_count: number;
    acknowledged_count: number;
};

export type AuditSummary = {
    id: string;
    ran_at: string;
    ran_by: string | null;
    status: 'clean' | 'findings';
    findings_count: number;
    critical_count: number;
    acknowledged_count: number;
    notes: string | null;
    is_imported: boolean;
};

export type AcknowledgementRow = {
    id: string;
    check_id: string;
    subject: string;
    detail: string;
    reason: string;
    by: string | null;
    at: string;
    revoked: { reason: string; by: string | null; at: string } | null;
};
