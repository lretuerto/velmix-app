import { StrictMode } from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import './bootstrap';
import { AppProviders } from '@/core/app/providers';
import { AppRouter } from '@/core/router';
import { AppErrorBoundary } from '@/core/ui/feedback/AppErrorBoundary';

const container = document.getElementById('app');

if (container === null) {
    throw new Error('The frontend root element "#app" was not found.');
}

ReactDOM.createRoot(container).render(
    <StrictMode>
        <BrowserRouter basename="/app">
            <AppProviders>
                <AppErrorBoundary>
                    <AppRouter />
                </AppErrorBoundary>
            </AppProviders>
        </BrowserRouter>
    </StrictMode>,
);
