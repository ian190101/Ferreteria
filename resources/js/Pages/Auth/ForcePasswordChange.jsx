import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import PasswordMatchHint from '@/Components/PasswordMatchHint';
import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function ForcePasswordChange() {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();

        put(route('password.force.update'), {
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Cambiar contrasena" />

            <div className="mb-6">
                <h1 className="text-xl font-semibold text-slate-950 dark:text-white">
                    Cambia tu contrasena
                </h1>
                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                    Tu cuenta tiene una contrasena temporal asignada por el
                    administrador. Debes crear una contrasena nueva para continuar.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="current_password" value="Contrasena actual" />
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        value={data.current_password}
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                        isFocused={true}
                        onChange={(event) => setData('current_password', event.target.value)}
                    />
                    <InputError message={errors.current_password} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Nueva contrasena" />
                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(event) => setData('password', event.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirmar nueva contrasena" />
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                    <PasswordMatchHint password={data.password} confirmation={data.password_confirmation} />
                </div>

                <div className="flex justify-end pt-2">
                    <PrimaryButton disabled={processing}>
                        Guardar y continuar
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
