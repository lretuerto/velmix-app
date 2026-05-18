import { useLocation } from 'react-router-dom';
import { useAppShell } from '@/core/app/hooks';

export function TenantSwitcher() {
    const boot = useAppShell();
    const location = useLocation();
    const memberships = boot.tenant.memberships;
    const selectedTenantCode = boot.tenant.selected?.code ?? '';

    if (memberships.length === 0) {
        return null;
    }

    const handleChange = (nextTenantCode: string) => {
        const params = new URLSearchParams(location.search);

        if (nextTenantCode === '') {
            params.delete('tenant');
        } else {
            params.set('tenant', nextTenantCode);
        }

        const search = params.toString();
        const nextUrl = `/app${location.pathname}${search !== '' ? `?${search}` : ''}`;

        window.location.assign(nextUrl);
    };

    return (
        <label className="flex flex-col gap-2">
            <span className="text-[10px] font-black uppercase tracking-[0.22em] text-white/45">
                Tenant activo
            </span>
            <select
                className="rounded-[var(--velmix-radius-md)] border border-white/10 bg-white px-3 py-2 text-sm font-semibold text-[var(--velmix-ink)] outline-none transition focus:border-[#f0b27f]"
                value={selectedTenantCode}
                onChange={(event) => handleChange(event.target.value)}
            >
                {memberships.length > 1 && <option value="">Seleccionar tenant</option>}
                {memberships.map((membership) => (
                    <option key={membership.id} value={membership.code}>
                        {membership.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
