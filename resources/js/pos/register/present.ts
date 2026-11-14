import type { Paisa } from '@/engine/money';
import type { CartTotals } from '@/engine/pricing';
import type { TenderRow } from './tenders';
import type { CustomerCard, PaymentMethod, PaymentMethodType } from './types';

/**
 * Presentation helpers for the register (mockup layout): the payment breakdown rows, the tender
 * rows of the payment panel and its keys. Pure: every amount comes from the pricing engine.
 */

export type BreakdownRow = {
    id:
        | 'subtotal'
        | 'line_discount'
        | 'group_discount'
        | 'order_discount'
        | 'vat'
        | 'rounding'
        | 'total';
    amount: Paisa;
    /** Shown as a deduction (−). */
    negative?: boolean;
};

/**
 * Subtotal → line discounts → group discount → order discount → VAT → rounding → total, as the mockup
 * lists them. Line discounts, the order discount row (it carries the "add order discount" button) and
 * VAT are always there (৳0.00 when zero); a zero group discount and zero rounding are left out.
 */
export function breakdownRows(totals: CartTotals): BreakdownRow[] {
    const o = totals.order;
    const rows: BreakdownRow[] = [{ id: 'subtotal', amount: o.subtotal }];
    // Mockup totals panel: line item discounts and VAT always show (৳0.00 when zero).
    rows.push({
        id: 'line_discount',
        amount: o.line_discount_total,
        negative: true,
    });
    if (o.group_discount > 0) {
        rows.push({
            id: 'group_discount',
            amount: o.group_discount,
            negative: true,
        });
    }
    rows.push({
        id: 'order_discount',
        amount: o.order_discount,
        negative: true,
    });
    rows.push({ id: 'vat', amount: o.tax });
    if (o.rounding !== 0) {
        rows.push({ id: 'rounding', amount: o.rounding });
    }
    rows.push({ id: 'total', amount: o.grand_total });

    return rows;
}

/** Stock badge tone of a catalogue tile (mockup: 0 danger, ≤ 5 warning, else success). */
export function stockTone(
    stockMilli: number | null,
): 'danger' | 'warning' | 'success' | null {
    if (stockMilli === null) {
        return null;
    }
    if (stockMilli <= 0) {
        return 'danger';
    }

    return stockMilli <= 5_000 ? 'warning' : 'success';
}

export type TenderIcon = 'cash' | 'card' | 'wallet' | 'bank' | 'credit';

export type TenderOption = {
    method: PaymentMethod;
    icon: TenderIcon;
    /** Alt+<key> picks this method in the payment panel; null when none. */
    key: string | null;
};

/** Mockup tender order: Cash, Card, bKash, Nagad, Rocket, Bank, then customer credit and the rest. */
const RANK: { key: string | null; pick: (m: PaymentMethod) => boolean }[] = [
    { key: 'c', pick: (m) => m.type === 'cash' },
    { key: 'k', pick: (m) => m.type === 'card' },
    { key: 'b', pick: (m) => wallet(m, /bkash/i) },
    { key: 'n', pick: (m) => wallet(m, /nagad/i) },
    { key: 'r', pick: (m) => wallet(m, /rocket/i) },
    { key: null, pick: (m) => m.type === 'mobile_wallet' },
    { key: 'a', pick: (m) => m.type === 'bank' },
    { key: null, pick: (m) => m.type === 'customer_credit' },
];

function wallet(m: PaymentMethod, name: RegExp): boolean {
    return m.type === 'mobile_wallet' && name.test(m.name);
}

const ICON: Record<PaymentMethodType, TenderIcon> = {
    cash: 'cash',
    card: 'card',
    mobile_wallet: 'wallet',
    bank: 'bank',
    customer_credit: 'credit',
    exchange_credit: 'credit',
};

/**
 * The tenant's payment methods as the payment panel's rows. Inactive methods never reach the page;
 * customer credit shows only for a named customer with credit to spend. A shortcut goes to the first
 * method of its kind only, so two cash methods never share Alt+C.
 */
export function tenderOptions(
    methods: readonly PaymentMethod[],
    customer: CustomerCard | null,
): TenderOption[] {
    const usable = methods.filter(
        (m) =>
            m.type !== 'customer_credit' ||
            (customer !== null && customer.advance > 0),
    );
    const rank = (m: PaymentMethod) => {
        const i = RANK.findIndex((r) => r.pick(m));

        return i < 0 ? RANK.length : i;
    };
    const sorted = usable
        .map((m, i) => ({ m, i }))
        .sort((a, b) => rank(a.m) - rank(b.m) || a.i - b.i)
        .map(({ m }) => m);
    const taken = new Set<string>();

    return sorted.map((method) => {
        let key = RANK[rank(method)]?.key ?? null;
        if (key !== null && taken.has(key)) {
            key = null;
        }
        if (key !== null) {
            taken.add(key);
        }

        return { method, icon: ICON[method.type], key };
    });
}

