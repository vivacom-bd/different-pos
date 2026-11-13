import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { RoleMatrix, sameSet } from '@/components/admin/role-matrix';
import { i18n } from '@/i18n';

const put = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        put: (...args: unknown[]) => put(...args),
        post: vi.fn(),
        delete: vi.fn(),
        on: () => () => undefined,
    },
    useForm: () => ({}),
}));

const permissions = [
    'dashboard.view',
    'pos.sell',
    'sales.view',
    'sales.void',
    'users.manage',
    'settings.manage',
    'backup.view',
    'backup.run',
    'sms.send',
];
const roles = [
    {
        id: 1,
        name: 'Owner',
        is_system: true,
        max_discount_pct_bp: 0,
        permissions,
        users: 1,
    },
    {
        id: 2,
        name: 'Cashier',
        is_system: false,
        max_discount_pct_bp: 500,
        permissions: ['pos.sell'],
        users: 2,
    },
    {
        id: 3,
        name: 'Manager',
        is_system: false,
        max_discount_pct_bp: 0,
        permissions: ['sales.view'],
        users: 1,
    },
];
const sensitive = ['users.manage', 'backup.run'];

const box = (name: string) => screen.getByRole('checkbox', { name });

beforeEach(() => put.mockReset());
afterEach(async () => {
    cleanup();
    await i18n.changeLanguage('en');
});

describe('RoleMatrix', () => {
    for (const locale of ['en', 'bn'] as const) {
        it(`toggles every checkbox and group box without crashing (${locale}, owner)`, async () => {
            await i18n.changeLanguage(locale);
            render(
                <RoleMatrix
                    permissions={permissions}
                    roles={roles}
                    sensitive_permissions={sensitive}
                    is_owner
                />,
            );
            for (const b of screen.getAllByRole('checkbox')) {
                if (b.getAttribute('data-disabled') === null)
                    fireEvent.click(b);
            }
            for (const b of screen.getAllByRole('checkbox')) {
                if (b.getAttribute('data-disabled') === null)
                    fireEvent.click(b);
            }
            expect(put).not.toHaveBeenCalled();
        });
    }

    it('keeps sensitive cells locked for a non-owner and still toggles the rest', () => {
        render(
            <RoleMatrix
                permissions={permissions}
                roles={roles}
                sensitive_permissions={sensitive}
                is_owner={false}
            />,
        );
        expect(
            box('Cashier: Manage users and roles').hasAttribute('disabled'),
        ).toBe(true);
        for (const b of screen.getAllByRole('checkbox')) {
            if (!b.hasAttribute('disabled')) fireEvent.click(b);
        }
    });

    it('only edits a local draft on toggle; Save sends one PUT with the full set', () => {
        render(
            <RoleMatrix
                permissions={permissions}
                roles={roles}
                sensitive_permissions={sensitive}
                is_owner
            />,
        );
        expect(screen.queryByRole('status')).toBeNull();
        fireEvent.click(box('Cashier: Sell at the register'));
        fireEvent.click(box('Cashier: See the dashboard'));
        expect(put).not.toHaveBeenCalled();
        expect(screen.getByRole('status')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Save: Cashier' }));
        expect(put).toHaveBeenCalledTimes(1);
        const [url, data] = put.mock.calls[0] as [
            string,
            { permissions: string[]; max_discount_pct_bp: number },
        ];
        expect(url).toBe('/app/settings/roles/2');
        expect(data.permissions).toEqual(['dashboard.view']);
        expect(data.max_discount_pct_bp).toBe(500);
    });

    it('Cancel discards the draft', () => {
        render(
            <RoleMatrix
                permissions={permissions}
                roles={roles}
                sensitive_permissions={sensitive}
                is_owner
            />,
        );
        fireEvent.click(box('Manager: See the dashboard'));
        expect(
            box('Manager: See the dashboard').getAttribute('data-state'),
        ).toBe('checked');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            box('Manager: See the dashboard').getAttribute('data-state'),
        ).toBe('unchecked');
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('group select-all changes every role at once and shows a mixed state', () => {
        render(
            <RoleMatrix
                permissions={permissions}
                roles={roles}
                sensitive_permissions={sensitive}
                is_owner
            />,
        );
        fireEvent.click(screen.getAllByRole('button', { name: 'All' })[0]!);
        expect(
            box('Cashier: See the dashboard').getAttribute('data-state'),
        ).toBe('checked');
        expect(
            box('Manager: See the dashboard').getAttribute('data-state'),
        ).toBe('checked');
        // sales group: Manager holds 1 of 2 -> indeterminate
        expect(box('Manager: Sales').getAttribute('data-state')).toBe(
            'indeterminate',
        );
    });
});

describe('sameSet', () => {
    it('compares without order', () => {
        expect(sameSet(['a', 'b'], ['b', 'a'])).toBe(true);
        expect(sameSet(['a'], ['a', 'b'])).toBe(false);
    });
});
