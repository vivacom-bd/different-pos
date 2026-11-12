import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vite-plus/test';
import {
    SaleInvoiceDialog,
    type SaleDetail,
} from '@/components/sales/sale-invoice-dialog';
import { HotkeyHost } from '@/hotkeys/host';
import { i18n } from '@/i18n';

const posts: string[] = [];
const navigations: string[] = [];

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: (url: string) => posts.push(url) },
    usePage: () => ({
        props: { auth: { permissions: [], branch_paper: 'a4' } },
        url: '/app/sales/9',
    }),
}));
vi.mock('@/command/navigate', () => ({
    navigateTo: (url: string) => navigations.push(url),
}));

const t = (key: string, options: Record<string, unknown> = {}): string =>
    i18n.t(key, options);

const detail = (overrides: Partial<SaleDetail> = {}): SaleDetail => ({
    sale: {
        id: 9,
        number: 'DF1-R1-26000413',
        status: 'completed',
        payment_status: 'partial',
        origin: 'pos',
        sold_at: '2026-11-03 19:35',
        cashier: 'Rana',
        register: { name: 'Register 1', code: 'R1' },
        note: null,
        courier_name: null,
        courier_tracking: null,
        buyer_vat_bin: null,
        sync_flags: [],
        subtotal: 466_000,
        item_discount_total: 14_500,
        order_discount: 0,
        tax_total: 0,
        shipping: 0,
        rounding: 0,
        total: 451_500,
        paid: 400_000,
        change_given: 0,
        due: 51_500,
        returned_total: 0,
        cogs_total: null,
        voided_at: null,
        voided_by: null,
        void_reason: null,
        public_url: null,
    },
    customer: null,
    lines: [
        {
            id: 1,
            name: 'Cotton Panjabi Classic',
            sku: 'PAN-1',
            qty: 2000,
            qty_returned: 0,
            unit_price: 145_000,
            discount: 14_500,
            tax_rate_bp: 0,
            tax_amount: 0,
            line_total: 275_500,
            cogs: null,
            batches: null,
        },
    ],
    payments: [],
    returns: [],
    exchange_of: null,
    sms: null,
    audit: [],
    can: {
        void: false,
        collect: false,
        return: true,
        exchange: false,
        share: true,
        resend_sms: false,
    },
    ...overrides,
});

beforeAll(() => {
    globalThis.ResizeObserver ??= class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
});

afterEach(() => {
    cleanup();
    posts.length = 0;
    navigations.length = 0;
    vi.restoreAllMocks();
});

const footer = () =>
    Array.from(
        document.querySelectorAll('[data-slot="dialog-actions"] button'),
    ).map((b) => b.textContent?.replace(/\s+/g, ' ').trim() ?? '');

describe('SaleInvoiceDialog', () => {
    it('shows the mockup sections and footer actions in order', () => {
        render(
            <SaleInvoiceDialog
                detail={detail()}
                methods={[]}
                onClose={vi.fn()}
            />,
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog.textContent).toContain('DF1-R1-26000413');
        for (const heading of [
            'sales.items_discounts',
            'sales.breakdown',
            'sales.customer_info',
        ]) {
            expect(
                screen.getByRole('heading', { name: t(heading) }),
            ).toBeTruthy();
        }
        expect(dialog.textContent).toContain(t('sales.outstanding_due'));
        const buttons = footer();
        expect(buttons[0]).toContain(t('sales.return_items'));
        expect(buttons[1]).toContain(t('sales.print_receipt'));
        expect(buttons[2]).toContain(t('sales.print_a4_tax'));
        expect(buttons.join('|')).not.toContain(t('sales.void'));
        expect(buttons.join('|')).not.toContain(t('sales.resend_sms'));
        // Cost & batches are not sent without products.view_cost.
        expect(dialog.textContent).not.toContain(t('sales.cost_batches'));
    });

    it('keeps cost and batches in a collapsed section', () => {
        const withCost = detail();
        withCost.sale.cogs_total = 80_000;
        withCost.lines[0] = {
            ...withCost.lines[0]!,
            cogs: 80_000,
            batches: [
                {
                    id: 1,
                    batch: 'ST123',
                    received_at: null,
                    qty: 2000,
                    unit_cost: 40_000,
                },
            ],
        };
        render(
            <SaleInvoiceDialog
                detail={withCost}
                methods={[]}
                onClose={vi.fn()}
            />,
        );
        const trigger = screen.getByRole('button', {
            name: new RegExp(t('sales.cost_batches')),
        });
        expect(trigger.getAttribute('aria-expanded')).toBe('false');
        expect(screen.queryByText(/ST123/)).toBeNull();
        fireEvent.click(trigger);
        expect(screen.getByText(/ST123/)).toBeTruthy();
    });

    it('resends a failed receipt SMS and closes on Esc', () => {
        const onClose = vi.fn();
        render(
            <SaleInvoiceDialog
                detail={detail({
                    sms: {
                        id: 77,
                        status: 'failed',
                        sent_at: '2026-11-03 19:36',
                    },
                    can: { ...detail().can, resend_sms: true },
                })}
                methods={[]}
                onClose={onClose}
            />,
        );
        expect(screen.getByText(t('sales.sms_failed'))).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', {
                name: new RegExp(t('sales.resend_sms')),
            }),
        );
        expect(posts).toEqual(['/app/messages/77/resend']);
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(onClose).toHaveBeenCalled();
    });

    it('binds the action keys once armed (R = return, P = print)', async () => {
        vi.useFakeTimers({ toFake: ['requestAnimationFrame'] });
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        render(
            <>
                <HotkeyHost />
                <SaleInvoiceDialog
                    detail={detail()}
                    methods={[]}
                    onClose={vi.fn()}
                />
            </>,
        );
        await act(async () => {
            vi.advanceTimersToNextFrame();
        });
        vi.useRealTimers();
        fireEvent.keyDown(document.body, { key: 'r' });
        expect(navigations).toEqual(['/app/returns/create?sale=9']);
        fireEvent.keyDown(document.body, { key: 'p' });
        expect(open).toHaveBeenCalledWith(
            '/app/sales/9/receipt?paper=a4&print=1',
            '_blank',
            'noopener',
        );
    });
});
