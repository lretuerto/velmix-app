import {
    useEffect,
    useRef,
    useState,
    type PropsWithChildren,
} from 'react';
import { cn } from '@/shared/utils/cn';
import {
    ToastContext,
    type ToastContextValue,
    type ToastInput,
    type ToastTone,
} from '@/core/ui/feedback/toast';

interface Toast {
    id: number;
    title: string;
    description: string;
    tone: ToastTone;
    durationMs: number;
}

const toneClassMap: Record<ToastTone, string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-950',
    warning: 'border-amber-200 bg-amber-50 text-amber-950',
    danger: 'border-rose-200 bg-rose-50 text-rose-950',
    info: 'border-sky-200 bg-sky-50 text-sky-950',
};

export function ToastProvider({ children }: PropsWithChildren) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const nextIdRef = useRef(1);
    const timeoutMapRef = useRef<Map<number, number>>(new Map());

    const dismiss = (id: number) => {
        const timeoutId = timeoutMapRef.current.get(id);

        if (timeoutId !== undefined) {
            window.clearTimeout(timeoutId);
            timeoutMapRef.current.delete(id);
        }

        setToasts((current) => current.filter((toast) => toast.id !== id));
    };

    const push = (toast: ToastInput) => {
        const id = nextIdRef.current++;
        const normalizedToast: Toast = {
            id,
            title: toast.title,
            description: toast.description,
            tone: toast.tone ?? 'info',
            durationMs: toast.durationMs ?? 4200,
        };

        setToasts((current) => [...current, normalizedToast]);

        const timeoutId = window.setTimeout(() => {
            dismiss(id);
        }, normalizedToast.durationMs);

        timeoutMapRef.current.set(id, timeoutId);

        return id;
    };

    useEffect(() => {
        const timeoutMap = timeoutMapRef.current;

        return () => {
            timeoutMap.forEach((timeoutId) => {
                window.clearTimeout(timeoutId);
            });
            timeoutMap.clear();
        };
    }, []);

    const value: ToastContextValue = {
        push,
        dismiss,
        success: (toast) => push({ ...toast, tone: 'success' }),
        warning: (toast) => push({ ...toast, tone: 'warning' }),
        danger: (toast) => push({ ...toast, tone: 'danger' }),
        info: (toast) => push({ ...toast, tone: 'info' }),
    };

    return (
        <ToastContext.Provider value={value}>
            {children}
            <ToastViewport toasts={toasts} onDismiss={dismiss} />
        </ToastContext.Provider>
    );
}

interface ToastViewportProps {
    toasts: Toast[];
    onDismiss: (id: number) => void;
}

function ToastViewport({ toasts, onDismiss }: ToastViewportProps) {
    return (
        <div className="pointer-events-none fixed right-4 top-4 z-50 flex w-full max-w-sm flex-col gap-3">
            {toasts.map((toast) => (
                <article
                    key={toast.id}
                    className={cn(
                        'pointer-events-auto rounded-[var(--velmix-radius-lg)] border px-4 py-4 shadow-[var(--velmix-shadow)] backdrop-blur',
                        toneClassMap[toast.tone],
                    )}
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-semibold">{toast.title}</p>
                            <p className="mt-1 text-sm leading-6 text-current/80">{toast.description}</p>
                        </div>
                        <button
                            type="button"
                            className="rounded-full border border-current/15 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-current/70 transition hover:text-current"
                            onClick={() => onDismiss(toast.id)}
                        >
                            cerrar
                        </button>
                    </div>
                </article>
            ))}
        </div>
    );
}
