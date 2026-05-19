import { createContext } from 'react';
import type { AppBoot } from '@/core/app/boot';

export const AppShellContext = createContext<AppBoot | null>(null);
