import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    return (
        <GuestLayout>
            <Head title="Recuperar acceso" />

            <div className="mb-6">
                <h1 className="text-xl font-semibold text-slate-950 dark:text-white">
                    Recuperar acceso
                </h1>
                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                    Por seguridad, el restablecimiento de contrasena lo realiza
                    un administrador del sistema. Contacta al administrador para
                    que verifique tu identidad y te asigne una contrasena temporal.
                </p>
            </div>

            {status ? (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    {status}
                </div>
            ) : null}

            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">
                Al iniciar sesion con la contrasena temporal, el sistema te
                obligara a crear una contrasena nueva.
            </div>

            <div className="mt-5 flex justify-end">
                <Link href={route('login')} className="rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25">
                    Volver al login
                </Link>
            </div>
        </GuestLayout>
    );
}
