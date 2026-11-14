import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { saleBody } from '../api';
import { emptyCart } from '../cart';
import { payBlockedByNoCustomer, showDonePanel } from '../present';
import type { CartLine, PaymentMethod } from '../types';
import { PaymentDialog } from './payment-dialog';
import { BillLine } from './register-parts';

afterEach(cleanup);

const line = (stock: number | null): CartLine => ({
    key: '1',
    productId: 1,
    name: 'Shirt',
    sku: 'SH-1',
    label: null,
    unitPrice: 50_000,
    qty: 2_000,
    taxRateBp: 0,
    allowsDecimal: false,
    stock,
    discount: { type: 'none', value: 0 },
});

const row = (stock: number | null) =>
    render(
        <table>
            <tbody>
                <BillLine
                    index={0}
                    line={line(stock)}
                    priced={undefined}
                    selected={false}
                    onSelect={vi.fn()}
                    onQty={vi.fn()}
                    onStep={vi.fn()}
                    onDiscount={vi.fn()}
                    onRemove={vi.fn()}
                />
            </tbody>
        </table>,
    );

describe('bill line Available column', () => {
    it('shows the branch stock of the item', () => {
        row(12_000);
        expect(screen.getByTestId('bill-available').textContent).toBe('12');
    });

    it('shows a dash for untracked stock', () => {
        row(null);
        expect(screen.getByTestId('bill-available').textContent).toBe('–');
    });
});

describe('register gates', () => {
    it('blocks Pay without a customer only when the setting is on', () => {
        expect(payBlockedByNoCustomer(null, true)).toBe(true);
        expect(payBlockedByNoCustomer(null, false)).toBe(false);
        expect(payBlockedByNoCustomer({ id: 1 }, true)).toBe(false);
    });

    it('shows the done dialog only when print after sale is on', () => {
        expect(showDonePanel(true)).toBe(true);
        expect(showDonePanel(false)).toBe(false);
    });
});

describe('courier on the sale', () => {
    it('sends the picked courier (or null) with the sale body', () => {
        const state = emptyCart('11111111-1111-4111-8111-111111111111');
        expect(saleBody(state, [], 0, null, ' Pathao ').courier_name).toBe(
            'Pathao',
        );
        expect(saleBody(state, [], 0, null).courier_name).toBeNull();
        expect(saleBody(state, [], 0, null, '  ').courier_name).toBeNull();
    });

    const cash: PaymentMethod = {
        id: 1,
        name: 'Cash',
        type: 'cash',
        requires_reference: false,
    };

    it('offers the courier list in the pay dialog and submits the choice', async () => {
        const onSubmit = vi.fn().mockResolvedValue(null);
        render(
            <PaymentDialog
                open
                total={10_000}
                methods={[cash]}
                customer={null}
                approval={null}
                couriers={['Pathao', 'RedX']}
                onSubmit={onSubmit}
                onNeedApproval={vi.fn()}
                onAttachCustomer={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Courier (optional)'), {
            target: { value: 'RedX' },
        });
        fireEvent.submit(
            screen.getByLabelText('Courier (optional)').closest('form')!,
        );
        await Promise.resolve();
        expect(onSubmit).toHaveBeenCalledWith(expect.any(Array), 'RedX');
    });

    it('hides the picker without couriers', () => {
        render(
            <PaymentDialog
                open
                total={10_000}
                methods={[cash]}
                customer={null}
                approval={null}
                onSubmit={vi.fn()}
                onNeedApproval={vi.fn()}
                onAttachCustomer={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        expect(screen.queryByLabelText('Courier (optional)')).toBeNull();
    });
});
