import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    FileText,
    Package,
    Plus,
    Receipt,
    Truck,
    Users,
} from 'lucide-react';
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
import { KpiTile } from '@/components/kpi-tile';
import {
    ExportMenu,
    Page,
    PageHeader,
    PrintButton,
} from '@/components/page-header';
import { FiltersDialog } from '@/components/sales/filters-dialog';
import {
    DATE_PRESETS,
    presetOf,
    presetRange,
    type DatePreset,
} from '@/components/sales/list-tools';
import { StatusBadge } from '@/components/status-badge';
import { TableToolbar } from '@/components/table-toolbar';
import { Button } from '@/components/ui/button';
import { moneyToDecimalString } from '@/engine/money';
import { qtyToDecimalString } from '@/engine/quantity';
import { useCan } from '@/hooks/use-can';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { DownloadError, downloadFile } from '@/lib/download';
import { exportItems, exportUrl, type ExportFormat } from '@/lib/export-items';
import { downloadRowsCsv, type CsvColumn } from '@/lib/rows-csv';

type Row = {
    id: number;
    uuid: string;
    number: string;
    vendor: string | null;
    vendor_ref: string | null;
    status: string;
    payment_status: string;
    purchased_at: string;
    branch: { name: string; code: string } | null;
    current_branch: boolean;
    items: number;
    /** Milli-units. */
    qty: number;
    total: number;
    paid: number;
    due: number;
};

type Totals = {
    bills: number;
    total: number;
    paid: number;
    due: number;
    qty: number;
    vendors_owing: number;
};

type BranchOption = {
    id: number;
    code: string;
    name: string;
    current: boolean;
};

const MORE_FILTERS = ['vendor'] as const;

