import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Bienvenido" />
            <main className="flex min-h-dvh flex-col items-center justify-center gap-10 bg-sidebar px-6 py-16 text-center text-sidebar-foreground">
                <AppLogoIcon className="size-16 text-sidebar-primary" />
                <div className="flex max-w-2xl flex-col items-center gap-4">
                    <p className="eyebrow">Gestión de la iglesia</p>
                    <h1 className="heading-hero">{name}</h1>
                    <p className="text-lg text-sidebar-foreground/80">
                        Ingrese para administrar el registro de miembros.
                    </p>
                </div>
                <Button asChild size="lg">
                    <Link href={auth.user ? dashboard() : login()}>
                        {auth.user ? 'Ir al panel' : 'Iniciar sesión'}
                    </Link>
                </Button>
            </main>
        </>
    );
}
