import { mulDivHalfUp, type Paisa } from '@/engine/money';

/** Shapes shared by the sales, returns and dues pages (money in paisa, quantities in milli-units). */
export type CustomerSummary = {
    id: number;
    uuid: string;
    name: string;
    shop_name: string | null;
    customer_no: string | null;
    phone: string | null;
    walk_in: boolean;
};

export type MoneyMethod = {
    id: number;
    name: string;
    type: string;
    requires_reference: boolean;
};

export type Paper = '80' | '58' | 'a4';

export const salesUrl = {
    index: '/app/sales',
    show: (id: number) => `/app/sales/${id}`,
    void: (id: number) => `/app/sales/${id}/void`,
    payments: (id: number) => `/app/sales/${id}/payments`,
    share: (id: number) => `/app/sales/${id}/share`,
    receipt: (id: number, paper: Paper = 'a4') =>
        `/app/sales/${id}/receipt?paper=${paper}`,
    chalan: (id: number) => `/app/sales/${id}/chalan`,
    returnsIndex: '/app/returns',
    returnCreate: (saleId?: number) =>
        saleId ? `/app/returns/create?sale=${saleId}` : '/app/returns/create',
    exchangeCreate: (saleId?: number) =>
        saleId
            ? `/app/exchanges/create?sale=${saleId}`
            : '/app/exchanges/create',
    returnShow: (id: number) => `/app/returns/${id}`,
    returnReceipt: (id: number, paper: Paper = 'a4') =>
        `/app/returns/${id}/receipt?paper=${paper}`,
    dues: '/app/dues',
    dueShow: (uuid: string) => `/app/dues/${uuid}`,
    duePayments: (uuid: string) => `/app/dues/${uuid}/payments`,
    paymentReceipt: (id: number, paper: Paper = 'a4') =>
        `/app/payments/${id}/receipt?paper=${paper}`,
    customer: (uuid: string) => `/app/customers/${uuid}`,
};

/** Opens a print view in a new window (the view has its own Print button; `print=1` prints on load). */
export function openPrint(url: string, autoprint = true): void {
    const target = autoprint
        ? `${url}${url.includes('?') ? '&' : '?'}print=1`
        : url;
    window.open(target, '_blank', 'noopener');
}

/** Estimated value of returning `qty` of a line: its payable value (after the order discount) prorated by quantity. */
export function returnValue(
    payable: Paisa,
    qty: number,
    soldQty: number,
): Paisa {
    if (soldQty <= 0 || qty <= 0) {
        return 0;
    }

    return mulDivHalfUp(payable, qty, soldQty);
}
