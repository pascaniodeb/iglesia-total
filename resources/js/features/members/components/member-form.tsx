import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    ContactStep,
    FaithStep,
    FamilyStep,
    IdentificationStep,
    ServiceStep,
} from '@/features/members/components/member-form-steps';
import type { MemberStepProps } from '@/features/members/components/member-form-steps';
import { MemberStepNav } from '@/features/members/components/member-step-nav';
import type {
    MemberDetail,
    MemberFormData,
    MemberOptions,
} from '@/features/members/types';
import { index, store, update } from '@/routes/members';

type Step = {
    label: string;
    /** Form fields of the section (a repeater matches its `children.0.name` style errors too). */
    fields: string[];
    Component: (props: MemberStepProps) => React.JSX.Element;
};

const STEPS: Step[] = [
    {
        label: 'Identificación',
        fields: [
            'full_name',
            'document_type',
            'document_id',
            'sex',
            'birth_date',
            'birth_place_city',
            'birth_place_state',
        ],
        Component: IdentificationStep,
    },
    {
        label: 'Contacto y domicilio',
        fields: [
            'phone',
            'landline_phone',
            'email',
            'address_state',
            'address_municipality',
            'address_parish',
            'address',
        ],
        Component: ContactStep,
    },
    {
        label: 'Familia',
        fields: ['marital_status', 'spouse_id', 'children'],
        Component: FamilyStep,
    },
    {
        label: 'Fe y formación',
        fields: [
            'education_level',
            'profession',
            'occupation',
            'baptism_place',
            'baptism_date',
            'baptism_pastor',
            'holy_spirit_date',
            'joined_date',
            'status',
        ],
        Component: FaithStep,
    },
    {
        label: 'Servicio y notas',
        fields: ['roles', 'pastor_notes'],
        Component: ServiceStep,
    },
];

/** Whether any of the errors belongs to the fields of the section. */
function sectionHasErrors(
    step: Step,
    errors: Record<string, unknown>,
): boolean {
    return Object.keys(errors).some((key) =>
        step.fields.some(
            (field) => key === field || key.startsWith(`${field}.`),
        ),
    );
}

/** The form state from a member's card (null becomes ''), or an empty card to register. */
function initialData(member?: MemberDetail): MemberFormData {
    return {
        full_name: member?.full_name ?? '',
        document_type: member?.document_type ?? '',
        document_id: member?.document_id ?? '',
        sex: member?.sex ?? '',
        birth_date: member?.birth_date ?? '',
        birth_place_city: member?.birth_place_city ?? '',
        birth_place_state: member?.birth_place_state ?? '',
        phone: member?.phone ?? '',
        landline_phone: member?.landline_phone ?? '',
        email: member?.email ?? '',
        address_state: member?.address_state ?? '',
        address_municipality: member?.address_municipality ?? '',
        address_parish: member?.address_parish ?? '',
        address: member?.address ?? '',
        education_level: member?.education_level ?? '',
        profession: member?.profession ?? '',
        occupation: member?.occupation ?? '',
        marital_status: member?.marital_status ?? '',
        spouse_id: member?.spouse?.id ?? '',
        baptism_date: member?.baptism_date ?? '',
        baptism_place: member?.baptism_place ?? '',
        baptism_pastor: member?.baptism_pastor ?? '',
        holy_spirit_date: member?.holy_spirit_date ?? '',
        joined_date: member?.joined_date ?? '',
        status: member?.status ?? '',
        pastor_notes: member?.pastor_notes ?? '',
        children: (member?.children ?? []).map((child) => ({
            name: child.name,
            birth_date: child.birth_date ?? '',
        })),
        roles: (member?.roles ?? []).map((role) => ({
            role: role.role,
            since: role.since ?? '',
            until: role.until ?? '',
        })),
    };
}

type MemberFormProps = {
    /** The member being edited; absent when registering. */
    member?: MemberDetail;
    options: MemberOptions;
    readOnly?: boolean;
    /** Why the card cannot be edited (shown above it). */
    notice?: string;
};

/**
 * The registry card, by sections. One section at a time keeps every screen short: on a desktop
 * the page is exactly as tall as the window (see data-fit-* in app.css) and only the section
 * scrolls, if it ever does; on a phone the fields stack in one column and nothing overflows
 * sideways. The actions stay in view, outside the scrolling area.
 */
export function MemberForm({
    member,
    options,
    readOnly = false,
    notice,
}: MemberFormProps) {
    const form = useForm<MemberFormData>(initialData(member));
    const [step, setStep] = useState(0);
    const [spouse, setSpouse] = useState(member?.spouse ?? null);
    const errors: Record<string, string | undefined> = form.errors;
    const current = STEPS[step] ?? STEPS[0];
    const last = step === STEPS.length - 1;

    if (!current) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (readOnly) {
            return;
        }

        form.submit(member ? update(member.id) : store(), {
            preserveScroll: true,
            onError: (serverErrors) => {
                const first = STEPS.findIndex((candidate) =>
                    sectionHasErrors(candidate, serverErrors),
                );

                if (first >= 0) {
                    setStep(first);
                }
            },
        });
    };

    const stepProps: MemberStepProps = {
        data: form.data,
        setData: form.setData,
        errors,
        options,
        disabled: readOnly,
        spouse,
        memberId: member?.id,
        onSpouseChange: (next) => {
            setSpouse(next);
            form.setData('spouse_id', next?.id ?? '');
        },
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            // data-fit-viewport: on tablet and desktop the form is as tall as the window.
            data-fit-viewport
            className="flex min-w-0 flex-col gap-4"
        >
            <MemberStepNav
                steps={STEPS.map((candidate) => ({
                    label: candidate.label,
                    hasErrors: sectionHasErrors(candidate, errors),
                }))}
                current={step}
                onSelect={setStep}
            />

            {notice && (
                <p
                    role="status"
                    className="rounded-2xl border-2 border-border bg-muted px-5 py-3 text-sm font-medium"
                >
                    {notice}
                </p>
            )}

            <section
                data-fit-scroll
                className="relative overflow-clip rounded-3xl border-2 border-border bg-card"
            >
                <div data-fit-body className="min-w-0 p-5 md:px-6 md:py-3">
                    <h2 className="mb-5 text-2xl font-black tracking-tight md:sr-only">
                        {current.label}
                    </h2>
                    {/* min-w-0 and no border: a fieldset defaults to min-content width and would overflow. */}
                    <fieldset
                        disabled={readOnly}
                        className="m-0 min-w-0 border-0 p-0"
                    >
                        <current.Component {...stepProps} />
                    </fieldset>
                </div>
            </section>

            <footer className="@container flex flex-col-reverse gap-3 @2xl:flex-row @2xl:items-center @2xl:justify-between">
                <Button
                    asChild
                    variant="ghost"
                    className="sm:justify-self-start"
                >
                    <Link href={index()}>Volver al listado</Link>
                </Button>
                <div className="flex flex-col-reverse gap-3 @lg:flex-row @lg:items-center">
                    {step > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep(step - 1)}
                        >
                            Anterior
                        </Button>
                    )}
                    {!last && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep(step + 1)}
                        >
                            Siguiente
                        </Button>
                    )}
                    {!readOnly && (
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {member ? 'Guardar cambios' : 'Registrar miembro'}
                        </Button>
                    )}
                </div>
            </footer>
        </form>
    );
}
