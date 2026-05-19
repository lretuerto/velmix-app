function isValidDate(value: string): boolean {
    return !Number.isNaN(new Date(value).getTime());
}

export function formatNumber(value: number, options: Intl.NumberFormatOptions = {}): string {
    return new Intl.NumberFormat('es-PE', options).format(value);
}

export function formatCurrency(value: number, currency = 'PEN'): string {
    return new Intl.NumberFormat('es-PE', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

export function formatDate(value: string | null | undefined): string {
    if (value === null || value === undefined || value.trim() === '' || !isValidDate(value)) {
        return 'N/A';
    }

    return new Intl.DateTimeFormat('es-PE', {
        dateStyle: 'medium',
    }).format(new Date(value));
}

export function formatDateTime(value: string | null | undefined): string {
    if (value === null || value === undefined || value.trim() === '' || !isValidDate(value)) {
        return 'N/A';
    }

    return new Intl.DateTimeFormat('es-PE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
