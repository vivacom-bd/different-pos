import { router, usePage } from "@inertiajs/react";
import { Banknote, Check, ChevronRight } from "lucide-react";
import { useMemo, useState } from "react";
import InputError from "@/components/input-error";
import { MoneyInput } from "@/components/money-input";
import { NativeSelect } from "@/components/native-select";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
    Dialog,
    DialogActions,
    DialogBar,
    DialogBody,
    DialogContent,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import type { Paisa } from "@/engine/money";
import { useDigits } from "@/hooks/use-digits";
import { useHotkeyScope } from "@/hotkeys/use-hotkeys";
import { useT } from "@/i18n";
import {
    allocatedTotal,
    allocateOldestFirst,
    allocateSelected,
} from "@/lib/dues";
import { openPrint, salesUrl, type MoneyMethod } from "@/lib/sales";

export type CollectInvoice = {
    id: number;
    number: string;
    sold_at: string | null;
    due: Paisa;
};

/**
 * Due collection: amount, method, reference and the allocation preview (oldest first, or only the
 * ticked invoices). Overpayment stays on the account as an advance. The server does the real allocation.
 */
export function CollectDialog({
    open,
    onOpenChange,
    action,
    title,
    invoices,
    methods,
    onCollected,
    customer,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** POST target. */
    action: string;
    title: string;
    /** Open invoices, oldest first. */
    invoices: CollectInvoice[];
    methods: MoneyMethod[];
    onCollected?: () => void;
    /** Mockup header line and the credit-limit note. */
    customer?: {
        name: string;
        customer_no: string | null;
        balance: number;
        credit_limit: number | null;
    };
}) {
    const { t } = useT();
    // Branch setting: off = no automatic print window after collecting (the receipt stays one click away).
    const autoPrint =
        usePage<{ auth?: { branch_auto_print?: boolean | null } }>().props.auth
            ?.branch_auto_print !== false;
    const digits = useDigits();
    const totalDue = invoices.reduce((sum, i) => sum + i.due, 0);
    const [amount, setAmount] = useState<Paisa | null>(totalDue);
    const [methodId, setMethodId] = useState<number | null>(
        methods[0]?.id ?? null,
    );
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [manual, setManual] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    useHotkeyScope("dialog", open);

    const method = methods.find((m) => m.id === methodId);
    // Reference and note sit under "More" (mockup default view) unless the method needs a reference.
    const showMore =
        moreOpen ||
        method?.requires_reference === true ||
        errors["reference"] !== undefined;
    const preview = useMemo(
        () =>
            manual
                ? allocateSelected(invoices, selected, amount ?? 0)
                : allocateOldestFirst(invoices, amount ?? 0),
        [manual, invoices, selected, amount],
    );
    const allocated = allocatedTotal(preview);
    const advance = (amount ?? 0) - allocated;

    const toggle = (id: number) =>
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const submit = () => {
        const allocations = manual
            ? Object.entries(preview.allocations).map(([saleId, share]) => ({
                  sale_id: Number(saleId),
                  amount: share,
              }))
            : null;
        router.post(
            action,
            {
                amount: amount ?? 0,
                payment_method_id: methodId,
                reference: reference.trim() || null,
                note: note.trim() || null,
                ...(allocations && allocations.length > 0
                    ? { allocations }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (e) => setErrors(e),
                onSuccess: (page) => {
                    setErrors({});
                    onOpenChange(false);
                    onCollected?.();
                    // "Print Money Receipt & Settle": the redirect names the new payment (?receipt=).
                    const receipt = new URL(
                        page.url,
                        window.location.origin,
                    ).searchParams.get("receipt");
                    if (autoPrint && receipt && /^\d+$/.test(receipt)) {
                        openPrint(salesUrl.paymentReceipt(Number(receipt)));
                    }
                },
            },
        );
    };

    const invalid =
        !amount ||
        amount <= 0 ||
        methodId === null ||
        (method?.requires_reference === true && reference.trim() === "") ||
        (manual && advance > 0 && selected.size === 0);

    const remaining = Math.max(0, totalDue - (amount ?? 0));

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !busy && onOpenChange(next)}
        >
            <DialogContent
                layout="panel"
                size="lg"
                onOpenAutoFocus={(e) => {
                    // Mockup: the amount takes the focus (Enter collects it).
                    e.preventDefault();
                    document.getElementById("collect-amount")?.focus();
                }}
            >
                <form
                    className="flex min-h-0 flex-col"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!invalid) {
                            submit();
                        }
                    }}
                >
                    <DialogBar
                        icon={Banknote}
                        title={title}
                        closeLabel={t("common.close")}
                        subtitle={
                            customer
                                ? t("dues.collect_customer", {
                                      name: customer.name,
                                      no: customer.customer_no
                                          ? ` (${digits.n(customer.customer_no)})`
                                          : "",
                                  })
                                : t("dues.collect_hint", {
                                      due: digits.money(totalDue),
                                  })
                        }
                    />
                    <DialogBody className="space-y-4">
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-warning/30 bg-warning-soft p-3">
                            <div>
                                <div className="text-[10px] text-muted-foreground">
                                    {t("dues.outstanding_balance")}
                                </div>
                                <div className="num font-mono text-lg font-extrabold text-destructive">
                                    {digits.money(totalDue)}
                                </div>
                            </div>
                            {customer ? (
                                <span className="num text-right text-[10px] font-semibold text-warning">
                                    {customer.credit_limit !== null
                                        ? t("dues.credit_limit_is", {
                                              limit: digits.money(
                                                  customer.credit_limit,
                                              ),
                                          })
                                        : t("dues.no_limit")}
                                </span>
                            ) : null}
                        </div>

                        <div className="space-y-1.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="text-xs font-bold text-muted-foreground">
                                    {manual
                                        ? t("dues.open_invoices_pick")
                                        : t("dues.open_invoices_oldest")}
                                </div>
                                {invoices.length > 1 ? (
                                    <ToggleGroup
                                        type="single"
                                        variant="outline"
                                        size="sm"
                                        value={manual ? "manual" : "auto"}
                                        onValueChange={(v) =>
                                            v && setManual(v === "manual")
                                        }
                                        aria-label={t("dues.allocation")}
                                    >
                                        <ToggleGroupItem value="auto">
                                            {t("dues.oldest_first")}
                                        </ToggleGroupItem>
                                        <ToggleGroupItem value="manual">
                                            {t("dues.choose_invoices")}
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                ) : null}
                            </div>
                            <ul
                                aria-label={t("dues.allocation")}
                                className="max-h-36 divide-y overflow-y-auto rounded-xl border"
                            >
                                {invoices.map((invoice) => (
                                    <li
                                        key={invoice.id}
                                        className="flex items-center justify-between gap-2 bg-surface-sunken p-2.5 text-[11px]"
                                    >
                                        <span className="flex items-center gap-2">
                                            {manual ? (
                                                <Checkbox
                                                    checked={selected.has(
                                                        invoice.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggle(invoice.id)
                                                    }
                                                    aria-label={t(
                                                        "dues.pick_invoice",
                                                        {
                                                            number: invoice.number,
                                                        },
                                                    )}
                                                />
                                            ) : null}
                                            <span className="num font-mono font-bold text-primary">
                                                {invoice.number}
                                            </span>
                                            <span className="num text-[10px] text-muted-foreground">
                                                {digits.n(
                                                    (
                                                        invoice.sold_at ?? ""
                                                    ).slice(0, 10),
                                                )}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <span className="num text-muted-foreground">
                                                {t("dues.due_is", {
                                                    due: digits.money(
                                                        invoice.due,
                                                    ),
                                                })}
                                            </span>
                                            <span className="num font-bold text-success">
                                                {t("dues.alloc_is", {
                                                    amount: digits.money(
                                                        preview.allocations[
                                                            invoice.id
                                                        ] ?? 0,
                                                    ),
                                                })}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1">
                                <Label
                                    htmlFor="collect-amount"
                                    className="text-xs font-semibold text-muted-foreground"
                                >
                                    {t("dues.amount_partial")}
                                </Label>
                                <MoneyInput
                                    id="collect-amount"
                                    value={amount}
                                    onChange={setAmount}
                                    invalid={errors["amount"] !== undefined}
                                />
                                <InputError message={errors["amount"]} />
                            </div>
                            <div className="grid gap-1">
                                <Label
                                    htmlFor="collect-method"
                                    className="text-xs font-semibold text-muted-foreground"
                                >
                                    {t("dues.tender")}
                                </Label>
                                <NativeSelect
                                    id="collect-method"
                                    value={methodId ?? ""}
                                    onChange={(e) =>
                                        setMethodId(
                                            Number(e.target.value) || null,
                                        )
                                    }
                                >
                                    {methods.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError
                                    message={errors["payment_method_id"]}
                                />
                            </div>
                            {showMore ? (
                                <>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor="collect-reference"
                                            className="text-xs font-semibold text-muted-foreground"
                                        >
                                            {t("dues.reference")}
                                            {method?.requires_reference
                                                ? " *"
                                                : ""}
                                        </Label>
                                        <Input
                                            id="collect-reference"
                                            value={reference}
                                            maxLength={100}
                                            onChange={(e) =>
                                                setReference(e.target.value)
                                            }
                                        />
                                        <InputError
                                            message={errors["reference"]}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor="collect-note"
                                            className="text-xs font-semibold text-muted-foreground"
                                        >
                                            {t("dues.note")}
                                        </Label>
                                        <Input
                                            id="collect-note"
                                            value={note}
                                            maxLength={255}
                                            onChange={(e) =>
                                                setNote(e.target.value)
                                            }
                                        />
                                    </div>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    className="flex items-center gap-1 justify-self-start text-xs font-semibold text-primary hover:underline sm:col-span-2"
                                    aria-expanded={false}
                                    onClick={() => setMoreOpen(true)}
                                >
                                    <ChevronRight
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t("dues.more_fields")}
                                </button>
                            )}
                        </div>

                        <div className="flex items-center justify-between rounded-xl bg-surface-sunken p-2.5">
                            <span>{t("dues.remaining_after")}</span>
                            <span className="num font-mono font-bold">
                                {digits.money(remaining)}
                            </span>
                        </div>
                        {advance > 0 ? (
                            <p className="num text-[11px] text-muted-foreground">
                                {t("dues.advance_note", {
                                    amount: digits.money(advance),
                                })}
                            </p>
                        ) : null}
                        <InputError
                            message={errors["allocations"] ?? errors["form"]}
                        />
                    </DialogBody>
                    <DialogActions className="flex-nowrap">
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-9 flex-1"
                            disabled={busy}
                            onClick={() => onOpenChange(false)}
                        >
                            {t("confirm.cancel")}
                        </Button>
                        <Button
                            type="submit"
                            className="h-9 flex-1 font-bold"
                            disabled={busy || invalid}
                        >
                            <Check aria-hidden="true" />
                            {t("dues.collect_confirm")}
                        </Button>
                    </DialogActions>
                </form>
            </DialogContent>
        </Dialog>
    );
}
