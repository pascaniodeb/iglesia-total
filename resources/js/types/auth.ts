/** Mirrors App\Enums\UserRole (a test keeps both in sync). */
export type UserRole =
    | 'pastor'
    | 'asistente'
    | 'secretario'
    | 'tesorero'
    | 'contralor'
    | 'miembro';

/** Mirrors App\Enums\Permission (a test keeps both in sync). */
export type Permission =
    | 'dashboard.view'
    | 'members.view'
    | 'members.manage'
    | 'member_reports.view'
    | 'church_settings.manage'
    | 'assets.view'
    | 'assets.manage'
    | 'offerings.view'
    | 'offerings.manage'
    | 'closures.view'
    | 'closures.manage'
    | 'accounting.view'
    | 'accounting.manage'
    | 'catalogs.view'
    | 'catalogs.manage'
    | 'receipts.administer'
    | 'tithes.view'
    | 'tithes.manage'
    | 'sealed_ledger.view'
    | 'audits.view'
    | 'audits.record'
    | 'activity_log.view'
    | 'users.manage';

export type User = {
    id: string;
    name: string;
    email: string;
    role: UserRole;
    is_active: boolean;
    member_id: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** What the account may do; the server enforces it, the interface only hides the rest. */
    permissions: Permission[];
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
