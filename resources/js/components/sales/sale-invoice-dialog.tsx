import { Link, router, usePage } from '@inertiajs/react';
import {
    Ban,
    ChevronDown,
    FileText,
    HandCoins,
    MessageSquare,
    Printer,
    Receipt,
    Repeat,
    Share2,
    Truck,
    Undo2,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Kbd } from '@/components/kbd';
import { CollectDialog } from '@/components/sales/collect-dialog';
import { VoidSaleDialog } from '@/components/sales/void-dialog';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogActions,
    DialogBar,
    DialogBody,
    DialogContent,
} from '@/components/ui/dialog';
import { actions } from '@/command/actions';
import { navigateTo } from '@/command/navigate';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { apiRequest } from '@/lib/api';
import {
    defaultPaper,
    openPrint,
    salesUrl,
    type CustomerSummary,
    type MoneyMethod,
} from '@/lib/sales';
import { cn } from '@/lib/utils';

export type SaleDetail = {
    sale: {
        id: number;
        number: string;
        status: string;
        payment_status: string;
        origin: string;
        sold_at: string | null;
        cashier: string | null;
        register: { name: string; code: string } | null;
        note: string | null;
        courier_name: string | null;
        courier_tracking: string | null;
        buyer_vat_bin: string | null;
        sync_flags: string[];
        subtotal: number;
        item_discount_total: number;
        order_discount: number;
        tax_total: number;
        shipping: number;
        rounding: number;
        total: number;
        paid: number;
        change_given: number;
        due: number;
        returned_total: number;
        cogs_total: number | null;
        voided_at: string | null;
        voided_by: string | null;
        void_reason: string | null;
        public_url: string | null;
    };
    customer: (CustomerSummary & { balance: number }) | null;
    lines: {
        id: number;
        name: string;
        sku: string;
        qty: number;
        qty_returned: number;
        unit_price: number;
        discount: number;
        tax_rate_bp: number;
        tax_amount: number;
        line_total: number;
        cogs: number | null;
        batches:
            | {
                  id: number;
                  batch: string;
                  received_at: string | null;
                  qty: number;
                  unit_cost: number;
              }[]
            | null;
    }[];
    payments: {
        id: number;
        number: string;
        method: string;
        amount: number;
        payment_amount: number;
        reference: string | null;
        paid_at: string | null;
        voided: boolean;
        credit: boolean;
    }[];
    returns: {
        id: number;
        number: string;
        returned_at: string | null;
        total: number;
        refund_mode: string;
        exchange_sale_id: number | null;
    }[];
    exchange_of: {
        return_id: number;
        return_number: string;
        sale_id: number;
        sale_number: string;
    } | null;
    sms: { id: number; status: string; sent_at: string | null } | null;
    audit: { event: string; by: string | null; at: string | null }[];
    can: {
        void: boolean;
        collect: boolean;
        return: boolean;
        exchange: boolean;
        share: boolean;
        resend_sms: boolean;
    };
};

type Action = {
    id: string;
    keys: string;
    label: string;
    icon: typeof Printer;
    show: boolean;
    run: () => void;
    variant: 'warning' | 'outline' | 'destructive';
};

const card = 'rounded-xl border bg-surface p-4 shadow-xs';

/**
 * The sale as the mockup's invoice dialog over Sales History: header (number, status, created by),
 * "Purchased Items & Discounts", "Payment Breakdown", "Customer Info", then payments, returns, the
 * receipt SMS and activity. Cost and FIFO batches sit in a collapsed "Cost & batches" section, sent
 * only with products.view_cost. Footer: Return Items, Print 80 mm, A4 Tax Invoice, then the rest.
 */
