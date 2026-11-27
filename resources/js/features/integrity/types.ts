export type BlockKind = 'genesis' | 'weekly' | 'monthly';

export type BlockRow = {
    id: string;
    height: number;
    kind: BlockKind;
    period_key: string | null;
    hash: string;
    leaves_count: number;
    sealed_at: string;
};

export type BlockSortKey = 'height';

export type ChainIssue = {
    type: string;
    height: number;
    kind: string;
    period_key: string | null;
    detail?: string;
};

export type ChainCheck = {
    at: string;
    intact: boolean;
    matches_books: boolean;
    issues: ChainIssue[];
    unsealed: { kind: string; period_key: string }[];
};

export type IntegrityStatus = {
    blocks: number;
    height: number | null;
    head_hash: string | null;
    started_at: string | null;
    legacy: { height: number; hash: string } | null;
    last_chain_check: ChainCheck | null;
};

export type CheckResult = {
    id: string;
    title: string;
    passed: boolean;
    findings: string[];
};

export type HistoryEntry = {
    id: string;
    at: string;
    action: string;
    summary: string;
    passed: boolean;
};

export type BlockDetail = {
    block: {
        height: number;
        kind: BlockKind;
        period_key: string | null;
        prev_hash: string;
        merkle_root: string;
        payload_hash: string;
        hash: string;
        leaves_count: number;
        sealed_at: string;
        sealed_by: string | null;
    };
    hash_recomputes: boolean;
    payload_recomputes: boolean;
    books: 'matches' | 'drifted' | 'unavailable';
    payload: unknown;
    leaves: { key: string; leaf: string }[];
    leaves_total: number;
    proofs_offered: boolean;
};

export type ProofStatus =
    | 'valid'
    | 'changed'
    | 'missing'
    | 'unsealed'
    | 'unsupported';

export type RecordProof = {
    status: ProofStatus;
    kind: string;
    period_key: string;
    record_key: string;
    height: number | null;
    block_hash: string | null;
    merkle_root: string | null;
    leaf: string | null;
    steps: { side: 'left' | 'right'; hash: string }[];
};
