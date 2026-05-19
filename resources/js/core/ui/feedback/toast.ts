import { createContext, useContext } from 'react';

export type ToastTone = 'success' | 'warning' | 'danger' | 'info';

export interface ToastInput {
    title: string;
    description: string;
    tone?: ToastTone;
    durationMs?: number;
}

export interface ToastContextValue {
    push: (toast: ToastInput) => number;
    dismiss: (id: number) => void;
    success: (toast: Omit<ToastInput, 'tone'>) => number;
    warning: (toast: Omit<ToastInput, 'tone'>) => number;
    danger: (toast: Omit<ToastInput, 'tone'>) => number;
    info: (toast: Omit<ToastInput, 'tone'>) => number;
}

export const ToastContext = createContext<ToastContextValue | null>(null);

export function useToast() {
    const context = useContext(ToastContext);

    if (context === null) {
        throw new Error('useToast must be used inside ToastProvider.');
    }

    return context;
}
