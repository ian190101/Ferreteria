export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex min-h-10 items-center justify-center rounded-full border border-transparent bg-brand-primary px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25 transition duration-150 ease-in-out hover:bg-brand-primary/90 focus:bg-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 active:scale-[0.98] dark:focus:ring-offset-slate-950 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
