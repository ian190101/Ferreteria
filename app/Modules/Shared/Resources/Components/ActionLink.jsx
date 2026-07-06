import { Link } from '@inertiajs/react';

export default function ActionLink({ href, children }) {
    return (
        <Link
            href={href}
            className="inline-flex min-h-10 items-center justify-center rounded-full bg-brand-primary px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25 transition hover:bg-brand-primary/90 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 active:scale-[0.98] dark:focus:ring-offset-slate-950"
        >
            {children}
        </Link>
    );
}
