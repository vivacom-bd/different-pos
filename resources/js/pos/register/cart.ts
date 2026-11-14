import { randomUuid } from "@/lib/browser";
import {
    calculateCart,
    type CartTotals,
    type Discount,
} from "@/engine/pricing";
import { QTY_SCALE } from "@/engine/quantity";
import type {
    Approval,
    CartLine,
    CartState,
    CatalogVariant,
    CustomerCard,
    MemberAuthState,
    RegisterSettings,
} from "./types";

export const NO_DISCOUNT: Discount = { type: "none", value: 0 };

export function newClientUuid(): string {
    return randomUuid();
}

export function emptyCart(uuid: string = newClientUuid()): CartState {
    return {
        lines: [],
        selected: -1,
        orderDiscount: NO_DISCOUNT,
        customer: null,
        clientUuid: uuid,
        approval: null,
        member: null,
    };
}

export type CartAction =
    | { type: "add"; items: { variant: CatalogVariant; qty: number }[] }
    | { type: "qty"; key: string; qty: number }
    | { type: "remove"; key: string }
    | { type: "line_discount"; key: string; discount: Discount }
    | { type: "order_discount"; discount: Discount }
    | { type: "select"; index: number }
    | { type: "move"; delta: number }
    | { type: "customer"; customer: CustomerCard | null }
    | { type: "approval"; approval: Approval | null }
    | { type: "member"; member: MemberAuthState | null }
    | { type: "load"; state: CartState }
    | { type: "clear"; uuid?: string };

function lineFrom(variant: CatalogVariant, qty: number): CartLine {
    return {
        key: String(variant.id),
        productId: variant.id,
        name: variant.name,
        sku: variant.sku,
        label: variant.label,
        unitPrice: variant.price,
        qty,
        taxRateBp: variant.tax_rate_bp,
        allowsDecimal: variant.allows_decimal,
        stock: variant.stock,
        discount: NO_DISCOUNT,
    };
}

const clampIndex = (index: number, length: number): number =>
    length === 0 ? -1 : Math.max(0, Math.min(index, length - 1));

/**
 * The bill. Adding a product already on the bill adds to its quantity (one line per product, so a
 * size picked twice merges); the last touched line becomes the selected one.
 */
export function cartReducer(state: CartState, action: CartAction): CartState {
    switch (action.type) {
        case "add": {
            const lines = [...state.lines];
            let selected = state.selected;
            for (const { variant, qty } of action.items) {
                if (qty <= 0) {
                    continue;
                }
                const index = lines.findIndex(
                    (l) => l.productId === variant.id,
                );
                if (index >= 0) {
                    const line = lines[index] as CartLine;
                    lines[index] = { ...line, qty: line.qty + qty };
                    selected = index;
                } else {
                    lines.push(lineFrom(variant, qty));
                    selected = lines.length - 1;
                }
            }

            return { ...state, lines, selected };
        }
        case "qty": {
            if (action.qty <= 0) {
                return cartReducer(state, { type: "remove", key: action.key });
            }

            return {
                ...state,
                lines: state.lines.map((l) =>
                    l.key === action.key ? { ...l, qty: action.qty } : l,
                ),
            };
        }
        case "remove": {
            const lines = state.lines.filter((l) => l.key !== action.key);

            return {
                ...state,
                lines,
                selected: clampIndex(state.selected, lines.length),
            };
        }
        case "line_discount":
            return {
                ...state,
                lines: state.lines.map((l) =>
                    l.key === action.key
                        ? { ...l, discount: action.discount }
                        : l,
                ),
            };
        case "order_discount":
            return { ...state, orderDiscount: action.discount };
        case "select":
            return {
                ...state,
                selected: clampIndex(action.index, state.lines.length),
            };
        case "move":
            return {
                ...state,
                selected: clampIndex(
                    (state.selected < 0 ? 0 : state.selected) + action.delta,
                    state.lines.length,
                ),
            };
        case "customer":
            // A different customer (or the walk-in) starts the PIN step afresh.
            return {
                ...state,
                customer: action.customer,
                member:
                    action.customer !== null &&
                    state.member?.customerId === action.customer.id
                        ? state.member
                        : null,
            };
        case "member":
            return { ...state, member: action.member };
        case "approval":
            return { ...state, approval: action.approval };
        case "load":
            return action.state;
        case "clear":
            return emptyCart(action.uuid);
    }
}

/** The pricing engine's input for this bill: the same numbers the server re-prices with. */
export function cartTotals(
    state: Pick<CartState, "lines" | "orderDiscount" | "customer">,
    settings: Pick<RegisterSettings, "tax_inclusive" | "rounding_step">,
): CartTotals {
    return calculateCart({
        lines: state.lines.map((l) => ({
            unit_price: l.unitPrice,
            qty_milli: l.qty,
            line_discount: l.discount,
            tax_rate_bp: l.taxRateBp,
            tax_inclusive: settings.tax_inclusive,
        })),
        order_discount: state.orderDiscount,
        group_discount_bp: state.customer?.group?.discount_bp ?? 0,
        rounding_step: settings.rounding_step,
        tenders: [],
    });
}

/** Units on the bill (milli-units summed, whole units for display). */
export function totalQty(lines: readonly CartLine[]): number {
    return lines.reduce((sum, l) => sum + l.qty, 0);
}

/**
 * Manual discount (line + share of the bill discount) above the cap on any line: a hint that a
 * manager must approve. The server decides; this only warns before Pay.
 */
export function exceedsCap(totals: CartTotals, capBp: number): boolean {
    return totals.lines.some((line) => {
        const manual = line.line_discount + line.order_discount;
        if (manual === 0 || line.gross === 0) {
            return false;
        }

        return manual * 10_000 > line.gross * capBp;
    });
}

export const ONE_UNIT = QTY_SCALE;
