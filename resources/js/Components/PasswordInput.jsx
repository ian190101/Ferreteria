import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';

export default forwardRef(function PasswordInput(
    { className = '', isFocused = false, value = '', ...props },
    ref,
) {
    const localRef = useRef(null);
    const [visible, setVisible] = useState(false);
    const hasValue = String(value ?? '').length > 0;

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    useEffect(() => {
        if (!hasValue) {
            setVisible(false);
        }
    }, [hasValue]);

    return (
        <div className="relative">
            <input
                {...props}
                ref={localRef}
                value={value}
                type={visible ? 'text' : 'password'}
                className={
                    'rounded-2xl border-slate-200 bg-white/80 shadow-sm backdrop-blur focus:border-brand-primary focus:ring-brand-primary read-only:cursor-not-allowed read-only:bg-slate-100 read-only:text-slate-500 dark:border-white/10 dark:bg-white/10 dark:text-slate-100 dark:read-only:bg-slate-800/70 dark:read-only:text-slate-400 ' +
                    (hasValue ? 'pr-12 ' : '') +
                    className
                }
            />
            {hasValue ? (
                <button
                    type="button"
                    aria-label={visible ? 'Ocultar contrasena' : 'Ver contrasena'}
                    className="absolute inset-y-1 right-1 inline-flex w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
                    onClick={() => setVisible((current) => !current)}
                >
                    <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        {visible ? (
                            <>
                                <path d="M3 3l18 18" />
                                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                                <path d="M7.1 7.5C4.9 8.8 3.4 10.6 2.5 12c2.2 3.6 5.4 5.5 9.5 5.5 1.5 0 2.9-.3 4.1-.8" />
                                <path d="M14.1 6.8c3.2.6 5.7 2.4 7.4 5.2a13.5 13.5 0 0 1-2.4 2.9" />
                            </>
                        ) : (
                            <>
                                <path d="M2.5 12c2.2-3.6 5.4-5.5 9.5-5.5s7.3 1.9 9.5 5.5c-2.2 3.6-5.4 5.5-9.5 5.5S4.7 15.6 2.5 12Z" />
                                <path d="M12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" />
                            </>
                        )}
                    </svg>
                </button>
            ) : null}
        </div>
    );
});
