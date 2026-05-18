import { Component, type ErrorInfo, type ReactNode } from 'react';
import { StatePanel } from '@/core/ui/feedback/StatePanel';

interface AppErrorBoundaryProps {
    children: ReactNode;
}

interface AppErrorBoundaryState {
    hasError: boolean;
    message: string;
}

export class AppErrorBoundary extends Component<AppErrorBoundaryProps, AppErrorBoundaryState> {
    public override state: AppErrorBoundaryState = {
        hasError: false,
        message: '',
    };

    public static getDerivedStateFromError(error: Error): AppErrorBoundaryState {
        return {
            hasError: true,
            message: error.message,
        };
    }

    public override componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
        console.error('Unhandled frontend error', error, errorInfo);
    }

    public override render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="mx-auto flex min-h-screen max-w-3xl items-center justify-center px-6 py-12">
                <StatePanel
                    tone="danger"
                    title="El frontend encontro un error inesperado"
                    description={`${this.state.message} Puedes recargar el workspace para reconstruir el shell actual.`}
                    actions={
                        <div className="flex flex-wrap gap-3">
                            <button
                                type="button"
                                className="rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                                onClick={() => window.location.reload()}
                            >
                                Recargar frontend
                            </button>
                            <button
                                type="button"
                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                                onClick={() => {
                                    window.location.assign('/app');
                                }}
                            >
                                Volver al workspace
                            </button>
                        </div>
                    }
                />
            </div>
        );
    }
}