export function SaleInvoiceDialog({
    detail,
    methods,
    onClose,
}: {
    detail: SaleDetail;
    methods: MoneyMethod[];
    onClose: () => void;
}) {
    const { t } = useT();
    const digits = useDigits();
    const branchPaper = usePage().props.auth.branch_paper;
    const { sale, customer, lines, payments, returns, can, sms, audit } =
        detail;
    const exchangeOf = detail.exchange_of;
    const [voidOpen, setVoidOpen] = useState(false);
    const [collectOpen, setCollectOpen] = useState(false);
    const [shareUrl, setShareUrl] = useState<string | null>(sale.public_url);
    const [resending, setResending] = useState(false);

    const share = async () => {
        try {
            const { url } = await apiRequest<{ url: string }>(
                salesUrl.share(sale.id),
                { method: 'POST', body: {} },
            );
            setShareUrl(url);
            await navigator.clipboard?.writeText(url);
            toast.success(t('sales.link_copied'));
        } catch {
            toast.error(t('sales.share_failed'));
        }
    };

    const resendSms = () => {
        if (!sms || resending) {
            return;
        }
        router.post(
            `/app/messages/${sms.id}/resend`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setResending(true),
                onFinish: () => setResending(false),
            },
        );
    };

    const buttons: Action[] = [
        {
            id: 'return',
            keys: 'r',
            label: t('sales.return_items'),
            icon: Undo2,
            show: can.return,
            run: () => navigateTo(salesUrl.returnCreate(sale.id)),
            variant: 'warning' as const,
        },
        {
            id: 'print',
            keys: 'p',
            label: t('sales.print_receipt'),
            icon: Printer,
            show: true,
            run: () =>
                openPrint(salesUrl.receipt(sale.id, defaultPaper(branchPaper))),
            variant: 'outline' as const,
        },
        {
            id: 'a4',
            keys: 'shift+p',
            label: t('sales.print_a4_tax'),
            icon: FileText,
            show: true,
            run: () => openPrint(salesUrl.receipt(sale.id, 'a4')),
            variant: 'outline' as const,
        },
        {
            id: 'print58',
            keys: 'alt+p',
            label: t('sales.print_58'),
            icon: Receipt,
            show: true,
            run: () => openPrint(salesUrl.receipt(sale.id, '58')),
            variant: 'outline' as const,
        },
        {
            id: 'chalan',
            keys: 'd',
            label: t('sales.chalan'),
            icon: Truck,
            show: true,
            run: () => openPrint(salesUrl.chalan(sale.id)),
            variant: 'outline' as const,
        },
        {
            id: 'exchange',
            keys: 'x',
            label: t('sales.exchange'),
            icon: Repeat,
            show: can.exchange,
            run: () => navigateTo(salesUrl.exchangeCreate(sale.id)),
            variant: 'outline' as const,
        },
        {
            id: 'collect',
            keys: 'c',
            label: t('sales.collect_due'),
            icon: HandCoins,
            show: can.collect,
            run: () => setCollectOpen(true),
            variant: 'outline' as const,
        },
        {
            id: 'share',
            keys: 's',
            label: t('sales.share'),
            icon: Share2,
            show: can.share,
            run: () => void share(),
            variant: 'outline' as const,
        },
        {
            id: 'resend_sms',
            keys: 'm',
            label: t('sales.resend_sms'),
            icon: MessageSquare,
            show: can.resend_sms,
            run: resendSms,
            variant: 'outline' as const,
        },
        {
            id: 'void',
            keys: 'v',
            label: t('sales.void'),
            icon: Ban,
            show: can.void,
            run: () => setVoidOpen(true),
            variant: 'destructive' as const,
        },
    ].filter((b) => b.show);

    // The dialog's keys sit on their own scope above the list. On a deep link the dialog mounts with
    // the list, whose scope is pushed after ours: arm on the next frame so ours ends up on top.
    const [armed, setArmed] = useState(false);
    useEffect(() => {
        const frame = requestAnimationFrame(() => setArmed(true));

        return () => cancelAnimationFrame(frame);
    }, []);
    useHotkeys(
        'sale-dialog',
        armed
            ? [
                  ...buttons.map((b) => ({
                      id: `sale.show.${b.id}`,
                      keys: b.keys,
                      labelKey: `sales.hotkey_${b.id}`,
                      handler: b.run,
                  })),
                  {
                      id: 'sale.show.back',
                      keys: 'alt+arrowleft',
                      labelKey: 'hotkeys.back',
                      handler: onClose,
                  },
              ]
            : [],
    );

    // Palette entries for the open sale; handlers read the latest render.
    const latest = useRef(buttons);
    useEffect(() => {
        latest.current = buttons;
    });
    const commandKey = buttons.map((b) => b.id).join('|');
    useEffect(
        () =>
            actions.register(
                ...latest.current.map((b) => ({
                    id: `page.sale.${b.id}`,
                    entity: null,
                    labelKey: `sales.hotkey_${b.id}`,
                    icon: b.icon,
                    keys: b.keys,
                    run: () => latest.current.find((x) => x.id === b.id)?.run(),
                })),
            ),
        [commandKey],
    );

    const status =
        sale.status === 'completed' ? sale.payment_status : sale.status;
    const discounts = sale.item_discount_total + sale.order_discount;
    const showCost = sale.cogs_total !== null;

    return (
        <>
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent
                    layout="panel"
                    className="sm:max-w-5xl"
                    aria-describedby={undefined}
                >
                    <DialogBar
                        icon={FileText}
                        closeLabel={t('common.close')}
                        title={
                            <span className="flex items-center gap-2">
                                <span className="num font-mono text-base">
                                    {sale.number}
                                </span>
                                <StatusBadge status={status} />
                                {sale.returned_total > 0 ? (
                                    <StatusBadge
                                        status="returned"
                                        tone="info"
                                        label={t('sales.has_returns')}
                                    />
                                ) : null}
                                {sale.sync_flags.map((flag) => (
                                    <StatusBadge
                                        key={flag}
                                        status={flag}
                                        tone="warning"
                                        label={t(`sales.flag_${flag}`)}
                                    />
                                ))}
                            </span>
                        }
                        subtitle={
                            <span className="num">
                                {t('sales.created_at_by', {
                                    date: digits.n(sale.sold_at ?? ''),
                                    name: sale.cashier ?? '—',
                                })}
                                {sale.register
                                    ? ` · ${sale.register.name} [${sale.register.code}]`
                                    : ''}
                            </span>
                        }
                    />
                    <DialogBody className="space-y-4">
                        {sale.status === 'voided' ? (
                            <output className="block rounded-lg border border-destructive bg-danger-soft p-3">
                                {t('sales.voided_banner', {
                                    date: digits.n(sale.voided_at ?? ''),
                                    name: sale.voided_by ?? '—',
                                    reason: sale.void_reason ?? '',
                                })}
                            </output>
                        ) : null}
                        {exchangeOf ? (
                            <div
                                role="note"
                                className="rounded-lg border bg-info-soft p-3"
                            >
                                {t('sales.replacement_of')}{' '}
                                <Link
                                    className="num font-semibold underline"
                                    href={salesUrl.returnShow(
                                        exchangeOf.return_id,
                                    )}
                                >
                                    {exchangeOf.return_number}
                                </Link>{' '}
                                ·{' '}
                                <Link
                                    className="num font-semibold underline"
                                    href={salesUrl.show(exchangeOf.sale_id)}
                                >
                                    {exchangeOf.sale_number}
                                </Link>
                            </div>
                        ) : null}

                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <section
                                className={cn(card, 'space-y-3 lg:col-span-2')}
                                aria-labelledby="sale-items-heading"
                            >
                                <h2
                                    id="sale-items-heading"
                                    className="text-sm font-bold"
                                >
                                    {t('sales.items_discounts')}
                                </h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs max-sm:min-w-[28rem]">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="py-2"
                                                >
                                                    {t(
                                                        'sales.item_description',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-center"
                                                >
                                                    {t('sales.qty')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-right"
                                                >
                                                    {t('sales.unit_price')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-right"
                                                >
                                                    {t('sales.line_discount')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-right"
                                                >
                                                    {t('sales.vat')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-right"
                                                >
                                                    {t('sales.line_total')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {lines.map((line) => (
                                                <tr key={line.id}>
                                                    <td className="py-2.5">
                                                        <div className="font-medium">
                                                            {line.name}
                                                        </div>
                                                        <div className="num text-[10px] text-muted-foreground">
                                                            {line.sku}
                                                        </div>
                                                        {line.qty_returned >
                                                        0 ? (
                                                            <div className="text-[10px] text-info">
                                                                {t(
                                                                    'sales.returned_qty',
                                                                    {
                                                                        qty: digits.qty(
                                                                            line.qty_returned,
                                                                        ),
                                                                    },
                                                                )}
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                    <td className="num py-2.5 text-center font-mono">
                                                        {digits.qty(line.qty)}
                                                    </td>
                                                    <td className="num py-2.5 text-right">
                                                        {digits.money(
                                                            line.unit_price,
                                                        )}
                                                    </td>
                                                    <td className="num py-2.5 text-right text-destructive">
                                                        {line.discount
                                                            ? `−${digits.money(line.discount)}`
                                                            : '—'}
                                                    </td>
                                                    <td className="num py-2.5 text-right">
                                                        {line.tax_amount
                                                            ? digits.money(
                                                                  line.tax_amount,
                                                              )
                                                            : '—'}
                                                    </td>
                                                    <td className="num py-2.5 text-right font-bold">
                                                        {digits.money(
                                                            line.line_total,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {sale.note ? (
                                    <p>
                                        <span className="font-semibold">
                                            {t('sales.note')}:
                                        </span>{' '}
                                        {sale.note}
                                    </p>
                                ) : null}
                                {sale.courier_name ? (
                                    <p>
                                        <span className="font-semibold">
                                            {t('sales.courier')}:
                                        </span>{' '}
                                        {sale.courier_name}{' '}
                                        <span className="num">
                                            {sale.courier_tracking ?? ''}
                                        </span>
                                    </p>
                                ) : null}
                            </section>

                            <div className="space-y-4">
                                <section
                                    className={cn(card, 'space-y-2')}
                                    aria-labelledby="sale-totals-heading"
                                >
                                    <h2
                                        id="sale-totals-heading"
                                        className="mb-2 text-sm font-bold"
                                    >
                                        {t('sales.breakdown')}
                                    </h2>
                                    <Line
                                        label={t('sales.subtotal')}
                                        value={digits.money(sale.subtotal)}
                                        tone="muted"
                                    />
                                    {discounts ? (
                                        <Line
                                            label={t('sales.total_discounts')}
                                            value={`−${digits.money(discounts)}`}
                                            tone="danger"
                                        />
                                    ) : null}
                                    {sale.tax_total ? (
                                        <Line
                                            label={t('sales.vat')}
                                            value={digits.money(sale.tax_total)}
                                            tone="muted"
                                        />
                                    ) : null}
                                    {sale.shipping ? (
                                        <Line
                                            label={t('sales.shipping')}
                                            value={digits.money(sale.shipping)}
                                            tone="muted"
                                        />
                                    ) : null}
                                    {sale.rounding ? (
                                        <Line
                                            label={t('sales.rounding')}
                                            value={digits.money(sale.rounding)}
                                            tone="muted"
                                        />
                                    ) : null}
                                    <div className="flex justify-between border-t pt-2 text-sm font-bold">
                                        <span>{t('sales.grand_total')}</span>
                                        <span className="num text-primary">
                                            {digits.money(sale.total)}
                                        </span>
                                    </div>
                                    <Line
                                        label={t('sales.paid')}
                                        value={digits.money(sale.paid)}
                                        tone="success"
                                    />
                                    {sale.change_given ? (
                                        <Line
                                            label={t('sales.change')}
                                            value={digits.money(
                                                sale.change_given,
                                            )}
                                            tone="muted"
                                        />
                                    ) : null}
                                    {sale.returned_total ? (
                                        <Line
                                            label={t('sales.returned')}
                                            value={digits.money(
                                                sale.returned_total,
                                            )}
                                            tone="muted"
                                        />
                                    ) : null}
                                    {sale.due > 0 ? (
                                        <div className="flex justify-between border-t pt-1 font-bold text-destructive">
                                            <span>
                                                {t('sales.outstanding_due')}
                                            </span>
                                            <span className="num">
                                                {digits.money(sale.due)}
                                            </span>
                                        </div>
                                    ) : null}
                                </section>

                                <section
                                    className={cn(card, 'space-y-2')}
                                    aria-labelledby="sale-customer-heading"
                                >
                                    <h2
                                        id="sale-customer-heading"
                                        className="mb-2 text-sm font-bold"
                                    >
                                        {t('sales.customer_info')}
                                    </h2>
                                    {customer && !customer.walk_in ? (
                                        <>
                                            <div className="flex justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {t('sales.customer')}
                                                </span>
                                                <Link
                                                    href={salesUrl.customer(
                                                        customer.uuid,
                                                    )}
                                                    className="font-semibold hover:underline"
                                                >
                                                    {customer.name}
                                                </Link>
                                            </div>
                                            {customer.shop_name ? (
                                                <div className="flex justify-between gap-2">
                                                    <span className="text-muted-foreground">
                                                        {t('sales.shop')}
                                                    </span>
                                                    <span>
                                                        {customer.shop_name}
                                                    </span>
                                                </div>
                                            ) : null}
                                            <div className="flex justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {t('sales.customer_no')}
                                                </span>
                                                <span className="num font-mono text-primary">
                                                    {digits.n(
                                                        customer.customer_no ??
                                                            '—',
                                                    )}
                                                </span>
                                            </div>
                                            {customer.phone ? (
                                                <div className="flex justify-between gap-2">
                                                    <span className="text-muted-foreground">
                                                        {t('sales.phone')}
                                                    </span>
                                                    <span className="num font-mono">
                                                        {digits.n(
                                                            customer.phone,
                                                        )}
                                                    </span>
                                                </div>
                                            ) : null}
                                            {sale.buyer_vat_bin ? (
                                                <div className="flex justify-between gap-2">
                                                    <span className="text-muted-foreground">
                                                        {t('sales.bin')}
                                                    </span>
                                                    <span className="num">
                                                        {sale.buyer_vat_bin}
                                                    </span>
                                                </div>
                                            ) : null}
                                            <div className="flex justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {t('sales.balance')}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'num font-semibold',
                                                        customer.balance > 0 &&
                                                            'text-destructive',
                                                    )}
                                                >
                                                    {digits.money(
                                                        customer.balance,
                                                    )}
                                                </span>
                                            </div>
                                            <Link
                                                href={salesUrl.dueShow(
                                                    customer.uuid,
                                                )}
                                                className="inline-block text-primary hover:underline"
                                            >
                                                {t('sales.open_dues')}
                                            </Link>
                                        </>
                                    ) : (
                                        <>
                                            <div className="flex justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {t('sales.customer')}
                                                </span>
                                                <span className="font-semibold">
                                                    {t('sales.walk_in')}
                                                </span>
                                            </div>
                                            <div className="flex justify-between gap-2">
                                                <span className="text-muted-foreground">
                                                    {t('sales.customer_no')}
                                                </span>
                                                <span className="font-mono">
                                                    {t('sales.walk_in_short')}
                                                </span>
                                            </div>
                                        </>
                                    )}
                                </section>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <section
                                className={cn(card, 'space-y-2')}
                                aria-labelledby="sale-payments-heading"
                            >
                                <h2
                                    id="sale-payments-heading"
                                    className="text-sm font-bold"
                                >
                                    {t('sales.payments')}
                                </h2>
                                {payments.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        {t('sales.no_payments')}
                                    </p>
                                ) : (
                                    <ul className="grid gap-2">
                                        {payments.map((p) => (
                                            <li
                                                key={`${p.id}-${p.amount}`}
                                                className={cn(
                                                    'flex items-center justify-between gap-2',
                                                    p.voided &&
                                                        'line-through opacity-60',
                                                )}
                                            >
                                                <span>
                                                    <span className="font-semibold">
                                                        {p.method}
                                                    </span>
                                                    {p.reference ? (
                                                        <span className="num ml-1 text-[10px] text-muted-foreground">
                                                            {p.reference}
                                                        </span>
                                                    ) : null}
                                                    <span className="num block text-[10px] text-muted-foreground">
                                                        {p.number} ·{' '}
                                                        {digits.n(
                                                            p.paid_at ?? '',
                                                        )}
                                                    </span>
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <span className="num font-semibold">
                                                        {digits.money(p.amount)}
                                                    </span>
                                                    {!p.credit && !p.voided ? (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-11 md:size-7"
                                                            aria-label={t(
                                                                'sales.print_money_receipt',
                                                                {
                                                                    number: p.number,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                openPrint(
                                                                    salesUrl.paymentReceipt(
                                                                        p.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <Printer aria-hidden="true" />
                                                        </Button>
                                                    ) : null}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section
                                className={cn(card, 'space-y-2')}
                                aria-labelledby="sale-returns-heading"
                            >
                                <h2
                                    id="sale-returns-heading"
                                    className="text-sm font-bold"
                                >
                                    {t('sales.linked_returns')}
                                </h2>
                                {returns.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        {t('sales.no_returns')}
                                    </p>
                                ) : (
                                    <ul className="grid gap-2">
                                        {returns.map((r) => (
                                            <li
                                                key={r.id}
                                                className="flex items-center justify-between gap-2"
                                            >
                                                <span>
                                                    <Link
                                                        href={salesUrl.returnShow(
                                                            r.id,
                                                        )}
                                                        className="num font-semibold text-warning hover:underline"
                                                    >
                                                        {r.number}
                                                    </Link>
                                                    <span className="num block text-[10px] text-muted-foreground">
                                                        {digits.n(
                                                            r.returned_at ?? '',
                                                        )}{' '}
                                                        ·{' '}
                                                        {t(
                                                            `returns.mode_${r.refund_mode}`,
                                                        )}
                                                    </span>
                                                    {r.exchange_sale_id ? (
                                                        <Link
                                                            href={salesUrl.show(
                                                                r.exchange_sale_id,
                                                            )}
                                                            className="text-[10px] text-primary hover:underline"
                                                        >
                                                            {t(
                                                                'sales.view_replacement',
                                                            )}
                                                        </Link>
                                                    ) : null}
                                                </span>
                                                <span className="num font-semibold text-destructive">
                                                    −{digits.money(r.total)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section
                                className={cn(card, 'space-y-2')}
                                aria-labelledby="sale-audit-heading"
                            >
                                <h2
                                    id="sale-audit-heading"
                                    className="text-sm font-bold"
                                >
                                    {t('sales.audit')}
                                </h2>
                                {sms ? (
                                    <p className="flex items-center justify-between gap-2">
                                        <span>
                                            {t('sales.receipt_sms')}{' '}
                                            <span className="num text-[10px] text-muted-foreground">
                                                {digits.n(sms.sent_at ?? '')}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            status={sms.status}
                                            tone={
                                                sms.status === 'sent'
                                                    ? 'success'
                                                    : sms.status === 'failed'
                                                      ? 'danger'
                                                      : 'warning'
                                            }
                                            label={t(`sales.sms_${sms.status}`)}
                                        />
                                    </p>
                                ) : null}
                                {shareUrl ? (
                                    <p className="truncate">
                                        <span className="text-muted-foreground">
                                            {t('sales.share_link')}:{' '}
                                        </span>
                                        <a
                                            href={shareUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="num text-primary underline"
                                        >
                                            {shareUrl}
                                        </a>
                                    </p>
                                ) : null}
                                {audit.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        {t('sales.no_audit')}
                                    </p>
                                ) : (
                                    <ol className="grid gap-1.5">
                                        {audit.map((a, i) => (
                                            <li
                                                key={i}
                                                className="flex justify-between gap-2"
                                            >
                                                <span>
                                                    {t(
                                                        `sales.event.${a.event.replace(/\./g, '_')}`,
                                                        {
                                                            defaultValue:
                                                                a.event,
                                                        },
                                                    )}
                                                    {a.by ? (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            · {a.by}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="num text-[10px] text-muted-foreground">
                                                    {digits.n(a.at ?? '')}
                                                </span>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        </div>

                        {showCost ? (
                            <Collapsible className={card}>
                                <CollapsibleTrigger className="group flex w-full items-center justify-between gap-2 text-sm font-bold [@media(pointer:coarse)]:min-h-11">
                                    <span>
                                        {t('sales.cost_batches')}{' '}
                                        <span className="num ml-1 text-xs font-semibold text-muted-foreground">
                                            {t('sales.cost_total', {
                                                cost: digits.money(
                                                    sale.cogs_total ?? 0,
                                                ),
                                            })}
                                        </span>
                                    </span>
                                    <ChevronDown
                                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                                        aria-hidden="true"
                                    />
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <table className="mt-3 w-full text-left text-xs">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="py-2"
                                                >
                                                    {t('sales.item')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 text-right"
                                                >
                                                    {t('sales.cogs')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-2 pl-4"
                                                >
                                                    {t('sales.batches')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {lines.map((line) => (
                                                <Fragment key={line.id}>
                                                    <tr>
                                                        <td className="py-2">
                                                            {line.name}
                                                        </td>
                                                        <td className="num py-2 text-right">
                                                            {digits.money(
                                                                line.cogs ?? 0,
                                                            )}
                                                        </td>
                                                        <td className="num py-2 pl-4 text-muted-foreground">
                                                            {(
                                                                line.batches ??
                                                                []
                                                            )
                                                                .map(
                                                                    (b) =>
                                                                        `${b.batch} · ${digits.qty(b.qty)} × ${digits.money(b.unit_cost)}${b.received_at ? ` (${digits.n(b.received_at)})` : ''}`,
                                                                )
                                                                .join('; ') ||
                                                                '—'}
                                                        </td>
                                                    </tr>
                                                </Fragment>
                                            ))}
                                        </tbody>
                                    </table>
                                </CollapsibleContent>
                            </Collapsible>
                        ) : null}
                    </DialogBody>
                    <DialogActions className="justify-start">
                        {buttons.map((b) => (
                            <Button
                                key={b.id}
                                type="button"
                                variant={
                                    b.variant === 'warning'
                                        ? 'default'
                                        : b.variant
                                }
                                className={cn(
                                    b.variant === 'warning' &&
                                        'bg-warning text-destructive-foreground hover:bg-warning/90',
                                    b.id === 'void' && 'sm:ml-auto',
                                )}
                                disabled={b.id === 'resend_sms' && resending}
                                onClick={b.run}
                            >
                                <b.icon aria-hidden="true" />
                                {b.label}
                                <Kbd keys={b.keys} onButton />
                            </Button>
                        ))}
                    </DialogActions>
                </DialogContent>
            </Dialog>
            <VoidSaleDialog
                sale={voidOpen ? { id: sale.id, number: sale.number } : null}
                onOpenChange={setVoidOpen}
            />
            {collectOpen ? (
                <CollectDialog
                    open
                    onOpenChange={setCollectOpen}
                    action={salesUrl.payments(sale.id)}
                    title={t('sales.collect_title', { number: sale.number })}
                    invoices={[
                        {
                            id: sale.id,
                            number: sale.number,
                            sold_at: sale.sold_at,
                            due: sale.due,
                        },
                    ]}
                    methods={methods}
                    {...(customer
                        ? {
                              customer: {
                                  name: customer.name,
                                  customer_no: customer.customer_no,
                                  balance: customer.balance,
                                  credit_limit: null,
                              },
                          }
                        : {})}
                />
            ) : null}
        </>
    );
}

function Line({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone: 'muted' | 'danger' | 'success';
}) {
    return (
        <div
            className={cn(
                'flex justify-between',
                tone === 'muted' && 'text-muted-foreground',
                tone === 'danger' && 'text-destructive',
                tone === 'success' && 'font-medium text-success',
            )}
        >
            <span>{label}</span>
            <span className="num">{value}</span>
        </div>
    );
}
