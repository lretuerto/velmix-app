import { describe, expect, it } from 'vitest';
import { hasAnyPermission, hasPermission } from '@/core/auth/permissions';

describe('permissions helpers', () => {
    it('returns true when the required permission exists', () => {
        expect(hasPermission(['inventory.product.read', 'sales.customer.read'], 'inventory.product.read')).toBe(true);
    });

    it('returns false when the required permission does not exist', () => {
        expect(hasPermission(['inventory.product.read'], 'reports.platform-observability.read')).toBe(false);
    });

    it('returns true when at least one permission matches', () => {
        expect(hasAnyPermission(['sales.customer.read'], ['inventory.product.read', 'sales.customer.read'])).toBe(true);
    });
});