/** One row per usable method; cash (if any) starts with the exact amount, the rest at zero. */
export function initialTenders(
    options: readonly TenderOption[],
    total: Paisa,
): TenderRow[] {
    const cashId = options.find((o) => o.method.type === 'cash')?.method.id;

    return options.map((o) => ({
        methodId: o.method.id,
        amount: o.method.id === cashId ? total : 0,
        reference: '',
    }));
}

/**
 * Picking a method (Alt+key or its row): it takes what is still open after the other non-cash
 * tenders (capped at the customer's credit for customer credit); cash keeps only what is left.
 */
export function pickTender(
    rows: readonly TenderRow[],
    methodId: number,
    total: Paisa,
    methods: readonly PaymentMethod[],
    customer: CustomerCard | null,
): TenderRow[] {
    const byId = new Map(methods.map((m) => [m.id, m]));
    const isCash = (id: number) => byId.get(id)?.type === 'cash';
    const others = rows
        .filter((r) => r.methodId !== methodId && !isCash(r.methodId))
        .reduce((sum, r) => sum + r.amount, 0);
    let amount = Math.max(0, total - others);
    const method = byId.get(methodId);
    if (method?.type === 'customer_credit' && customer) {
        amount = Math.min(amount, customer.advance);
    }
    if (isCash(methodId)) {
        return rows.map((r) =>
            r.methodId === methodId ? { ...r, amount } : r,
        );
    }
    const cashLeft = Math.max(0, total - others - amount);

    return rows.map((r) => {
        if (r.methodId === methodId) {
            return { ...r, amount };
        }

        return isCash(r.methodId) ? { ...r, amount: cashLeft } : r;
    });
}

/** Exact cash (Alt+E): cash takes the whole bill, every other tender goes back to zero. */
export function exactCash(
    rows: readonly TenderRow[],
    total: Paisa,
    cashId: number | undefined,
): TenderRow[] {
    return rows.map((r) => ({
        ...r,
        amount: r.methodId === cashId ? total : 0,
    }));
}

/** +৳100 / +৳500 / +৳1000 on the cash row. */
export function addCash(
    rows: readonly TenderRow[],
    cashId: number | undefined,
    step: Paisa,
): TenderRow[] {
    return rows.map((r) =>
        r.methodId === cashId ? { ...r, amount: r.amount + step } : r,
    );
}

/** Rest as due (Alt+D): the cash row drops to zero, the other tenders stay. */
export function leaveDue(
    rows: readonly TenderRow[],
    cashId: number | undefined,
): TenderRow[] {
    return rows.map((r) => (r.methodId === cashId ? { ...r, amount: 0 } : r));
}

export type PayKey =
    | { type: 'submit' }
    | { type: 'due' }
    | { type: 'exact' }
    | { type: 'pick'; methodId: number }
    | { type: 'quick'; index: number };

/**
 * The payment panel's keys: F10 completes; Alt+E exact cash, Alt+D rest as due, Alt+<method key>
 * picks a method; 1–4 quick cash when no field has the focus.
 */
export function payKey(
    e: Pick<
        KeyboardEvent,
        'key' | 'altKey' | 'ctrlKey' | 'metaKey' | 'shiftKey'
    > & { typing: boolean },
    options: readonly TenderOption[],
    quickCount: number,
): PayKey | null {
    if (e.key === 'F10' && !e.shiftKey) {
        return { type: 'submit' };
    }
    if (e.altKey && !e.ctrlKey && !e.metaKey) {
        const key = e.key.toLowerCase();
        if (key === 'd') {
            return { type: 'due' };
        }
        if (key === 'e') {
            return { type: 'exact' };
        }
        const option = options.find((o) => o.key === key);

        return option ? { type: 'pick', methodId: option.method.id } : null;
    }
    if (e.ctrlKey || e.metaKey || e.typing) {
        return null;
    }
    const n = Number(e.key);
    if (Number.isInteger(n) && n >= 1 && n <= quickCount) {
        return { type: 'quick', index: n - 1 };
    }

    return null;
}

/** Credit-limit meter of the payment panel: share of the limit used once this sale is settled. */
export function creditMeter(
    balanceAfter: Paisa | null,
    limit: Paisa | null,
): { percent: number; over: boolean } | null {
    if (balanceAfter === null || limit === null || limit <= 0) {
        return null;
    }
    const used = Math.max(0, balanceAfter);

    return {
        percent: Math.min(100, Math.round((used * 100) / limit)),
        over: used > limit,
    };
}

/** "Require a customer on every sale" is on and none is attached: Pay is blocked (attach with F4). */
export function payBlockedByNoCustomer(
    customer: unknown,
    requireCustomer: boolean,
): boolean {
    return requireCustomer && customer === null;
}

/** After a sale: the done panel (with Print / Chalan) shows only when "print after sale" is on; off goes straight to the next sale. */
export function showDonePanel(autoPrint: boolean): boolean {
    return autoPrint;
}
