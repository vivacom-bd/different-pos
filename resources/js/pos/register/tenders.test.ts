import { describe, expect, it } from 'vite-plus/test';
import { quickCash, settle } from './tenders';
import type { CustomerCard, PaymentMethod } from './types';

const methods: PaymentMethod[] = [
    { id: 1, name: 'Cash', type: 'cash', requires_reference: false },
    { id: 2, name: 'Card', type: 'card', requires_reference: false },
    { id: 3, name: 'bKash', type: 'mobile_wallet', requires_reference: true },
    {
        id: 4,
        name: 'Customer credit',
        type: 'customer_credit',
        requires_reference: false,
    },
];
const named = (over: Partial<CustomerCard> = {}): CustomerCard => ({
    id: 1,
    uuid: 'u',
    number: null,
    name: 'Rahim',
    name_bn: null,
    shop_name: null,
    phone: null,
    group: null,
    balance: 0,
    credit_limit: null,
    advance: 0,
    is_active: true,
    pin_status: 'pending',
    ...over,
});
const row = (methodId: number, amount: number, reference = '') => ({
    methodId,
    amount,
    reference,
});

describe('settle', () => {
    it('gives change from cash', () => {
        const s = settle(45_000, [row(1, 50_000)], methods, null);
        expect(s).toMatchObject({
            tendered: 50_000,
            change: 5_000,
            due: 0,
            problems: [],
        });
    });

    it('never gives change from card', () => {
        expect(
            settle(45_000, [row(2, 50_000)], methods, null).problems,
        ).toContain('change_from_non_cash');
    });

    it('does not count the courier condition toward the credit limit', () => {
        const customer = named({ credit_limit: 10_000 });
        expect(
            settle(45_000, [row(1, 30_000)], methods, customer, 10_000)
                .problems,
        ).toEqual([]);
        expect(
            settle(45_000, [row(1, 30_000)], methods, customer, 4_000)
                .problems,
        ).toEqual(['over_limit']);
        // A condition above the due is clamped to it.
        expect(
            settle(45_000, [row(1, 30_000)], methods, customer, 99_000)
                .balanceAfter,
        ).toBe(0);
    });

    it('refuses a due for the walk-in, allows it for a named customer within the limit', () => {
        expect(
            settle(45_000, [row(1, 40_000)], methods, null).problems,
        ).toEqual(['walk_in_due']);
        const ok = settle(
            45_000,
            [row(1, 40_000)],
            methods,
            named({ balance: 1_000, credit_limit: 10_000 }),
        );
        expect(ok).toMatchObject({
            due: 5_000,
            balanceAfter: 6_000,
            problems: [],
        });
        expect(
            settle(
                45_000,
                [row(1, 30_000)],
                methods,
                named({ credit_limit: 10_000 }),
            ).problems,
        ).toEqual(['over_limit']);
    });

    it('limits customer credit to the advance and needs references where required', () => {
        expect(
            settle(10_000, [row(4, 10_000)], methods, named({ advance: 5_000 }))
                .problems,
        ).toContain('credit_exceeds_advance');
        expect(
            settle(10_000, [row(3, 10_000)], methods, null).problems,
        ).toEqual(['reference_required']);
        expect(
            settle(10_000, [row(3, 10_000, 'TX9')], methods, null).problems,
        ).toEqual([]);
    });
});

describe('quickCash', () => {
    it('offers exact, then the next round notes, in integer paisa', () => {
        expect(quickCash(45_050)).toEqual([45_050, 50_000, 100_000, 200_000]);
        expect(quickCash(50_000)).toEqual([50_000, 100_000, 200_000]);
        expect(quickCash(0)).toEqual([]);
    });
});
