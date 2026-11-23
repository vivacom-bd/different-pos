import type { Paisa } from '@/engine/money';
import type { CustomerCard, PaymentMethod } from './types';

export type TenderRow = {
    methodId: number;
    amount: Paisa;
    reference: string;
};

export type TenderProblem =
    | 'walk_in_due'
    | 'over_limit'
    | 'credit_needs_customer'
    | 'credit_exceeds_advance'
    | 'change_from_non_cash'
    | 'reference_required';

export type Settlement = {
    tendered: Paisa;
    change: Paisa;
    due: Paisa;
    creditUsed: Paisa;
    /** The customer's balance once this sale is settled (null for the walk-in). */
    balanceAfter: Paisa | null;
    problems: TenderProblem[];
};

/**
 * Mirrors the server's tender rules so the cashier sees them before Pay: change only from cash, due
 * only for a named customer within the credit limit (a manager may override the limit), customer
 * credit up to the spendable advance, references where the method needs one. The courier
 * `condition` (collected on delivery, clamped to the due) does not count toward the credit limit.
 */
export function settle(
    total: Paisa,
    tenders: readonly TenderRow[],
    methods: readonly PaymentMethod[],
    customer: CustomerCard | null,
    condition: Paisa = 0,
): Settlement {
    const byId = new Map(methods.map((m) => [m.id, m]));
    let tendered = 0;
    let cash = 0;
    let creditUsed = 0;
    const problems = new Set<TenderProblem>();

    for (const row of tenders) {
        if (row.amount <= 0) {
            continue;
        }
        const method = byId.get(row.methodId);
        if (!method) {
            continue;
        }
        tendered += row.amount;
        if (method.type === 'cash') {
            cash += row.amount;
        }
        if (method.type === 'customer_credit') {
            creditUsed += row.amount;
            if (customer === null) {
                problems.add('credit_needs_customer');
            }
        }
        if (method.requires_reference && row.reference.trim() === '') {
            problems.add('reference_required');
        }
    }

    const change = Math.max(0, tendered - total);
    const due = Math.max(0, total - tendered);
    if (change > cash) {
        problems.add('change_from_non_cash');
    }
    if (customer !== null && creditUsed > customer.advance) {
        problems.add('credit_exceeds_advance');
    }

    let balanceAfter: Paisa | null = null;
    if (customer !== null) {
        balanceAfter =
            customer.balance +
            due +
            creditUsed -
            Math.min(Math.max(0, condition), due);
    }
    if (due > 0) {
        if (customer === null) {
            problems.add('walk_in_due');
        } else if (
            customer.credit_limit !== null &&
            (balanceAfter ?? 0) > customer.credit_limit
        ) {
            problems.add('over_limit');
        }
    }

    return {
        tendered,
        change,
        due,
        creditUsed,
        balanceAfter,
        problems: [...problems],
    };
}

/** Problems a manager approval can lift. */
export const OVERRIDABLE: readonly TenderProblem[] = ['over_limit'];

/** Quick cash buttons: the exact amount, then the next round notes above it (৳100/500/1000). */
export function quickCash(total: Paisa): Paisa[] {
    if (total <= 0) {
        return [];
    }
    const up = (step: Paisa) => {
        const over = total + step - 1;

        return over - (over % step);
    };
    const values = [total, up(10_000), up(50_000), up(100_000), up(200_000)];

    return [...new Set(values)].sort((a, b) => a - b).slice(0, 4);
}
