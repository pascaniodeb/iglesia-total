import type { FacetOption } from '@/components/data-table/types';
import type { TableFilters } from '@/types';

/** A row of the members listing. */
export type MemberRow = {
    id: string;
    full_name: string;
    document: string | null;
    phone: string | null;
    joined_date: string | null;
    status: MemberStatus | null;
    status_label: string | null;
    is_deleted: boolean;
};

/** Mirrors App\Enums\MemberStatus (a test keeps both in sync). */
export type MemberStatus = 'activo' | 'trasladado' | 'inactivo' | 'fallecido';

export type MemberSortKey = 'name' | 'document' | 'joined' | 'status';

export type MemberFilters = TableFilters<MemberSortKey> & {
    member_status: string;
    sex: string;
};

/** The choices of the form's selects (labels come translated from the server). */
export type MemberOptions = {
    document_types: FacetOption[];
    sexes: FacetOption[];
    education_levels: FacetOption[];
    marital_statuses: FacetOption[];
    statuses: FacetOption[];
};

export type MemberChild = {
    name: string;
    birth_date: string;
};

export type MemberRole = {
    role: string;
    since: string;
    until: string;
};

export type SpouseOption = {
    id: string;
    name: string;
    document: string | null;
};

/** The registry card as the server sends it (null where nothing was recorded). */
export type MemberDetail = {
    id: string;
    full_name: string;
    document_type: string | null;
    document_id: string | null;
    sex: string | null;
    birth_date: string | null;
    birth_place_city: string | null;
    birth_place_state: string | null;
    phone: string | null;
    landline_phone: string | null;
    email: string | null;
    address_state: string | null;
    address_municipality: string | null;
    address_parish: string | null;
    address: string | null;
    education_level: string | null;
    profession: string | null;
    occupation: string | null;
    marital_status: string | null;
    spouse: { id: string; name: string } | null;
    baptism_date: string | null;
    baptism_place: string | null;
    baptism_pastor: string | null;
    holy_spirit_date: string | null;
    joined_date: string | null;
    status: string | null;
    pastor_notes: string | null;
    children: { name: string; birth_date: string | null }[];
    roles: { role: string; since: string | null; until: string | null }[];
    is_deleted: boolean;
};

/**
 * The form state. Empty text is '' (the server turns it into null) and the repeaters carry the
 * whole list, which the server replaces.
 */
export type MemberFormData = {
    full_name: string;
    document_type: string;
    document_id: string;
    sex: string;
    birth_date: string;
    birth_place_city: string;
    birth_place_state: string;
    phone: string;
    landline_phone: string;
    email: string;
    address_state: string;
    address_municipality: string;
    address_parish: string;
    address: string;
    education_level: string;
    profession: string;
    occupation: string;
    marital_status: string;
    spouse_id: string;
    baptism_date: string;
    baptism_place: string;
    baptism_pastor: string;
    holy_spirit_date: string;
    joined_date: string;
    status: string;
    pastor_notes: string;
    children: MemberChild[];
    roles: MemberRole[];
};
