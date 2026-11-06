import type { ReactNode } from 'react';
import { DateField } from '@/components/form/date-field';
import { Cell } from '@/components/form/form-section';
import { SelectField } from '@/components/form/select-field';
import { TextField } from '@/components/form/text-field';
import { TextareaField } from '@/components/form/textarea-field';
import {
    ChildrenField,
    RolesField,
} from '@/features/members/components/member-repeaters';
import { SpouseField } from '@/features/members/components/spouse-field';
import type { MemberFormData, MemberOptions } from '@/features/members/types';
import { todayIso } from '@/lib/date';

export type MemberStepProps = {
    data: MemberFormData;
    setData: <K extends keyof MemberFormData>(
        key: K,
        value: MemberFormData[K],
    ) => void;
    errors: Record<string, string | undefined>;
    options: MemberOptions;
    disabled: boolean;
    spouse: { id: string; name: string } | null;
    onSpouseChange: (spouse: { id: string; name: string } | null) => void;
    /** The member being edited (absent when registering). */
    memberId?: string;
};

/**
 * Grid of a section. The width is measured on the container, not the screen: one column on a
 * phone, two on a tablet and six parts (thirds, halves) when there is room.
 */
function StepGrid({ children }: { children: ReactNode }) {
    return (
        <div className="@container min-w-0">
            <div className="grid grid-cols-1 gap-x-6 gap-y-4 @md:grid-cols-2 @2xl:grid-cols-6">
                {children}
            </div>
        </div>
    );
}

const CLEAR = 'Sin especificar';

