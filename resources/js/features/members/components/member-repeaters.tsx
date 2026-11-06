import { Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateField } from '@/components/form/date-field';
import { TextField } from '@/components/form/text-field';
import { Button } from '@/components/ui/button';
import type { MemberChild, MemberRole } from '@/features/members/types';
import { todayIso } from '@/lib/date';

type Errors = Record<string, string | undefined>;

/** Heading with the "add" button, and the list (or the empty message) below it. */
function Repeater({
    title,
    addLabel,
    empty,
    onAdd,
    disabled,
    children,
    isEmpty,
}: {
    title: string;
    addLabel: string;
    empty: string;
    onAdd: () => void;
    disabled?: boolean;
    isEmpty: boolean;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-lg font-bold">{title}</h3>
                {!disabled && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                    >
                        <Plus aria-hidden />
                        {addLabel}
                    </Button>
                )}
            </div>
            {isEmpty ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="flex flex-col gap-4 @xl:gap-2">{children}</ul>
            )}
        </div>
    );
}

/**
 * A row of a repeater. Under a container width of 36rem the fields stack as a card; above it they
 * sit on one row with the remove button at the end. min-w-0 everywhere: no horizontal overflow.
 */
function RepeaterRow({
    columns,
    removeLabel,
    onRemove,
    disabled,
    children,
}: {
    /** Grid columns on a wide row. */
    columns: string;
    removeLabel: string;
    onRemove: () => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <li className="@container min-w-0 rounded-2xl border-2 border-border p-4 @xl:border-0 @xl:p-0 @xl:not-first:[&_label]:sr-only">
            <div className={`grid min-w-0 grid-cols-1 gap-4 ${columns}`}>
                {children}
                {!disabled && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="justify-self-end text-destructive-foreground hover:bg-destructive-foreground/10 hover:text-destructive-foreground @xl:mb-1"
                        aria-label={removeLabel}
                        onClick={onRemove}
                    >
                        <Trash2 aria-hidden />
                    </Button>
                )}
            </div>
        </li>
    );
}

type ChildrenFieldProps = {
    value: MemberChild[];
    onChange: (value: MemberChild[]) => void;
    errors: Errors;
    disabled?: boolean;
};

export function ChildrenField({
    value,
    onChange,
    errors,
    disabled,
}: ChildrenFieldProps) {
    const change = (index: number, patch: Partial<MemberChild>) =>
        onChange(
            value.map((child, i) =>
                i === index ? { ...child, ...patch } : child,
            ),
        );

    return (
        <Repeater
            title="Hijos"
            addLabel="Agregar hijo"
            empty="Sin hijos registrados."
            onAdd={() => onChange([...value, { name: '', birth_date: '' }])}
            disabled={disabled}
            isEmpty={value.length === 0}
        >
            {value.map((child, index) => (
                <RepeaterRow
                    // The list has no ids and its inputs are controlled: the position identifies a row.
                    key={index}
                    columns="@xl:grid-cols-[minmax(0,1fr)_minmax(0,11rem)_auto] @xl:items-start"
                    removeLabel={`Quitar al hijo ${index + 1}`}
                    onRemove={() =>
                        onChange(value.filter((_, i) => i !== index))
                    }
                    disabled={disabled}
                >
                    <TextField
                        name={`children[${index}][name]`}
                        label="Nombre del hijo"
                        value={child.name}
                        maxLength={150}
                        autoComplete="off"
                        error={errors[`children.${index}.name`]}
                        onChange={(event) =>
                            change(index, { name: event.target.value })
                        }
                    />
                    <DateField
                        name={`children[${index}][birth_date]`}
                        label="Nacimiento"
                        value={child.birth_date}
                        max={todayIso()}
                        error={errors[`children.${index}.birth_date`]}
                        onValueChange={(birth_date) =>
                            change(index, { birth_date })
                        }
                    />
                </RepeaterRow>
            ))}
        </Repeater>
    );
}

type RolesFieldProps = {
    value: MemberRole[];
    onChange: (value: MemberRole[]) => void;
    errors: Errors;
    disabled?: boolean;
};

export function RolesField({
    value,
    onChange,
    errors,
    disabled,
}: RolesFieldProps) {
    const change = (index: number, patch: Partial<MemberRole>) =>
        onChange(
            value.map((role, i) =>
                i === index ? { ...role, ...patch } : role,
            ),
        );

    return (
        <Repeater
            title="Cargos y servicio"
            addLabel="Agregar cargo"
            empty="Sin cargos registrados."
            onAdd={() =>
                onChange([...value, { role: '', since: '', until: '' }])
            }
            disabled={disabled}
            isEmpty={value.length === 0}
        >
            {value.map((role, index) => (
                <RepeaterRow
                    key={index}
                    columns="@xl:grid-cols-[minmax(0,1fr)_minmax(0,10rem)_minmax(0,10rem)_auto] @xl:items-start"
                    removeLabel={`Quitar el cargo ${index + 1}`}
                    onRemove={() =>
                        onChange(value.filter((_, i) => i !== index))
                    }
                    disabled={disabled}
                >
                    <TextField
                        name={`roles[${index}][role]`}
                        label="Cargo"
                        value={role.role}
                        maxLength={120}
                        autoComplete="off"
                        error={errors[`roles.${index}.role`]}
                        onChange={(event) =>
                            change(index, { role: event.target.value })
                        }
                    />
                    <DateField
                        name={`roles[${index}][since]`}
                        label="Desde"
                        value={role.since}
                        error={errors[`roles.${index}.since`]}
                        onValueChange={(since) => change(index, { since })}
                    />
                    <DateField
                        name={`roles[${index}][until]`}
                        label="Hasta"
                        value={role.until}
                        min={role.since || undefined}
                        error={errors[`roles.${index}.until`]}
                        onValueChange={(until) => change(index, { until })}
                    />
                </RepeaterRow>
            ))}
        </Repeater>
    );
}
