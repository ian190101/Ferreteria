import { Link } from '@inertiajs/react';
import Icon from './Icon';

const tones = {
    default: 'border-slate-200/80 bg-white/80 text-slate-600 hover:border-brand-primary/40 hover:bg-brand-primary/10 hover:text-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-300',
    danger: 'border-red-200/80 bg-red-50/90 text-red-600 hover:border-red-300 hover:bg-red-100 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300',
    success: 'border-emerald-200/80 bg-emerald-50/90 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
};

export default function IconButton({ icon, label, tone = 'default', href, method, as, className = '', ...props }) {
    const classes = `group relative inline-flex h-9 w-9 items-center justify-center rounded-full border shadow-sm backdrop-blur transition active:scale-95 disabled:pointer-events-none disabled:opacity-40 ${tones[tone] ?? tones.default} ${className}`;
    const content = (
        <>
            <Icon name={icon} />
            <span className="pointer-events-none absolute -top-9 left-1/2 z-20 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-950 px-2 py-1 text-[11px] font-medium text-white opacity-0 shadow-lg transition group-hover:opacity-100 group-focus-visible:opacity-100 dark:bg-white dark:text-slate-950">
                {label}
            </span>
            <span className="sr-only">{label}</span>
        </>
    );

    if (href) {
        return (
            <Link href={href} method={method} as={as} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" aria-label={label} title={label} className={classes} {...props}>
            {content}
        </button>
    );
}
