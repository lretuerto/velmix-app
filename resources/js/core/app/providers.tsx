import type { PropsWithChildren } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { AppShellProvider } from '@/core/app/context';
import { queryClient } from '@/core/query/client';
import { ToastProvider } from '@/core/ui/feedback/ToastProvider';

export function AppProviders({ children }: PropsWithChildren) {
    return (
        <AppShellProvider>
            <QueryClientProvider client={queryClient}>
                <ToastProvider>{children}</ToastProvider>
            </QueryClientProvider>
        </AppShellProvider>
    );
}
