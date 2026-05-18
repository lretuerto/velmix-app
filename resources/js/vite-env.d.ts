/// <reference types="vite/client" />

import type { AxiosStatic } from 'axios';
import type { AppBoot } from '@/core/app/boot';

declare global {
    interface Window {
        __VELMIX_BOOT__?: AppBoot;
        axios: AxiosStatic;
    }
}

export {};
