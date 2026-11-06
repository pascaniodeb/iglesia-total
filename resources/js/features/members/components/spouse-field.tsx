import { useCallback } from 'react';
import { RemoteSearchField } from '@/components/form/remote-search-field';
import { spouseOptions } from '@/routes/members';

type SpouseFieldProps = {
    /** The chosen spouse, or null. */
    value: { id: string; name: string } | null;
    onChange: (spouse: { id: string; name: string } | null) => void;
    /** The member being edited: never offered as their own spouse. */
    exceptId?: string;
    error?: string;
    disabled?: boolean;
};

/** Picks the spouse from the registry, searched on the server. */
export function SpouseField({
    value,
    onChange,
    exceptId,
    error,
    disabled,
}: SpouseFieldProps) {
    const searchUrl = useCallback(
        (search: string) =>
            spouseOptions({
                query: { search, ...(exceptId ? { except: exceptId } : {}) },
            }).url,
        [exceptId],
    );

    return (
        <RemoteSearchField
            label="Cónyuge"
            description="Se vincula en las dos fichas."
            value={value}
            onChange={onChange}
            searchUrl={searchUrl}
            placeholder="Buscar miembro…"
            emptyText="Ningún miembro disponible coincide."
            resultsLabel="Miembros que coinciden"
            error={error}
            disabled={disabled}
        />
    );
}
