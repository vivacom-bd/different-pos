import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, FileText, Printer, Truck } from 'lucide-react';
import { Fragment } from 'react';
import { Kbd } from '@/components/kbd';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { navigateTo } from '@/command/navigate';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import {
    defaultPaper,
    openPrint,
    salesUrl,
    type CustomerSummary,
} from '@/lib/sales';

type Props = {
    saleReturn: {
        id: number;
        number: string;
        returned_at: string | null;
        created_by: string | null;
        refund_mode: string;
        reason: string;
        note: string | null;
        subtotal: number;
        tax_total: number;
        total: number;
        refunded: number;
        credited: number;
    };
    sale: { id: number; number: string; sold_at: string | null };
    exchange_sale: {
        id: number;
        number: string;
        total: number;
        difference: number;
    } | null;
    customer: CustomerSummary | null;
    lines: {
        id: number;
        name: string;
        sku: string;
        qty: number;
        unit_price: number;
        tax_amount: number;
        line_total: number;
        restock: boolean;
        cogs_reversed: number | null;
    }[];
};

export default function ReturnShow({
    saleReturn: r,
    sale,
    exchange_sale: exchange,
    customer,
    lines,
}: Props) {
    const { t } = useT();
    const digits = useDigits();
    const branchPaper = usePage().props.auth.branch_paper;
    const print80 = () =>
        openPrint(salesUrl.returnReceipt(r.id, defaultPaper(branchPaper)));
    const printA4 = () => openPrint(salesUrl.returnReceipt(r.id, 'a4'));
    // sales.receipt and sales.chalan take the numeric sale id of the replacement sale.
    const printInvoice = () =>
        exchange
            ? openPrint(
                  salesUrl.receipt(exchange.id, defaultPaper(branchPaper)),
              )
            : undefined;
    const printChalan = () =>
        exchange ? openPrint(salesUrl.chalan(exchange.id)) : undefined;

    useHotkeys('page', [
        {
            id: 'return.print',
            keys: 'p',
            labelKey: 'returns.print',
            handler: print80,
        },
        {
            id: 'return.print_a4',
            keys: 'shift+p',
            labelKey: 'returns.print_a4',
            handler: printA4,
        },
        ...(exchange
            ? [
                  {
                      id: 'return.print_invoice',
                      keys: 'i',
                      labelKey: 'returns.print_new_invoice',
                      handler: printInvoice,
                  },
                  {
                      id: 'return.chalan',
                      keys: 'd',
                      labelKey: 'returns.replacement_chalan',
                      handler: printChalan,
                  },
              ]
            : []),
        {
            id: 'return.back',
            keys: 'alt+arrowleft',
            labelKey: 'hotkeys.back',
            handler: () => navigateTo(salesUrl.returnsIndex),
        },
    ]);
    const showCost = lines.some((l) => l.cogs_reversed !== null);

    return (
        <>
            <Head title={`${t('returns.return')} ${r.number}`} />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b pb-3">
                    <div className="flex items-start gap-3">
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            className="size-11 md:size-9"
                        >
                            <Link
                                href={salesUrl.returnsIndex}
                                aria-label={t('returns.back')}
                            >
                                <ArrowLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="num text-xl font-bold">
                                    {r.number}
                                </h1>
                                <StatusBadge
                                    status={r.refund_mode}
                                    tone={
                                        r.refund_mode === 'exchange'
                                            ? 'accent'
                                            : 'info'
                                    }
                                    label={t(`returns.mode_${r.refund_mode}`)}
                                />
                            </div>
                            <p className="num text-sm text-muted-foreground">
                                {t('sales.created_by', {
                                    date: digits.n(r.returned_at ?? ''),
                                    name: r.created_by ?? '—',
                                })}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 md:min-h-9"
                            onClick={print80}
                        >
                            <Printer aria-hidden="true" /> {t('returns.print')}{' '}
                            <Kbd
                                keys="p"
                                className="ml-1 hidden md:inline-flex"
                            />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 md:min-h-9"
                            onClick={printA4}
                        >
                            <FileText aria-hidden="true" />{' '}
                            {t('returns.print_a4')}{' '}
                            <Kbd
                                keys="shift+p"
                                className="ml-1 hidden md:inline-flex"
                            />
                        </Button>
                        {exchange ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11 md:min-h-9"
                                    onClick={printInvoice}
                                >
                                    <Printer aria-hidden="true" />{' '}
                                    {t('returns.print_new_invoice')}{' '}
                                    <Kbd
                                        keys="i"
                                        className="ml-1 hidden md:inline-flex"
                                    />
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11 md:min-h-9"
                                    onClick={printChalan}
                                >
                                    <Truck aria-hidden="true" />{' '}
                                    {t('returns.replacement_chalan')}{' '}
                                    <Kbd
                                        keys="d"
                                        className="ml-1 hidden md:inline-flex"
                                    />
                                </Button>
                            </>
                        ) : null}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <section
                        className="rounded-lg border bg-card p-4 lg:col-span-2"
                        aria-labelledby="return-lines"
                    >
                        <h2 id="return-lines" className="mb-3 font-semibold">
                            {t('returns.lines')}
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-2 py-2 text-left"
                                        >
                                            {t('sales.item')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-2 py-2 text-right"
                                        >
                                            {t('sales.qty')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-2 py-2 text-right"
                                        >
                                            {t('sales.unit_price')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-2 py-2 text-right"
                                        >
                                            {t('returns.value')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-2 py-2 text-left"
                                        >
                                            {t('returns.restock')}
                                        </th>
                                        {showCost ? (
                                            <th
                                                scope="col"
                                                className="px-2 py-2 text-right"
                                            >
                                                {t('returns.cogs_reversed')}
                                            </th>
                                        ) : null}
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((l) => (
                                        <tr
                                            key={l.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-2 py-2">
                                                <div className="font-medium">
                                                    {l.name}
                                                </div>
                                                <div className="num text-xs text-muted-foreground">
                                                    {l.sku}
                                                </div>
                                            </td>
                                            <td className="num px-2 py-2 text-right">
                                                {digits.qty(l.qty)}
                                            </td>
                                            <td className="num px-2 py-2 text-right">
                                                {digits.money(l.unit_price)}
                                            </td>
                                            <td className="num px-2 py-2 text-right font-semibold">
                                                {digits.money(l.line_total)}
                                            </td>
                                            <td className="px-2 py-2">
                                                {l.restock
                                                    ? t('returns.restocked')
                                                    : t(
                                                          'returns.not_restocked',
                                                      )}
                                            </td>
                                            {showCost ? (
                                                <td className="num px-2 py-2 text-right text-muted-foreground">
                                                    {digits.money(
                                                        l.cogs_reversed ?? 0,
                                                    )}
                                                </td>
                                            ) : null}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {r.note ? (
                            <p className="mt-3 text-sm">
                                <span className="font-medium">
                                    {t('sales.note')}:
                                </span>{' '}
                                {r.note}
                            </p>
                        ) : null}
                    </section>
                    <div className="flex flex-col gap-4">
                        <section
                            className="rounded-lg border bg-card p-4 text-sm"
                            aria-labelledby="return-totals"
                        >
                            <h2
                                id="return-totals"
                                className="mb-2 font-semibold"
                            >
                                {t('returns.settlement')}
                            </h2>
                            <dl className="grid grid-cols-[1fr_auto] gap-y-1">
                                {(
                                    [
                                        [t('sales.subtotal'), r.subtotal],
                                        ...(r.tax_total
                                            ? [[t('sales.vat'), r.tax_total]]
                                            : []),
                                        [t('returns.value'), r.total],
                                        [t('returns.col_refunded'), r.refunded],
                                        [t('returns.col_credited'), r.credited],
                                    ] as [string, number][]
                                ).map(([label, value]) => (
                                    <Fragment key={label}>
                                        <dt className="text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="num text-right">
                                            {digits.money(value)}
                                        </dd>
                                    </Fragment>
                                ))}
                                <dt className="text-muted-foreground">
                                    {t('returns.col_reason')}
                                </dt>
                                <dd className="text-right">
                                    {t(`returns.reason_${r.reason}`, {
                                        defaultValue: r.reason,
                                    })}
                                </dd>
                            </dl>
                        </section>
                        <section
                            className="rounded-lg border bg-card p-4 text-sm"
                            aria-labelledby="return-links"
                        >
                            <h2
                                id="return-links"
                                className="mb-2 font-semibold"
                            >
                                {t('returns.original_sale')}
                            </h2>
                            <Link
                                href={salesUrl.show(sale.id)}
                                className="num font-medium text-primary hover:underline"
                            >
                                {sale.number}
                            </Link>
                            <span className="num block text-xs text-muted-foreground">
                                {digits.n(sale.sold_at ?? '')}
                            </span>
                            {customer && !customer.walk_in ? (
                                <p className="mt-2">
                                    {customer.name}
                                    {customer.shop_name ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {customer.shop_name}
                                        </span>
                                    ) : null}
                                </p>
                            ) : null}
                        </section>
                        {exchange ? (
                            <section
                                className="rounded-lg border bg-card p-4 text-sm"
                                aria-labelledby="return-exchange"
                            >
                                <h2
                                    id="return-exchange"
                                    className="mb-2 font-semibold"
                                >
                                    {t('returns.replacement')}
                                </h2>
                                <Link
                                    href={salesUrl.show(exchange.id)}
                                    className="num font-medium text-primary hover:underline"
                                >
                                    {exchange.number}
                                </Link>
                                <dl className="mt-2 grid grid-cols-[1fr_auto] gap-y-1">
                                    <dt className="text-muted-foreground">
                                        {t('sales.total')}
                                    </dt>
                                    <dd className="num text-right">
                                        {digits.money(exchange.total)}
                                    </dd>
                                    <dt className="font-semibold">
                                        {exchange.difference >= 0
                                            ? t('returns.customer_paid')
                                            : t('returns.customer_got')}
                                    </dt>
                                    <dd className="num text-right font-semibold">
                                        {digits.money(
                                            Math.abs(exchange.difference),
                                        )}
                                    </dd>
                                </dl>
                            </section>
                        ) : null}
                    </div>
                </div>
            </div>
        </>
    );
}
