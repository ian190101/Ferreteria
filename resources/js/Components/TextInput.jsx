import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'rounded-2xl border-slate-200 bg-white/80 shadow-sm backdrop-blur focus:border-brand-primary focus:ring-brand-primary read-only:cursor-not-allowed read-only:bg-slate-100 read-only:text-slate-500 dark:border-white/10 dark:bg-white/10 dark:text-slate-100 dark:read-only:bg-slate-800/70 dark:read-only:text-slate-400 ' +
                className
            }
            ref={localRef}
        />
    );
});
