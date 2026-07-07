import ApplicationLogo from '@/Components/ApplicationLogo';
import { assetUrl } from '@/Utils/assets';
import { Link, usePage } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    const { branding } = usePage().props;
    const logoSrc = assetUrl(branding?.logoPath);

    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900">
            <div>
                <Link href="/">
                    <span className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-3xl bg-brand-primary/10 text-brand-primary ring-1 ring-brand-primary/15">
                        {logoSrc ? (
                            <img src={logoSrc} alt="Logo del sistema" className="h-full w-full object-contain p-2" />
                        ) : (
                            <ApplicationLogo className="h-12 w-12 fill-current" />
                        )}
                    </span>
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800">
                {children}
            </div>
        </div>
    );
}
