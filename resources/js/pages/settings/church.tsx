import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    edit,
    update,
} from '@/actions/App/Modules/Church/Http/Controllers/ChurchSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Field } from '@/components/form/field';
import { TextareaField } from '@/components/form/textarea-field';
import { TextField } from '@/components/form/text-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type BrandingAsset = {
    url: string;
    mime: string;
    size: number;
    sha256: string;
};

type ChurchSettings = {
    name: string;
    pastor: string;
    code: string;
    rif: string;
    denomination: string;
    headquarters: string;
    registration_note: string;
    denomination_rif: string;
    logo_light: BrandingAsset | null;
    logo_dark: BrandingAsset | null;
    favicon: BrandingAsset | null;
};

type Props = {
    settings: ChurchSettings | null;
};

type BrandingFieldProps = {
    name: 'logo_light' | 'logo_dark' | 'favicon';
    label: string;
    description: string;
    asset: BrandingAsset | null;
};

function BrandingField({
    name,
    label,
    description,
    asset,
}: BrandingFieldProps) {
    const [preview, setPreview] = useState<string | null>(asset?.url ?? null);

    return (
        <Field label={label} description={description}>
            {(control) => (
                <div className="flex min-w-0 flex-col gap-3">
                    <Input
                        {...control}
                        name={name}
                        type="file"
                        accept={
                            name === 'favicon'
                                ? '.png,.ico,image/png,image/x-icon'
                                : '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'
                        }
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            setPreview(
                                file
                                    ? URL.createObjectURL(file)
                                    : (asset?.url ?? null),
                            );
                        }}
                    />
                    {preview && (
                        <img
                            src={preview}
                            alt={`Vista previa de ${label.toLocaleLowerCase('es')}`}
                            className="max-h-24 max-w-full self-start rounded-md border border-border bg-muted object-contain p-2"
                        />
                    )}
                </div>
            )}
        </Field>
    );
}

export default function Church({ settings }: Props) {
    return (
        <>
            <Head title="Iglesia" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Iglesia"
                    description="Administre la identidad institucional y los archivos de marca."
                />

                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                    encType="multipart/form-data"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid min-w-0 gap-4 md:grid-cols-2">
                                <TextField
                                    name="name"
                                    label="Nombre de la iglesia"
                                    defaultValue={settings?.name ?? ''}
                                    error={errors.name}
                                    required
                                />
                                <TextField
                                    name="pastor"
                                    label="Pastor"
                                    defaultValue={settings?.pastor ?? ''}
                                    error={errors.pastor}
                                    required
                                />
                                <TextField
                                    name="code"
                                    label="Código"
                                    defaultValue={settings?.code ?? ''}
                                    error={errors.code}
                                    required
                                />
                                <TextField
                                    name="rif"
                                    label="RIF"
                                    defaultValue={settings?.rif ?? ''}
                                    error={errors.rif}
                                    required
                                />
                                <TextField
                                    name="denomination"
                                    label="Denominación"
                                    defaultValue={settings?.denomination ?? ''}
                                    error={errors.denomination}
                                    required
                                />
                                <TextField
                                    name="headquarters"
                                    label="Sede nacional"
                                    defaultValue={settings?.headquarters ?? ''}
                                    error={errors.headquarters}
                                    required
                                />
                                <TextField
                                    name="denomination_rif"
                                    label="RIF de la denominación"
                                    defaultValue={
                                        settings?.denomination_rif ?? ''
                                    }
                                    error={errors.denomination_rif}
                                    required
                                />
                                <TextareaField
                                    name="registration_note"
                                    label="Nota de registro"
                                    defaultValue={
                                        settings?.registration_note ?? ''
                                    }
                                    error={errors.registration_note}
                                    required
                                />
                            </div>

                            <div className="grid min-w-0 gap-5 border-t border-border pt-6">
                                <BrandingField
                                    name="logo_light"
                                    label="Logo claro"
                                    description="JPG, PNG o WebP de hasta 4 MB. SVG no se acepta por seguridad."
                                    asset={settings?.logo_light ?? null}
                                />
                                <BrandingField
                                    name="logo_dark"
                                    label="Logo oscuro"
                                    description="JPG, PNG o WebP de hasta 4 MB. SVG no se acepta por seguridad."
                                    asset={settings?.logo_dark ?? null}
                                />
                                <BrandingField
                                    name="favicon"
                                    label="Favicon"
                                    description="PNG o ICO de hasta 1 MB."
                                    asset={settings?.favicon ?? null}
                                />
                                <InputError
                                    message={
                                        errors.logo_light ??
                                        errors.logo_dark ??
                                        errors.favicon
                                    }
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>
                                    Guardar cambios
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Church.layout = {
    breadcrumbs: [{ title: 'Iglesia', href: edit() }],
};
