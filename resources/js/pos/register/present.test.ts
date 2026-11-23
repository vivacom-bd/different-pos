import { describe, expect, it } from 'vite-plus/test';
import { cartReducer, cartTotals, emptyCart } from './cart';
import {
    addCash,
    breakdownRows,
    creditMeter,
    exactCash,
    initialTenders,
    leaveDue,
    payKey,
    pickTender,
    stockTone,
    tenderOptions,
} from './present';
import type { CatalogVariant, CustomerCard, PaymentMethod } from './types';

const methods: PaymentMethod[] = [
    { id: 9, name: 'City Bank', type: 'bank', requires_reference: true },
    { id: 3, name: 'bKash', type: 'mobile_wallet', requires_reference: true },
    { id: 2, name: 'Card', type: 'card', requires_reference: false },
    { id: 5, name: 'Rocket', type: 'mobile_wallet', requires_reference: true },
    { id: 4, name: 'Nagad', type: 'mobile_wallet', requires_reference: true },
    { id: 1, name: 'Cash', type: 'cash', requires_reference: false },
    {
        id: 6,
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
const variant = (
    id: number,
    price: number,
    tax_rate_bp = 0,
): CatalogVariant => ({
    id,
    name: `P${id}`,
    sku: `P-${id}`,
    barcode: null,
    label: null,
    option1: null,
    option2: null,
    price,
    tax_rate_bp,
    stock: null,
    allows_decimal: false,
});
const settings = { tax_inclusive: false, rounding_step: 0 };

describe('breakdownRows', () => {
    it('lists subtotal, line discounts, the order discount box, VAT and the total for a plain bill (zero rows included)', () => {
        const cart = cartReducer(emptyCart('u1'), {
            type: 'add',
            items: [{ variant: variant(1, 100_000), qty: 2000 }],
        });
        const rows = breakdownRows(cartTotals(cart, settings));
        expect(rows.map((r) => [r.id, r.amount])).toEqual([
            ['subtotal', 200_000],
            ['line_discount', 0],
            ['order_discount', 0],
            ['vat', 0],
            ['total', 200_000],
        ]);
    });

    it('takes every amount from the engine: line, group, order discount, VAT', () => {
        let cart = cartReducer(emptyCart('u1'), {
            type: 'add',
            items: [{ variant: variant(1, 100_000, 1500), qty: 1000 }],
        });
        cart = cartReducer(cart, {
            type: 'line_discount',
            key: '1',
            discount: { type: 'percent_bp', value: 1000 },
        });
        cart = cartReducer(cart, {
            type: 'order_discount',
            discount: { type: 'amount', value: 5_000 },
        });
        cart = cartReducer(cart, {
            type: 'customer',
            customer: named({ group: { name: 'VIP', discount_bp: 500 } }),
        });
        const totals = cartTotals(cart, settings);
        const rows = breakdownRows(totals);
        const by = Object.fromEntries(rows.map((r) => [r.id, r]));
        expect(by.line_discount?.amount).toBe(totals.order.line_discount_total);
        expect(by.group_discount?.amount).toBe(totals.order.group_discount);
        expect(by.order_discount?.amount).toBe(totals.order.order_discount);
        expect(by.vat?.amount).toBe(totals.order.tax);
        expect(by.total?.amount).toBe(totals.order.grand_total);
        expect(by.line_discount?.negative).toBe(true);
        expect(rows.at(-1)?.id).toBe('total');
    });

    it('shows rounding only when it is not zero', () => {
        const cart = cartReducer(emptyCart('u1'), {
            type: 'add',
            items: [{ variant: variant(1, 10_050), qty: 1000 }],
        });
        const rounded = breakdownRows(
            cartTotals(cart, { tax_inclusive: false, rounding_step: 100 }),
        );
        expect(rounded.some((r) => r.id === 'rounding')).toBe(true);
        const exact = breakdownRows(cartTotals(cart, settings));
        expect(exact.some((r) => r.id === 'rounding')).toBe(false);
    });
});

describe('stockTone', () => {
    it('is danger at zero or below, warning up to 5, success above, null untracked', () => {
        expect(stockTone(0)).toBe('danger');
        expect(stockTone(-1000)).toBe('danger');
        expect(stockTone(5000)).toBe('warning');
        expect(stockTone(5001)).toBe('success');
        expect(stockTone(null)).toBeNull();
    });
});

describe('tenderOptions', () => {
    it('orders like the mockup (cash, card, bKash, Nagad, Rocket, bank) with Alt keys', () => {
        const options = tenderOptions(methods, null);
        expect(options.map((o) => [o.method.name, o.key, o.icon])).toEqual([
            ['Cash', 'c', 'cash'],
            ['Card', 'k', 'card'],
            ['bKash', 'b', 'wallet'],
            ['Nagad', 'n', 'wallet'],
            ['Rocket', 'r', 'wallet'],
            ['City Bank', 'a', 'bank'],
        ]);
    });

    it('shows customer credit only for a customer with credit to spend', () => {
        expect(
            tenderOptions(methods, named({ advance: 0 })).some(
                (o) => o.method.type === 'customer_credit',
            ),
        ).toBe(false);
        const withCredit = tenderOptions(methods, named({ advance: 10_000 }));
        expect(withCredit.at(-1)).toMatchObject({
            method: { id: 6 },
            key: null,
            icon: 'credit',
        });
    });

    it('gives a shortcut to the first method of a kind only', () => {
        const options = tenderOptions(
            [
                {
                    id: 1,
                    name: 'Cash',
                    type: 'cash',
                    requires_reference: false,
                },
                {
                    id: 2,
                    name: 'Cash (USD)',
                    type: 'cash',
                    requires_reference: false,
                },
            ],
            null,
        );
        expect(options.map((o) => o.key)).toEqual(['c', null]);
    });

    it('is empty when the tenant has no active method', () => {
        expect(tenderOptions([], null)).toEqual([]);
    });
});

describe('tender rows', () => {
    const options = tenderOptions(methods, null);
    const start = initialTenders(options, 150_000);
    const amounts = (rows: typeof start) =>
        Object.fromEntries(rows.map((r) => [r.methodId, r.amount]));

    it('starts with the exact amount in cash', () => {
        expect(amounts(start)).toMatchObject({ 1: 150_000, 2: 0, 3: 0 });
    });

    it('picking a method gives it the rest and takes it off cash', () => {
        const rows = pickTender(start, 3, 150_000, methods, null);
        expect(amounts(rows)).toMatchObject({ 1: 0, 3: 150_000 });
        const partial = pickTender(
            rows.map((r) => (r.methodId === 3 ? { ...r, amount: 50_000 } : r)),
            1,
            150_000,
            methods,
            null,
        );
        expect(amounts(partial)).toMatchObject({ 1: 100_000, 3: 50_000 });
    });

    it('caps customer credit at what the customer can spend', () => {
        const customer = named({ advance: 20_000 });
        const rows = initialTenders(tenderOptions(methods, customer), 150_000);
        const picked = pickTender(rows, 6, 150_000, methods, customer);
        expect(amounts(picked)).toMatchObject({ 6: 20_000, 1: 130_000 });
    });

    it('exact cash, +cash, rest as due', () => {
        const split = pickTender(start, 2, 150_000, methods, null);
        expect(amounts(exactCash(split, 150_000, 1))).toMatchObject({
            1: 150_000,
            2: 0,
        });
        expect(amounts(addCash(start, 1, 10_000))[1]).toBe(160_000);
        expect(amounts(leaveDue(split, 1))).toMatchObject({ 1: 0, 2: 150_000 });
        // No cash method: the helpers leave the rows alone.
        expect(addCash(start, undefined, 10_000)).toEqual(start);
    });
});

describe('payKey', () => {
    const options = tenderOptions(methods, null);
    const key = (
        k: string,
        mods: Partial<{
            altKey: boolean;
            ctrlKey: boolean;
            metaKey: boolean;
            shiftKey: boolean;
            typing: boolean;
        }> = {},
    ) =>
        payKey(
            {
                key: k,
                altKey: false,
                ctrlKey: false,
                metaKey: false,
                shiftKey: false,
                typing: false,
                ...mods,
            },
            options,
            3,
        );

    it('maps F10, Alt+D, Alt+E and the method keys', () => {
        expect(key('F10')).toEqual({ type: 'submit' });
        expect(key('F10', { typing: true })).toEqual({ type: 'submit' });
        expect(key('F10', { shiftKey: true })).toBeNull();
        expect(key('d', { altKey: true })).toEqual({ type: 'due' });
        expect(key('E', { altKey: true })).toEqual({ type: 'exact' });
        expect(key('o', { altKey: true })).toEqual({ type: 'condition' });
        expect(key('b', { altKey: true, typing: true })).toEqual({
            type: 'pick',
            methodId: 3,
        });
        expect(key('r', { altKey: true })).toEqual({
            type: 'pick',
            methodId: 5,
        });
        expect(key('z', { altKey: true })).toBeNull();
        expect(key('c', { altKey: true, ctrlKey: true })).toBeNull();
    });

    it('quick cash digits only outside a field and within range', () => {
        expect(key('1')).toEqual({ type: 'quick', index: 0 });
        expect(key('3')).toEqual({ type: 'quick', index: 2 });
        expect(key('4')).toBeNull();
        expect(key('1', { typing: true })).toBeNull();
        expect(key('0')).toBeNull();
    });
});

describe('creditMeter', () => {
    it('is the share of the limit used, flagged over the limit', () => {
        expect(creditMeter(50_000, 200_000)).toEqual({
            percent: 25,
            over: false,
        });
        expect(creditMeter(250_000, 200_000)).toEqual({
            percent: 100,
            over: true,
        });
        expect(creditMeter(-10_000, 200_000)).toEqual({
            percent: 0,
            over: false,
        });
    });

    it('is null without a limit or a customer', () => {
        expect(creditMeter(50_000, null)).toBeNull();
        expect(creditMeter(null, 200_000)).toBeNull();
        expect(creditMeter(50_000, 0)).toBeNull();
    });
});
