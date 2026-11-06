import { usePage } from '@inertiajs/react';
import type { Permission } from '@/types/auth';

export type UsePermissionsReturn = {
    /** Whether the signed-in account holds the permission. */
    readonly can: (permission: Permission) => boolean;
};

/**
 * Ask for permissions, never for roles: the role-to-permission map lives only on the
 * server (App\Enums\UserRole). Hiding something here is a courtesy; the server is what
 * actually refuses the request.
 */
export function usePermissions(): UsePermissionsReturn {
    const { auth } = usePage().props;
    const granted = new Set<Permission>(auth.permissions);

    return { can: (permission) => granted.has(permission) };
}
