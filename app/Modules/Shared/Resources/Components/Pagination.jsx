import { Link } from '@inertiajs/react';

export default function Pagination({ links = [] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-2" aria-label="Paginacion">
            {links.map((link, index) => (
                <Link
                    key={`${link.label}-${index}`}
                    href={link.url ?? '#'}
                    preserveScroll
                    className={[
                        'min-w-10 rounded-full border px-3 py-2 text-center text-sm font-semibold shadow-sm backdrop-blur transition',
                        link.active
                            ? 'border-brand-primary bg-brand-primary text-white'
                            : 'border-slate-200/80 bg-white/75 text-slate-700 hover:border-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-200',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ].join(' ')}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}
