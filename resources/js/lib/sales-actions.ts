import {
    Ban,
    Copy,
    FileText,
    HandCoins,
    Printer,
    Truck,
    Repeat,
    Undo2,
} from 'lucide-react';
import { actions, type AppAction } from '@/command/actions';
import { branchPaper, openPrint, salesUrl } from '@/lib/sales';

/** What a sale row needs for its context menu. */
export type SaleRowLike = {
    id: number;
    number: string;
    status: string;
    due: number;
    voidable: boolean;
    customer: { walk_in: boolean } | null;
};

/**
 * Sale row actions (table rows, the detail page and the palette share them). Void, collect and
 * exchange are surface handlers: the page decides (permission + state) and opens its dialog.
 */
const saleActions: AppAction<SaleRowLike>[] = [
    {
        id: 'sale.receipt',
        entity: 'sale',
        labelKey: 'sales.print_receipt',
        icon: Printer,
        keys: 'p',
        permission: 'sales.view',
        run: ({ row }) => openPrint(salesUrl.receipt(row.id, branchPaper())),
    },
    {
        id: 'sale.invoice',
        entity: 'sale',
        labelKey: 'sales.print_a4',
        icon: FileText,
        keys: 'shift+p',
        permission: 'sales.view',
        run: ({ row }) => openPrint(salesUrl.receipt(row.id, 'a4')),
    },
    {
        id: 'sale.chalan',
        entity: 'sale',
        labelKey: 'sales.chalan',
        icon: Truck,
        keys: 'd',
        permission: 'sales.view',
        run: ({ row }) => openPrint(salesUrl.chalan(row.id)),
    },
    {
        id: 'sale.collect',
        entity: 'sale',
        labelKey: 'sales.collect_due',
        icon: HandCoins,
        keys: 'c',
        permission: 'payments.collect',
        requires: 'handler',
        visible: (row) =>
            row.status === 'completed' &&
            row.due > 0 &&
            row.customer !== null &&
            !row.customer.walk_in,
        run: ({ row, handlers }) => handlers?.['sale.collect']?.(row),
    },
    {
        id: 'sale.return',
        entity: 'sale',
        labelKey: 'sales.return',
        icon: Undo2,
        keys: 'r',
        permission: 'returns.create',
        visible: (row) => row.status === 'completed',
        run: ({ row, navigate }) => navigate(salesUrl.returnCreate(row.id)),
    },
    {
        id: 'sale.exchange',
        entity: 'sale',
        labelKey: 'sales.exchange',
        icon: Repeat,
        keys: 'x',
        permission: 'returns.create',
        requires: 'handler',
        visible: (row) => row.status === 'completed',
        run: ({ row, handlers }) => handlers?.['sale.exchange']?.(row),
    },
    {
        id: 'sale.copy_number',
        entity: 'sale',
        labelKey: 'sales.copy_number',
        icon: Copy,
        group: 'copy',
        run: ({ row }) => void navigator.clipboard.writeText(row.number),
    },
    {
        id: 'sale.void',
        entity: 'sale',
        labelKey: 'sales.void',
        icon: Ban,
        keys: 'v',
        permission: 'sales.void',
        group: 'danger',
        requires: 'handler',
        visible: (row) => row.voidable,
        run: ({ row, handlers }) => handlers?.['sale.void']?.(row),
    },
];

let registered = false;

/** Idempotent; called by the sales pages. */
export function registerSaleActions(): void {
    if (registered) {
        return;
    }
    registered = true;
    actions.register(...saleActions);
}
