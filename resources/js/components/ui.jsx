import { useEffect } from 'react';

export const inputClassName = 'mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-base text-slate-900 dark:text-slate-100 outline-none sm:text-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-400 focus:border-prosof-dark focus:ring-2 focus:ring-prosof-lime/40';
export const primaryButton = 'inline-flex items-center justify-center gap-2 rounded-lg bg-prosof-action px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-prosof-action-hover disabled:cursor-not-allowed disabled:opacity-60';
export const dangerButton = 'inline-flex items-center justify-center gap-2 rounded-lg border border-red-600 dark:border-red-800 bg-red-50 dark:bg-slate-900 px-3 py-1.5 text-xs font-semibold text-red-800 dark:text-red-300 transition hover:bg-red-100 dark:hover:bg-red-950/70 disabled:opacity-60';
export const secondaryButton = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-500 dark:border-slate-600 bg-slate-100 dark:bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-800 dark:text-slate-200 transition hover:bg-slate-200 dark:hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60';

const icons = {
    sun: 'M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    moon: 'M20.9 13.35A9 9 0 0 1 10.65 3.1a9 9 0 1 0 10.25 10.25Z',
    home: 'M3 12 12 3l9 9M5 10v10h5v-6h4v6h5V10',
    rocket: 'M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2M12 15l-3-3a22 22 0 0 1 2-4 12 12 0 0 1 11-5c0 3-1 8-5 11a22 22 0 0 1-4 2zM9 12H4s.6-3 2-4c1.6-1 5 0 5 0M12 15v5s3-.6 4-2c1-1.6 0-5 0-5',
    folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    server: 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01',
    plus: 'M12 5v14M5 12h14',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    close: 'M6 6l12 12M18 6 6 18',
    check: 'm5 13 4 4L19 7',
    alert: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    menu: 'M4 6h16M4 12h16M4 18h16',
    collapse: 'm11 17-5-5 5-5M18 17l-5-5 5-5',
    expand: 'm13 17 5-5-5-5M6 17l5-5-5-5',
    trash: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6',
    power: 'M12 3v9M6.4 6.4a8 8 0 1 0 11.2 0',
    key: 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4',
    copy: 'M9 9h11v11H9zM5 15H4V4h11v1',
    link: 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
};

export function Icon({ className = 'size-5', name }) {
    return (
        <svg aria-hidden="true" className={className} fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" viewBox="0 0 24 24">
            <path d={icons[name]} />
        </svg>
    );
}

export function Field({ children, error, label }) {
    return (
        <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">
            <span>{label}</span>
            {children}
            {error && <span className="mt-1 block text-sm text-red-700 dark:text-red-300">{error}</span>}
        </label>
    );
}

export function Hint({ children }) {
    return <span className="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">{children}</span>;
}

export function Card({ action, children, title }) {
    return (
        <section className="overflow-hidden rounded-2xl bg-white/90 shadow-sm ring-1 ring-green-900/10 dark:bg-[#17251a] dark:ring-white/15">
            {(title || action) && (
                <header className="flex items-center justify-between gap-3 border-b border-green-900/10 px-5 py-4 dark:border-white/10">
                    <h2 className="text-base font-bold text-neutral-800 dark:text-slate-100">{title}</h2>
                    {action}
                </header>
            )}
            {children}
        </section>
    );
}

export function EmptyState({ children }) {
    return <p className="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{children}</p>;
}

const badgeStyles = {
    success: 'bg-prosof-lime/20 text-prosof-ink ring-prosof-dark/30 dark:text-prosof-lime',
    info: 'bg-sky-50 dark:bg-sky-950/60 text-sky-800 dark:text-sky-200 ring-sky-200 dark:ring-sky-800',
    warning: 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 ring-amber-200 dark:ring-amber-800',
    danger: 'bg-red-50 dark:bg-red-950/60 text-red-800 dark:text-red-200 ring-red-200 dark:ring-red-800',
    neutral: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 ring-slate-200 dark:ring-slate-700',
};

export function Badge({ children, tone = 'neutral' }) {
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${badgeStyles[tone]}`}>{children}</span>;
}

export const targetStatuses = {
    pending: { label: 'Pendiente', tone: 'warning' },
    running: { label: 'En ejecución', tone: 'info' },
    succeeded: { label: 'Exitoso', tone: 'success' },
    failed: { label: 'Fallido', tone: 'danger' },
    uncertain: { label: 'Incierto', tone: 'danger' },
    cancelled: { label: 'Cancelado', tone: 'neutral' },
};

export function StatusBadge({ status }) {
    const { label, tone } = targetStatuses[status] ?? { label: status, tone: 'neutral' };

    return <Badge tone={tone}>{label}</Badge>;
}

let openModalCount = 0;
let originalBodyOverflow = '';

export function Modal({ children, onClose, title }) {
    useEffect(() => {
        if (openModalCount === 0) {
            originalBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
        openModalCount += 1;

        return () => {
            openModalCount -= 1;
            if (openModalCount === 0) {
                document.body.style.overflow = originalBodyOverflow;
            }
        };
    }, []);

    useEffect(() => {
        const onKey = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div aria-modal="true" className="fixed inset-0 z-50 flex items-start justify-center bg-neutral-900/50 p-2 sm:p-4 sm:py-12" role="dialog">
            <div className="flex max-h-[calc(100dvh-1rem)] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-xl sm:max-h-[calc(100dvh-6rem)] dark:bg-slate-900">
                <header className="flex shrink-0 items-center justify-between border-b border-slate-100 px-4 py-4 sm:px-6 dark:border-slate-800">
                    <h2 className="font-display text-lg font-bold text-neutral-800 dark:text-slate-100">{title}</h2>
                    <button aria-label="Cerrar" className="rounded-lg p-1.5 text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800" onClick={onClose} type="button">
                        <Icon name="close" />
                    </button>
                </header>
                <div className="min-h-0 overflow-y-auto overscroll-contain scroll-pb-24 px-4 py-5 sm:px-6">{children}</div>
            </div>
        </div>
    );
}
