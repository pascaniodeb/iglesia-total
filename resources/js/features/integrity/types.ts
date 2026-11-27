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
