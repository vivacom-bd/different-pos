import { Head, router, usePage } from '@inertiajs/react';
import { FileText, Printer, Receipt, Truck, Undo2 } from 'lucide-react';
import { toast } from 'sonner';
import { useMemo, useState } from 'react';
import {
    ColumnChooser,
    DataTable,
    useColumnVisibility,
    type BulkAction,
    type DataColumn,
    type TableMeta,
} from '@/components/data-table/data-table';
import {
    buildTableQuery,
    useServerTable,
} from '@/components/data-table/server-table';
import { DateRangePicker } from '@/components/date-range-picker';
import { EmptyState } from '@/components/empty-state';
import {
    ExportMenu,
    Page,
    PageHeader,
    PrintButton,
} from '@/components/page-header';
import { CollectDialog } from '@/components/sales/collect-dialog';
import { DateTime } from '@/components/sales/date-time';
import { FiltersDialog } from '@/components/sales/filters-dialog';
import {
    DATE_PRESETS,
    presetOf,
    presetRange,
    rowOpenHandlers,
    type DatePreset,
} from '@/components/sales/list-tools';
import {
    SaleInvoiceDialog,
    type SaleDetail,
} from '@/components/sales/sale-invoice-dialog';
import { VoidSaleDialog } from '@/components/sales/void-dialog';
import { StatusBadge } from '@/components/status-badge';
import { TableToolbar } from '@/components/table-toolbar';
import { Button } from '@/components/ui/button';
import { navigateTo } from '@/command/navigate';
import { moneyToDecimalString } from '@/engine/money';
import { useCan } from '@/hooks/use-can';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { DownloadError, downloadFile } from '@/lib/download';
import { exportItems, exportUrl, type ExportFormat } from '@/lib/export-items';
import { downloadRowsCsv, type CsvColumn } from '@/lib/rows-csv';
import {
    defaultPaper,
    openPrint,
    salesUrl,
    type CustomerSummary,
    type MoneyMethod,
} from '@/lib/sales';
import { registerSaleActions } from '@/lib/sales-actions';

registerSaleActions();

type Row = {
    id: number;
    number: string;
    sold_at: string | null;
    customer: CustomerSummary | null;
    cashier: string | null;
    register: { name: string; code: string } | null;
    items: number;
    discount: number;
    vat: number;
    total: number;
    paid: number;
    due: number;
    returned: number;
    status: string;
    payment_status: string;
    flags: string[];
    voidable: boolean;
    exchange: boolean;
};

type Totals = {
    items: number;
    discount: number;
    vat: number;
    total: number;
    paid: number;
    due: number;
};

type Props = {
    rows: Row[];
    meta: TableMeta;
    totals: Totals;
    filters: Record<string, string>;
    cashiers: { id: number; name: string }[];
    customer: CustomerSummary | null;
    branch: { name: string; code: string };
    methods: MoneyMethod[];
    detail: SaleDetail | null;
};

const MORE_FILTERS = ['cashier', 'sync', 'returns'] as const;

