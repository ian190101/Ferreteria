import ApplicationLogo from '@/Components/ApplicationLogo';
import { assetUrl } from '@/Utils/assets';
import { applyBranding } from '@/Utils/branding';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function GuestLayout({ children }) {
    const { branding } = usePage().props;

    useEffect(() => {
        applyBranding(branding);
    }, [branding?.primary, branding?.primaryRgb, branding?.secondary, branding?.secondaryRgb, branding?.logoPath, branding?.themeMode]);

    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900">
            <div>
                <Link href="/">
                    <GuestLogo logoPath={branding?.logoPath} />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800">
                {children}
            </div>
        </div>
    );
}

function GuestLogo({ logoPath }) {
    const [failed, setFailed] = useState(false);
    const logoSrc = assetUrl(logoPath);

    return (
        <span className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-3xl bg-brand-primary/10 text-brand-primary ring-1 ring-brand-primary/15">
            {logoSrc && !failed ? (
                <img src={logoSrc} alt="" className="h-full w-full object-contain p-2" onError={() => setFailed(true)} />
            ) : (
                <ApplicationLogo className="h-12 w-12 fill-current" />
            )}
        </span>
    );
}
