import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import PurchaseCreate from '@/pages/purchases/create';
import { i18n } from '@/i18n';

// The shared top-bar ticker is `todayTicker`; the page's own `today` is a plain date string.
// The two once shared a name, so the ticker read the date and crashed with a MoneyError.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn() },
    usePage: () => ({
        props: {
            today: '2026-11-03',
            todayTicker: { total_paisa: 150_000, sales: 2 },
        },
    }),
}));

const t = (key: string): string => i18n.t(key);

afterEach(cleanup);

describe('PurchaseCreate initial state', () => {
    it('renders an empty GRN dated today with zero totals and receive disabled', () => {
        render(
            <PurchaseCreate
                vendors={[{ id: 1, name: 'Acme Textiles' }]}
                paymentMethods={[
                    { id: 3, name: 'Cash', requires_reference: false },
                ]}
                canPay
                today="2026-11-03"
            />,
        );

        expect(
            (screen.getByLabelText(t('purchases.date')) as HTMLInputElement)
                .value,
        ).toBe('2026-11-03');
        expect(screen.getByText(t('purchases.scan_hint'))).toBeTruthy();
        expect(screen.getAllByText('৳0.00').length).toBeGreaterThanOrEqual(2);
        expect(
            (
                screen.getByRole('button', {
                    name: t('purchases.receive'),
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('tells the buyer why receive is disabled (no lines; vendor is optional)', () => {
        render(
            <PurchaseCreate
                vendors={[{ id: 1, name: 'Acme Textiles' }]}
                paymentMethods={[]}
                canPay={false}
                today="2026-11-03"
            />,
        );

        expect(screen.getByText(t('purchases.blocked_lines'))).toBeTruthy();
    });

    it('offers "New item" and the All / Raw materials / Products filter only with products.create', () => {
        render(
            <PurchaseCreate
                vendors={[{ id: 1, name: 'Acme Textiles' }]}
                paymentMethods={[]}
                canPay={false}
                canCreateItem
                units={[
                    {
                        id: 1,
                        name: 'Metre',
                        short_name: 'm',
                        allows_decimal: true,
                    },
                ]}
                today="2026-11-03"
            />,
        );
        expect(
            screen.getByRole('button', {
                name: new RegExp(t('purchases.new_item')),
            }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: t('purchases.kind_raw') }),
        ).toBeTruthy();
        cleanup();

        render(
            <PurchaseCreate
                vendors={[{ id: 1, name: 'Acme Textiles' }]}
                paymentMethods={[]}
                canPay={false}
                today="2026-11-03"
            />,
        );
        expect(
            screen.queryByRole('button', {
                name: new RegExp(t('purchases.new_item')),
            }),
        ).toBeNull();
    });
});