export default function SalesIndex({
    rows,
    meta,
    totals,
    filters,
    cashiers,
    customer,
    branch,
    methods,
    detail,
}: Props) {
    const { t } = useT();
    const digits = useDigits();
    const can = useCan();
    const branchPaper = usePage().props.auth.branch_paper;
    const table = useServerTable();
    const url = usePage().url;
    const query = url.includes('?') ? url.slice(url.indexOf('?')) : '';
    const [voiding, setVoiding] = useState<Row | null>(null);
    const [collecting, setCollecting] = useState<Row | null>(null);
    const [moreOpen, setMoreOpen] = useState(false);
    const [customRange, setCustomRange] = useState(false);

    const setFilters = (next: Record<string, string>) => {
        router.get(
            salesUrl.index,
            buildTableQuery({
                ...table.state,
                filters: { ...table.state.filters, ...next },
                page: 1,
            }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // The invoice dialog: a partial reload of `detail` only; the URL is the sale's deep link.
    const openSale = (id: number) =>
        router.get(
            `${salesUrl.show(id)}${query}`,
            {},
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    const closeSale = () =>
        router.get(
            `${salesUrl.index}${query}`,
            {},
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const csvColumns: CsvColumn<Row>[] = [
        { header: t('sales.col_number'), value: (r) => r.number },
        { header: t('sales.col_date'), value: (r) => r.sold_at ?? '' },
        {
            header: t('sales.col_customer'),
            value: (r) =>
                r.customer && !r.customer.walk_in
                    ? r.customer.name
                    : t('sales.walk_in'),
        },
        {
            header: t('sales.col_customer_no'),
            value: (r) => r.customer?.customer_no ?? '',
        },
        { header: t('sales.col_items'), value: (r) => String(r.items) },
        {
            header: t('sales.col_discount'),
            value: (r) => moneyToDecimalString(r.discount),
        },
        { header: t('sales.vat'), value: (r) => moneyToDecimalString(r.vat) },
        {
            header: t('sales.col_total'),
            value: (r) => moneyToDecimalString(r.total),
        },
        {
            header: t('sales.col_paid'),
            value: (r) => moneyToDecimalString(r.paid),
        },
        {
            header: t('sales.col_due'),
            value: (r) => moneyToDecimalString(r.due),
        },
        { header: t('common.status'), value: (r) => statusOf(r) },
        { header: t('sales.col_cashier'), value: (r) => r.cashier ?? '' },
    ];
    const exportRows = (list: Row[]) =>
        downloadRowsCsv('sales.csv', csvColumns, list);
    const exportAs = (format: ExportFormat) => {
        downloadFile(
            exportUrl('/app/sales', format, window.location.search),
        ).catch((error: unknown) =>
            toast.error(
                error instanceof DownloadError && error.status === 422
                    ? error.message
                    : t('table.export_failed'),
            ),
        );
    };

    useHotkeys('list', [
        {
            id: 'sales.search',
            keys: '/',
            labelKey: 'hotkeys.search',
            handler: (e) => {
                e.preventDefault();
                document.getElementById('page-search')?.focus();
            },
        },
        {
            id: 'sales.new_return',
            keys: 'shift+r',
            labelKey: 'returns.new',
            handler: () =>
                can('returns.create') && navigateTo(salesUrl.returnCreate()),
        },
        {
            id: 'sales.export',
            keys: 'shift+e',
            labelKey: 'table.export_xlsx',
            handler: () => exportAs('xlsx'),
        },
        {
            id: 'sales.more_filters',
            keys: 'shift+f',
            labelKey: 'table.more_filters',
            handler: () => setMoreOpen(true),
        },
    ]);

    const columns = useMemo<DataColumn<Row>[]>(
        () => [
            {
                id: 'number',
                headerKey: 'sales.col_number',
                sortKey: 'number',
                required: true,
                mobile: 'title',
                cell: (r) => (
                    <span className="num font-mono font-bold text-primary">
                        {r.number}
                    </span>
                ),
            },
            {
                id: 'date',
                headerKey: 'sales.col_date',
                sortKey: 'sold_at',
                cell: (r) => <DateTime value={r.sold_at} />,
            },
            {
                id: 'customer',
                headerKey: 'sales.col_customer',
                cell: (r) =>
                    r.customer && !r.customer.walk_in ? (
                        <div>
                            <div className="font-semibold text-foreground">
                                {r.customer.name}
                            </div>
                            {r.customer.customer_no ? (
                                <div className="num font-mono text-[10px] text-primary">
                                    {digits.n(r.customer.customer_no)}
                                </div>
                            ) : r.customer.shop_name ? (
                                <div className="text-[10px] text-muted-foreground">
                                    {r.customer.shop_name}
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <span className="font-semibold">
                            {t('sales.walk_in')}
                        </span>
                    ),
            },
            {
                id: 'items',
                headerKey: 'sales.col_items',
                align: 'center',
                cell: (r) => <span className="num">{digits.n(r.items)}</span>,
            },
            {
                id: 'discount',
                headerKey: 'sales.col_discount',
                numeric: true,
                cell: (r) => digits.money(r.discount),
            },
            {
                id: 'vat',
                headerKey: 'sales.vat',
                numeric: true,
                cell: (r) => digits.money(r.vat),
            },
            {
                id: 'branch',
                headerKey: 'sales.col_branch',
                cell: () => `${branch.name} [${branch.code}]`,
            },
            {
                id: 'register',
                headerKey: 'sales.col_register',
                cell: (r) =>
                    r.register
                        ? r.register.name === r.register.code
                            ? r.register.name
                            : `${r.register.name} [${r.register.code}]`
                        : '—',
            },
            {
                id: 'total',
                headerKey: 'sales.col_total',
                sortKey: 'total',
                numeric: true,
                cell: (r) => (
                    <span className="font-bold text-foreground">
                        {digits.money(r.total)}
                    </span>
                ),
            },
            {
                id: 'paid',
                headerKey: 'sales.col_paid',
                sortKey: 'paid',
                numeric: true,
                cell: (r) => (
                    <span className="font-semibold text-success">
                        {digits.money(r.paid)}
                    </span>
                ),
            },
            {
                id: 'due',
                headerKey: 'sales.col_due',
                sortKey: 'due',
                numeric: true,
                cell: (r) => (
                    <span className="font-semibold text-destructive">
                        {r.due > 0 ? digits.money(r.due) : '—'}
                    </span>
                ),
            },
            {
                id: 'status',
                headerKey: 'common.status',
                align: 'center',
                cell: (r) => (
                    <span className="inline-flex flex-wrap justify-center gap-1">
                        <StatusBadge status={statusOf(r)} />
                        {r.returned > 0 ? (
                            <StatusBadge
                                status="returned"
                                tone="info"
                                label={t('sales.has_returns')}
                            />
                        ) : null}
                        {r.exchange ? (
                            <StatusBadge
                                status="exchange"
                                tone="accent"
                                label={t('sales.exchange_sale')}
                            />
                        ) : null}
                    </span>
                ),
            },
            {
                id: 'cashier',
                headerKey: 'sales.col_cashier',
                cell: (r) => r.cashier ?? '—',
            },
            {
                id: 'sync',
                headerKey: 'sales.col_sync',
                cell: (r) =>
                    r.flags.length > 0 ? (
                        <span className="text-[10px] font-bold text-destructive">
                            {t('sales.sync_flagged', {
                                flags: r.flags
                                    .map((f) => t(`sales.flag_${f}`))
                                    .join(', '),
                            })}
                        </span>
                    ) : (
                        <span className="text-[10px] font-medium text-muted-foreground">
                            {t('sales.sync_synced')}
                        </span>
                    ),
            },
            {
                id: 'actions',
                headerKey: 'common.actions',
                required: true,
                mobile: 'hidden',
                cell: (r) => (
                    <span className="flex items-center justify-end gap-1">
                        {can('returns.create') && r.status === 'completed' ? (
                            <button
                                type="button"
                                className="rounded-sm p-1 text-warning hover:bg-warning-soft [@media(pointer:coarse)]:min-h-11 [@media(pointer:coarse)]:min-w-11"
                                title={t('sales.return_from', {
                                    number: r.number,
                                })}
                                aria-label={t('sales.return_from', {
                                    number: r.number,
                                })}
                                onClick={() =>
                                    navigateTo(salesUrl.returnCreate(r.id))
                                }
                            >
                                <Undo2
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            </button>
                        ) : null}
                        <button
                            type="button"
                            className="rounded-sm p-1 text-muted-foreground hover:bg-surface-sunken hover:text-foreground [@media(pointer:coarse)]:min-h-11 [@media(pointer:coarse)]:min-w-11"
                            title={t('sales.reprint', { number: r.number })}
                            aria-label={t('sales.reprint', {
                                number: r.number,
                            })}
                            onClick={() =>
                                openPrint(
                                    salesUrl.receipt(
                                        r.id,
                                        defaultPaper(branchPaper),
                                    ),
                                )
                            }
                        >
                            <Printer className="size-3.5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            className="rounded-sm p-1 text-muted-foreground hover:bg-surface-sunken hover:text-foreground [@media(pointer:coarse)]:min-h-11 [@media(pointer:coarse)]:min-w-11"
                            title={t('sales.chalan_of', { number: r.number })}
                            aria-label={t('sales.chalan_of', {
                                number: r.number,
                            })}
                            onClick={() => openPrint(salesUrl.chalan(r.id))}
                        >
                            <Truck className="size-3.5" aria-hidden="true" />
                        </button>
                    </span>
                ),
            },
        ],
        [digits, t, branch, branchPaper, can],
    );
    const columnState = useColumnVisibility(columns);

    const handlers = {
        ...(can('sales.void')
            ? { 'sale.void': (r: Row) => setVoiding(r) }
            : {}),
        ...(can('payments.collect')
            ? { 'sale.collect': (r: Row) => setCollecting(r) }
            : {}),
        ...(can('returns.create') && can('pos.sell')
            ? {
                  'sale.exchange': (r: Row) =>
                      navigateTo(salesUrl.exchangeCreate(r.id)),
              }
            : {}),
    };

    const bulkActions: BulkAction<Row>[] = [
        {
            id: 'export',
            labelKey: 'sales.export_selected',
            icon: FileText,
            run: exportRows,
        },
    ];

    // Mockup status chips: All · Paid · Partial · Due · Void, over the two server filters.
    const chipValue =
        filters['status'] === 'voided'
            ? 'voided'
            : (filters['payment_status'] ?? '');
    const chipOptions = [
        { value: '', label: t('sales.all_statuses') },
        { value: 'paid', label: t('status.paid') },
        { value: 'partial', label: t('status.partial') },
        { value: 'unpaid', label: t('status.due') },
        { value: 'voided', label: t('status.void') },
    ];
    const setChip = (value: string) =>
        setFilters(
            value === 'voided'
                ? { status: 'voided', payment_status: '' }
                : {
                      status: value === '' ? '' : 'completed',
                      payment_status: value,
                  },
        );

    // Saved views (mockup): Default · Unpaid & due · Flagged sync.
    const view =
        filters['sync'] === 'flagged'
            ? 'flagged'
            : filters['payment_status'] === 'unpaid'
              ? 'due'
              : '';
    const setView = (value: string) =>
        setFilters({
            sync: value === 'flagged' ? 'flagged' : '',
            status: value === 'due' ? 'completed' : '',
            payment_status: value === 'due' ? 'unpaid' : '',
        });

    const from = filters['from'] ?? '';
    const to = filters['to'] ?? '';
    const preset: DatePreset = customRange ? 'custom' : presetOf(from, to);
    const setPreset = (value: DatePreset) => {
        setCustomRange(value === 'custom');
        if (value !== 'custom') {
            setFilters(presetRange(value));
        }
    };

    const moreCount = MORE_FILTERS.filter((k) => filters[k]).length;

    return (
        <>
            <Head
                title={
                    detail
                        ? `${t('sales.sale')} ${detail.sale.number}`
                        : t('sales.title')
                }
            />
            <Page fill>
                <PageHeader
                    title={t('sales.title')}
                    count={meta.total}
                    subtitle={t('sales.subtitle')}
                    actions={
                        <>
                            <ColumnChooser
                                columns={columns}
                                visibility={columnState.visibility}
                                onChange={columnState.setVisibility}
                            />
                            <ExportMenu items={exportItems(t, exportAs)} />
                            <PrintButton />
                        </>
                    }
                />
                <TableToolbar
                    search={table.searchText}
                    onSearch={table.setSearch}
                    placeholderKey="sales.search"
                    view={{
                        value: view,
                        options: [
                            { value: '', label: t('sales.view_default') },
                            { value: 'due', label: t('sales.view_due') },
                            {
                                value: 'flagged',
                                label: t('sales.view_flagged'),
                            },
                        ],
                        onChange: setView,
                    }}
                    date={{
                        value: preset,
                        options: DATE_PRESETS.map((p) => ({
                            value: p,
                            label: t(`sales.date_${p || 'all'}`),
                        })),
                        onChange: (v) => setPreset(v as DatePreset),
                    }}
                    dateControl={
                        preset === 'custom' ? (
                            <DateRangePicker
                                value={{ from, to }}
                                onChange={(range) =>
                                    setFilters({
                                        from: range.from,
                                        to: range.to,
                                    })
                                }
                            />
                        ) : undefined
                    }
                    chips={[
                        {
                            key: 'status',
                            label: t('common.status'),
                            value: chipValue,
                            options: chipOptions,
                            onChange: setChip,
                        },
                    ]}
                    moreFilters={{
                        count: moreCount,
                        onOpen: () => setMoreOpen(true),
                    }}
                >
                    {customer ? (
                        <Button
                            type="button"
                            variant="secondary"
                            aria-label={t('sales.clear_customer', {
                                name: customer.name,
                            })}
                            onClick={() => table.setFilter('customer', '')}
                        >
                            {customer.name} ×
                        </Button>
                    ) : null}
                </TableToolbar>
                <div
                    className="flex min-h-0 flex-1 flex-col"
                    {...rowOpenHandlers(rows, (r) => openSale(r.id))}
                >
                    <DataTable<Row>
                        entity="sale"
                        caption={t('sales.title')}
                        rows={rows}
                        columns={columns}
                        meta={meta}
                        getRowId={(r) => String(r.id)}
                        rowHref={(r) => `${salesUrl.show(r.id)}${query}`}
                        handlers={handlers}
                        bulkActions={bulkActions}
                        sort={table.state.sort}
                        onSort={table.toggleSort}
                        onPage={table.setPage}
                        loading={table.loading}
                        chooser="none"
                        visibility={columnState.visibility}
                        onVisibilityChange={columnState.setVisibility}
                        onPerPage={table.setPerPage}
                        fill
                        totals={{
                            cells: {
                                items: (
                                    <span className="num">
                                        {digits.n(totals.items)}
                                    </span>
                                ),
                                discount: digits.money(totals.discount),
                                vat: digits.money(totals.vat),
                                total: digits.money(totals.total),
                                paid: digits.money(totals.paid),
                                due: digits.money(totals.due),
                            },
                        }}
                        empty={
                            <EmptyState
                                icon={Receipt}
                                title={t('sales.empty')}
                                description={t('sales.empty_hint')}
                            />
                        }
                    />
                </div>
            </Page>
            {detail ? (
                <SaleInvoiceDialog
                    key={detail.sale.id}
                    detail={detail}
                    methods={methods}
                    onClose={closeSale}
                />
            ) : null}
            <FiltersDialog
                open={moreOpen}
                onOpenChange={setMoreOpen}
                values={filters}
                onApply={setFilters}
                fields={[
                    {
                        key: 'cashier',
                        label: t('sales.col_cashier'),
                        options: [
                            { value: '', label: t('sales.all_cashiers') },
                            ...cashiers.map((c) => ({
                                value: String(c.id),
                                label: c.name,
                            })),
                        ],
                    },
                    {
                        key: 'sync',
                        label: t('sales.col_sync'),
                        options: [
                            { value: '', label: t('sales.sync_all') },
                            { value: 'synced', label: t('sales.sync_synced') },
                            {
                                value: 'flagged',
                                label: t('sales.sync_flagged_only'),
                            },
                        ],
                    },
                    {
                        key: 'returns',
                        label: t('sales.has_return_filter'),
                        options: [
                            { value: '', label: t('sales.returns_all') },
                            { value: 'yes', label: t('sales.returns_yes') },
                            { value: 'no', label: t('sales.returns_no') },
                        ],
                    },
                ]}
            />
            <VoidSaleDialog
                sale={voiding}
                onOpenChange={(open) => !open && setVoiding(null)}
            />
            {collecting ? (
                <CollectDialog
                    open
                    onOpenChange={(open) => !open && setCollecting(null)}
                    action={salesUrl.payments(collecting.id)}
                    title={t('sales.collect_title', {
                        number: collecting.number,
                    })}
                    invoices={[
                        {
                            id: collecting.id,
                            number: collecting.number,
                            sold_at: collecting.sold_at,
                            due: collecting.due,
                        },
                    ]}
                    methods={methods}
                />
            ) : null}
        </>
    );
}

function statusOf(r: { status: string; payment_status: string }): string {
    return r.status === 'completed' ? r.payment_status : r.status;
}