export default function PurchasesIndex({
    rows,
    meta,
    totals,
    branches,
    vendors,
}: {
    rows: Row[];
    meta: TableMeta;
    totals: Totals;
    branches: BranchOption[];
    vendors: { id: number; name: string }[];
}) {
    const { t } = useT();
    const digits = useDigits();
    const can = useCan();
    const table = useServerTable();
    const filters = table.state.filters;
    const [moreOpen, setMoreOpen] = useState(false);
    const [customRange, setCustomRange] = useState(false);

    const setFilters = (next: Record<string, string>) => {
        router.get(
            '/app/purchases',
            buildTableQuery({
                ...table.state,
                filters: { ...table.state.filters, ...next },
                page: 1,
            }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const csvColumns: CsvColumn<Row>[] = [
        { header: t('purchases.col_grn'), value: (r) => r.number },
        { header: t('purchases.col_date'), value: (r) => r.purchased_at },
        {
            header: t('purchases.vendor'),
            value: (r) => r.vendor ?? t('purchases.no_vendor'),
        },
        {
            header: t('purchases.col_branch'),
            value: (r) => r.branch?.name ?? '',
        },
        {
            header: t('purchases.col_bill_ref'),
            value: (r) => r.vendor_ref ?? '',
        },
        {
            header: t('purchases.col_qty'),
            value: (r) => qtyToDecimalString(r.qty),
        },
        {
            header: t('purchases.col_total_bill'),
            value: (r) => moneyToDecimalString(r.total),
        },
        {
            header: t('purchases.col_paid'),
            value: (r) => moneyToDecimalString(r.paid),
        },
        {
            header: t('purchases.col_balance'),
            value: (r) => moneyToDecimalString(r.due),
        },
        { header: t('common.status'), value: (r) => statusOf(r) },
    ];
    const exportAs = (format: ExportFormat) => {
        downloadFile(
            exportUrl('/app/purchases', format, window.location.search),
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
            id: 'purchases.new',
            keys: 'n',
            labelKey: 'hotkeys.new',
            handler: () =>
                can('purchases.create') &&
                router.visit('/app/purchases/create'),
        },
        {
            id: 'purchases.search',
            keys: '/',
            labelKey: 'hotkeys.search',
            handler: (e) => {
                e.preventDefault();
                document.getElementById('page-search')?.focus();
            },
        },
        {
            id: 'purchases.export',
            keys: 'shift+e',
            labelKey: 'table.export_xlsx',
            handler: () => exportAs('xlsx'),
        },
        {
            id: 'purchases.more_filters',
            keys: 'shift+f',
            labelKey: 'table.more_filters',
            handler: () => setMoreOpen(true),
        },
    ]);

    const columns = useMemo<DataColumn<Row>[]>(
        () => [
            {
                id: 'number',
                headerKey: 'purchases.col_grn',
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
                headerKey: 'purchases.col_date',
                sortKey: 'purchased_at',
                cell: (r) => (
                    <span className="num whitespace-nowrap text-muted-foreground">
                        {digits.n(r.purchased_at)}
                    </span>
                ),
            },
            {
                id: 'vendor',
                headerKey: 'purchases.vendor',
                cell: (r) => (
                    <span className="font-semibold text-foreground">
                        {r.vendor ?? (
                            <span className="font-normal text-muted-foreground">
                                {t('purchases.no_vendor')}
                            </span>
                        )}
                    </span>
                ),
            },
            {
                id: 'branch',
                headerKey: 'purchases.col_branch',
                cell: (r) => (
                    <span className="text-muted-foreground">
                        {r.branch ? `${r.branch.name} [${r.branch.code}]` : '—'}
                    </span>
                ),
            },
            {
                id: 'bill_ref',
                headerKey: 'purchases.col_bill_ref',
                sortKey: 'vendor_ref',
                cell: (r) => (
                    <span className="num font-mono text-muted-foreground">
                        {r.vendor_ref ? digits.n(r.vendor_ref) : '—'}
                    </span>
                ),
            },
            {
                id: 'qty',
                headerKey: 'purchases.col_qty',
                numeric: true,
                cell: (r) => (
                    <span className="num rounded-sm border bg-surface-sunken px-2 py-0.5 font-mono font-semibold whitespace-nowrap text-foreground">
                        {t('purchases.qty_cell', {
                            items: digits.n(r.items),
                            qty: digits.qty(r.qty),
                        })}
                    </span>
                ),
            },
            {
                id: 'total',
                headerKey: 'purchases.col_total_bill',
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
                headerKey: 'purchases.col_paid',
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
                headerKey: 'purchases.col_balance',
                sortKey: 'due',
                numeric: true,
                cell: (r) =>
                    r.status === 'cancelled' ? (
                        <StatusBadge status="cancelled" />
                    ) : r.due > 0 ? (
                        <span className="font-semibold text-destructive">
                            {digits.money(r.due)}
                        </span>
                    ) : (
                        <span className="font-semibold text-success">
                            {t('purchases.paid_zero', {
                                amount: digits.money(0),
                            })}
                        </span>
                    ),
            },
            {
                id: 'status',
                headerKey: 'common.status',
                align: 'center',
                hidden: true,
                cell: (r) => <StatusBadge status={statusOf(r)} />,
            },
            {
                id: 'actions',
                headerKey: 'common.actions',
                required: true,
                mobile: 'hidden',
                cell: (r) =>
                    r.current_branch ? (
                        <span className="flex justify-end">
                            <Link
                                href={`/app/purchases/${r.uuid}`}
                                className="rounded-sm bg-primary-soft px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap text-primary transition-colors hover:bg-primary hover:text-primary-foreground [@media(pointer:coarse)]:min-h-11"
                            >
                                {t('purchases.view_items')}
                            </Link>
                        </span>
                    ) : null,
            },
        ],
        [digits, t],
    );
    const columnState = useColumnVisibility(columns);
    const bulkActions: BulkAction<Row>[] = [
        {
            id: 'export',
            labelKey: 'purchases.export_selected',
            icon: FileText,
            run: (list) => downloadRowsCsv('purchases.csv', csvColumns, list),
        },
    ];

    const chipValue =
        filters['status'] === 'cancelled'
            ? 'cancelled'
            : (filters['payment'] ?? '');
    const setChip = (value: string) =>
        setFilters(
            value === 'cancelled'
                ? { status: 'cancelled', payment: '' }
                : { status: value === '' ? '' : 'received', payment: value },
        );
    const view =
        filters['payment'] === 'due' && filters['status'] === 'received'
            ? 'unpaid'
            : '';
    const setView = (value: string) =>
        setFilters({
            status: value === 'unpaid' ? 'received' : '',
            payment: value === 'unpaid' ? 'due' : '',
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

    // All-branches users start on "All Branches" (the server default when no branch filter is given).
    const branchValue = filters['branch'] ?? 'all';
    const branchChips = branches.map((b) => ({
        value: String(b.id),
        label: `${b.name} [${b.code}]`,
    }));
    const moreCount = MORE_FILTERS.filter((k) => filters[k]).length;

    return (
        <>
            <Head title={t('purchases.title')} />
            <Page fill>
                <div className="grid shrink-0 grid-cols-2 gap-3 xl:grid-cols-4">
                    <KpiTile
                        label={t('purchases.kpi_bills')}
                        icon={Receipt}
                        value={digits.money(totals.total)}
                        footnote={t('purchases.kpi_bills_note', {
                            n: digits.n(totals.bills),
                        })}
                    />
                    <KpiTile
                        label={t('purchases.kpi_items')}
                        icon={Package}
                        value={
                            <span className="font-mono text-primary">
                                {t('purchases.kpi_items_value', {
                                    qty: digits.qty(totals.qty),
                                })}
                            </span>
                        }
                        footnote={t('purchases.kpi_items_note')}
                    />
                    <KpiTile
                        label={t('purchases.kpi_paid')}
                        icon={Banknote}
                        value={
                            <span className="text-success">
                                {digits.money(totals.paid)}
                            </span>
                        }
                        footnote={
                            <span className="text-success">
                                {t('purchases.kpi_paid_note')}
                            </span>
                        }
                    />
                    <KpiTile
                        label={t('purchases.kpi_due')}
                        icon={Users}
                        value={
                            <span className="text-destructive">
                                {digits.money(totals.due)}
                            </span>
                        }
                        footnote={
                            <span className="font-semibold text-destructive">
                                {t('purchases.kpi_due_note', {
                                    n: digits.n(totals.vendors_owing),
                                })}
                            </span>
                        }
                    />
                </div>
                <PageHeader
                    title={t('purchases.list_title')}
                    count={meta.total}
                    subtitle={t('purchases.subtitle')}
                    actions={
                        <>
                            {can('purchases.create') ? (
                                <Button asChild>
                                    <Link href="/app/purchases/create">
                                        <Plus aria-hidden="true" />
                                        {t('purchases.new_grn')}
                                    </Link>
                                </Button>
                            ) : null}
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
                    placeholderKey="purchases.search"
                    view={{
                        value: view,
                        options: [
                            { value: '', label: t('purchases.view_default') },
                            {
                                value: 'unpaid',
                                label: t('purchases.view_unpaid'),
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
                        ...(branches.length > 1
                            ? [
                                  {
                                      key: 'branch',
                                      label: t('purchases.col_branch'),
                                      value: branchValue,
                                      options: [
                                          {
                                              value: 'all',
                                              label: t(
                                                  'purchases.all_branches',
                                              ),
                                          },
                                          ...branchChips,
                                      ],
                                      onChange: (v: string) =>
                                          setFilters({ branch: v }),
                                  },
                              ]
                            : []),
                        {
                            key: 'status',
                            label: t('common.status'),
                            value: chipValue,
                            options: [
                                {
                                    value: '',
                                    label: t('purchases.all_statuses'),
                                },
                                { value: 'paid', label: t('status.paid') },
                                {
                                    value: 'partial',
                                    label: t('status.partial'),
                                },
                                { value: 'unpaid', label: t('status.unpaid') },
                                {
                                    value: 'cancelled',
                                    label: t('status.cancelled'),
                                },
                            ],
                            onChange: setChip,
                        },
                    ]}
                    moreFilters={{
                        count: moreCount,
                        onOpen: () => setMoreOpen(true),
                    }}
                />
                <DataTable<Row>
                    entity="purchase"
                    caption={t('purchases.title')}
                    rows={rows}
                    columns={columns}
                    meta={meta}
                    getRowId={(r) => r.uuid}
                    // Other branches' bills are read-only here: their detail page belongs to that branch.
                    rowHref={(r: Row) =>
                        r.current_branch
                            ? `/app/purchases/${r.uuid}`
                            : undefined
                    }
                    sort={table.state.sort}
                    onSort={table.toggleSort}
                    onPage={table.setPage}
                    loading={table.loading}
                    bulkActions={bulkActions}
                    chooser="none"
                    visibility={columnState.visibility}
                    onVisibilityChange={columnState.setVisibility}
                    onPerPage={table.setPerPage}
                    fill
                    totals={{
                        cells: {
                            qty: (
                                <span className="num">
                                    {digits.qty(totals.qty)}
                                </span>
                            ),
                            total: digits.money(totals.total),
                            paid: digits.money(totals.paid),
                            due: digits.money(totals.due),
                        },
                    }}
                    empty={
                        <EmptyState
                            icon={Truck}
                            title={t('purchases.empty')}
                            description={t('purchases.empty_hint')}
                        />
                    }
                />
            </Page>
            <FiltersDialog
                open={moreOpen}
                onOpenChange={setMoreOpen}
                values={filters}
                onApply={setFilters}
                fields={[
                    {
                        key: 'vendor',
                        label: t('purchases.vendor'),
                        options: [
                            { value: '', label: t('purchases.all_vendors') },
                            ...vendors.map((v) => ({
                                value: String(v.id),
                                label: v.name,
                            })),
                        ],
                    },
                ]}
            />
        </>
    );
}

function statusOf(r: { status: string; payment_status: string }): string {
    return r.status === 'received' ? r.payment_status : r.status;
}
