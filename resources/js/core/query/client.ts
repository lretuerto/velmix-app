import { QueryClient } from '@tanstack/react-query';
import { ApiError, isRetryableApiError } from '@/core/api/errors';

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
                if (error instanceof ApiError) {
                    return failureCount < 1 && isRetryableApiError(error);
                }

                return failureCount < 1;
            },
        },
        mutations: {
            retry: false,
        },
    },
});
