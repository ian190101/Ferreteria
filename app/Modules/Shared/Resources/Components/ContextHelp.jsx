import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const PANEL_WIDTH = 288;
const VIEWPORT_MARGIN = 12;

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

export default function ContextHelp({ title = 'Ayuda', children }) {
    const [isOpen, setIsOpen] = useState(false);
    const [position, setPosition] = useState({
        left: VIEWPORT_MARGIN,
        top: VIEWPORT_MARGIN,
        width: PANEL_WIDTH,
    });
    const buttonRef = useRef(null);
    const panelRef = useRef(null);

    const updatePosition = () => {
        const button = buttonRef.current;

        if (!button || typeof window === 'undefined') {
            return;
        }

        const rect = button.getBoundingClientRect();
        const width = Math.min(PANEL_WIDTH, window.innerWidth - VIEWPORT_MARGIN * 2);
        const panelHeight = panelRef.current?.offsetHeight ?? 180;
        const maxLeft = window.innerWidth - width - VIEWPORT_MARGIN;
        const maxTop = window.innerHeight - panelHeight - VIEWPORT_MARGIN;
        const left = clamp(rect.right - width, VIEWPORT_MARGIN, Math.max(VIEWPORT_MARGIN, maxLeft));
        let top = rect.bottom + 8;

        if (top + panelHeight > window.innerHeight - VIEWPORT_MARGIN) {
            top = rect.top - panelHeight - 8;
        }

        setPosition({
            left,
            top: clamp(top, VIEWPORT_MARGIN, Math.max(VIEWPORT_MARGIN, maxTop)),
            width,
        });
    };

    useEffect(() => {
        if (!isOpen) {
            return undefined;
        }

        updatePosition();

        const handlePointerDown = (event) => {
            if (buttonRef.current?.contains(event.target) || panelRef.current?.contains(event.target)) {
                return;
            }

            setIsOpen(false);
        };
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [isOpen]);

    if (!children) {
        return null;
    }

    const panel = (
        <div
            ref={panelRef}
            className="fixed z-[9999] max-h-[min(70vh,22rem)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 text-xs leading-relaxed text-slate-600 shadow-xl dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300"
            style={{
                left: position.left,
                top: position.top,
                width: position.width,
            }}
            role="dialog"
            aria-label={title}
        >
            <p className="mb-1 font-semibold text-slate-900 dark:text-white">{title}</p>
            <div>{children}</div>
        </div>
    );

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                className="inline-flex h-5 w-5 items-center justify-center rounded-full border border-brand-primary/40 text-[11px] font-bold text-brand-primary transition hover:bg-brand-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-brand-primary/30"
                title={title}
                aria-label={title}
                aria-expanded={isOpen}
                onClick={() => setIsOpen((current) => !current)}
            >
                ?
            </button>
            {isOpen && typeof document !== 'undefined' ? createPortal(panel, document.body) : null}
        </>
    );
}