export function IdentificationStep({
    data,
    setData,
    errors,
    options,
}: MemberStepProps) {
    return (
        <StepGrid>
            <Cell span="full">
                <TextField
                    name="full_name"
                    label="Nombres y apellidos (obligatorio)"
                    value={data.full_name}
                    maxLength={150}
                    autoComplete="off"
                    error={errors.full_name}
                    onChange={(event) =>
                        setData('full_name', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <SelectField
                    name="document_type"
                    label="Tipo de documento"
                    options={options.document_types}
                    value={data.document_type}
                    clearLabel={CLEAR}
                    error={errors.document_type}
                    onValueChange={(value) => setData('document_type', value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="document_id"
                    label="Número de documento"
                    value={data.document_id}
                    maxLength={30}
                    autoComplete="off"
                    error={errors.document_id}
                    onChange={(event) =>
                        setData('document_id', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <SelectField
                    name="sex"
                    label="Sexo"
                    options={options.sexes}
                    value={data.sex}
                    clearLabel={CLEAR}
                    error={errors.sex}
                    onValueChange={(value) => setData('sex', value)}
                />
            </Cell>
            <Cell span="third">
                <DateField
                    name="birth_date"
                    label="Fecha de nacimiento"
                    value={data.birth_date}
                    max={todayIso()}
                    error={errors.birth_date}
                    onValueChange={(value) => setData('birth_date', value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="birth_place_city"
                    label="Ciudad de nacimiento"
                    value={data.birth_place_city}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.birth_place_city}
                    onChange={(event) =>
                        setData('birth_place_city', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="birth_place_state"
                    label="Estado o país de nacimiento"
                    value={data.birth_place_state}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.birth_place_state}
                    onChange={(event) =>
                        setData('birth_place_state', event.target.value)
                    }
                />
            </Cell>
        </StepGrid>
    );
}

export function ContactStep({ data, setData, errors }: MemberStepProps) {
    return (
        <StepGrid>
            <Cell span="third">
                <TextField
                    name="phone"
                    label="Teléfono celular"
                    type="tel"
                    inputMode="tel"
                    value={data.phone}
                    maxLength={30}
                    autoComplete="off"
                    error={errors.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="landline_phone"
                    label="Teléfono fijo"
                    type="tel"
                    inputMode="tel"
                    value={data.landline_phone}
                    maxLength={30}
                    autoComplete="off"
                    error={errors.landline_phone}
                    onChange={(event) =>
                        setData('landline_phone', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="email"
                    label="Correo electrónico"
                    type="email"
                    inputMode="email"
                    value={data.email}
                    maxLength={200}
                    autoComplete="off"
                    error={errors.email}
                    onChange={(event) => setData('email', event.target.value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="address_state"
                    label="Estado"
                    value={data.address_state}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.address_state}
                    onChange={(event) =>
                        setData('address_state', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="address_municipality"
                    label="Municipio"
                    value={data.address_municipality}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.address_municipality}
                    onChange={(event) =>
                        setData('address_municipality', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="address_parish"
                    label="Parroquia"
                    value={data.address_parish}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.address_parish}
                    onChange={(event) =>
                        setData('address_parish', event.target.value)
                    }
                />
            </Cell>
            <Cell span="full">
                <TextareaField
                    name="address"
                    label="Dirección"
                    rows={2}
                    value={data.address}
                    maxLength={400}
                    error={errors.address}
                    onChange={(event) => setData('address', event.target.value)}
                />
            </Cell>
        </StepGrid>
    );
}

export function FamilyStep({
    data,
    setData,
    errors,
    options,
    disabled,
    spouse,
    onSpouseChange,
    memberId,
}: MemberStepProps) {
    return (
        <StepGrid>
            <Cell span="half">
                <SelectField
                    name="marital_status"
                    label="Estado civil"
                    options={options.marital_statuses}
                    value={data.marital_status}
                    clearLabel={CLEAR}
                    error={errors.marital_status}
                    onValueChange={(value) => setData('marital_status', value)}
                />
            </Cell>
            <Cell span="half">
                <SpouseField
                    value={spouse}
                    onChange={onSpouseChange}
                    exceptId={memberId}
                    error={errors.spouse_id}
                    disabled={disabled}
                />
            </Cell>
            <Cell span="full">
                <ChildrenField
                    value={data.children}
                    onChange={(children) => setData('children', children)}
                    errors={errors}
                    disabled={disabled}
                />
            </Cell>
        </StepGrid>
    );
}

export function FaithStep({ data, setData, errors, options }: MemberStepProps) {
    return (
        <StepGrid>
            <Cell span="third">
                <SelectField
                    name="education_level"
                    label="Nivel educativo"
                    options={options.education_levels}
                    value={data.education_level}
                    clearLabel={CLEAR}
                    error={errors.education_level}
                    onValueChange={(value) => setData('education_level', value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="profession"
                    label="Profesión u oficio"
                    value={data.profession}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.profession}
                    onChange={(event) =>
                        setData('profession', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="occupation"
                    label="Ocupación actual"
                    value={data.occupation}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.occupation}
                    onChange={(event) =>
                        setData('occupation', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="baptism_place"
                    label="Lugar del bautismo"
                    value={data.baptism_place}
                    maxLength={200}
                    autoComplete="off"
                    error={errors.baptism_place}
                    onChange={(event) =>
                        setData('baptism_place', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <DateField
                    name="baptism_date"
                    label="Fecha de bautismo en agua"
                    value={data.baptism_date}
                    max={todayIso()}
                    error={errors.baptism_date}
                    onValueChange={(value) => setData('baptism_date', value)}
                />
            </Cell>
            <Cell span="third">
                <TextField
                    name="baptism_pastor"
                    label="Pastor que bautizó"
                    value={data.baptism_pastor}
                    maxLength={120}
                    autoComplete="off"
                    error={errors.baptism_pastor}
                    onChange={(event) =>
                        setData('baptism_pastor', event.target.value)
                    }
                />
            </Cell>
            <Cell span="third">
                <DateField
                    name="holy_spirit_date"
                    label="Recepción del Espíritu Santo"
                    value={data.holy_spirit_date}
                    max={todayIso()}
                    error={errors.holy_spirit_date}
                    onValueChange={(value) =>
                        setData('holy_spirit_date', value)
                    }
                />
            </Cell>
            <Cell span="third">
                <DateField
                    name="joined_date"
                    label="Ingreso a la congregación"
                    value={data.joined_date}
                    max={todayIso()}
                    error={errors.joined_date}
                    onValueChange={(value) => setData('joined_date', value)}
                />
            </Cell>
            <Cell span="third">
                <SelectField
                    name="status"
                    label="Estado del miembro"
                    options={options.statuses}
                    value={data.status}
                    clearLabel={CLEAR}
                    error={errors.status}
                    onValueChange={(value) => setData('status', value)}
                />
            </Cell>
        </StepGrid>
    );
}

export function ServiceStep({
    data,
    setData,
    errors,
    disabled,
}: MemberStepProps) {
    return (
        <StepGrid>
            <Cell span="full">
                <RolesField
                    value={data.roles}
                    onChange={(roles) => setData('roles', roles)}
                    errors={errors}
                    disabled={disabled}
                />
            </Cell>
            <Cell span="full">
                <TextareaField
                    name="pastor_notes"
                    label="Observaciones del pastor"
                    description="Opcional."
                    rows={3}
                    value={data.pastor_notes}
                    maxLength={2000}
                    error={errors.pastor_notes}
                    onChange={(event) =>
                        setData('pastor_notes', event.target.value)
                    }
                />
            </Cell>
        </StepGrid>
    );
}
