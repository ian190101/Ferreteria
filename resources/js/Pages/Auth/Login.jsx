import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Iniciar sesion" />

            <div className="mb-6">
                <h1 className="text-xl font-semibold text-slate-950 dark:text-white">
                    Iniciar sesion
                </h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Ingresa tus credenciales para acceder al sistema.
                </p>
            </div>

            {status ? (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            ) : null}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="Correo electronico" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        placeholder="usuario@empresa.com"
                        isFocused={true}
                        onChange={(event) => setData('email', event.target.value)}
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Contrasena" />

                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                        placeholder="Tu contrasena"
                        onChange={(event) => setData('password', event.target.value)}
                    />

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-5">
                    <label
                        htmlFor="remember"
                        className="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 px-3 py-3 transition hover:border-brand-primary/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                    >
                        <Checkbox
                            id="remember"
                            name="remember"
                            checked={data.remember}
                            className="shrink-0"
                            onChange={(event) => setData('remember', event.target.checked)}
                        />
                        <span className="select-none text-sm font-medium text-slate-700 dark:text-slate-200">
                            Recordarme en este equipo
                        </span>
                    </label>
                </div>

                <div className="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    {canResetPassword ? (
                        <Link
                            href={route('password.request')}
                            className="text-center text-sm font-medium text-slate-500 transition hover:text-brand-primary dark:text-slate-400 dark:hover:text-white"
                        >
                            Olvidaste tu contrasena?
                        </Link>
                    ) : null}

                    <PrimaryButton className="w-full sm:w-auto" disabled={processing}>
                        Iniciar sesion
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
