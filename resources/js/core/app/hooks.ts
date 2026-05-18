import { useContext } from 'react';
import { AppShellContext } from '@/core/app/shell-context';

export function useAppShell() {
    const context = useContext(AppShellContext);

    if (context === null) {
        throw new Error('useAppShell must be used inside AppShellProvider.');
    }

    return context;
}
