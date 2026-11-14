import { Link, useForm } from "@inertiajs/react";
import {
    AlertCircle,
    CalendarDays,
    CheckCircle2,
    CreditCard,
    MapPin,
    User,
    UserPlus,
    type LucideIcon,
} from "lucide-react";
import { useId, useState, type ReactNode } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Kbd } from "@/components/kbd";
import { LocationFields } from "@/components/location-fields";
import { MoneyInput } from "@/components/money-input";
import { NativeSelect } from "@/components/native-select";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogActions,
    DialogBar,
    DialogBody,
    DialogContent,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useDigits } from "@/hooks/use-digits";
import { useHotkeys } from "@/hotkeys/use-hotkeys";
import { useT } from "@/i18n";
import { apiRequest } from "@/lib/api";
import { displayPhone } from "@/lib/phone";
import { cn } from "@/lib/utils";
import type { Paisa } from "@/engine/money";
import type { CustomerDetail, GroupOption } from "@/types/customers";

type Existing = {
    uuid: string;
    name: string;
    shop_name: string | null;
    customer_no: string | null;
    phone: string | null;
};

type FormData = {
    name: string;
    name_bn: string;
    shop_name: string;
    phone: string;
    alt_phone: string;
    email: string;
    customer_group_id: string;
    credit_limit: Paisa | null;
    geo_division_id: number | null;
    geo_district_id: number | null;
    geo_upazila_id: number | null;
    geo_union_id: number | null;
    address_line: string;
    area: string;
    post_code: string;
    dob: string;
    anniversary_date: string;
    notes: string;
};

/**
 * The mockup's customer registration form (render_customers_messaging.py "SCREEN 7") as a dialog:
 * 1. identity & contact (+ shop name next to the name), 2. address, 3. credit & tier, then the
 * dates/notes the record already keeps. Live duplicate-phone check, inline errors, Ctrl+Enter
 * (or Ctrl+S) saves, Esc asks before throwing away changes. Mounted only while open.
 */
export function CustomerDialog({
    open,
    customer,
    groups,
    onClose,
}: {
    open: boolean;
    /** null = new customer. */
    customer: CustomerDetail | null;
    groups: GroupOption[];
    onClose: () => void;
}) {
    if (!open) {
        return null;
    }

    return (
        <CustomerDialogBody
            customer={customer}
            groups={groups}
            onClose={onClose}
        />
    );
}

function CustomerDialogBody({
    customer,
    groups,
    onClose,
}: {
    customer: CustomerDetail | null;
    groups: GroupOption[];
    onClose: () => void;
}) {
    const { t } = useT();
    const digits = useDigits();
    const editing = customer !== null;
    const form = useForm<FormData>({
        name: customer?.name ?? "",
        name_bn: customer?.name_bn ?? "",
        shop_name: customer?.shop_name ?? "",
        // Shown in the local 01XXX-XXXXXX form; the server normalises any BD format.
        phone: displayPhone(customer?.phone),
        alt_phone: displayPhone(customer?.alt_phone),
        email: customer?.email ?? "",
        customer_group_id:
            customer?.customer_group_id != null
                ? String(customer.customer_group_id)
                : "",
        credit_limit: customer?.credit_limit ?? null,
        geo_division_id: customer?.geo_division_id ?? null,
        geo_district_id: customer?.geo_district_id ?? null,
        geo_upazila_id: customer?.geo_upazila_id ?? null,
        geo_union_id: customer?.geo_union_id ?? null,
        address_line: customer?.address_line ?? "",
        area: customer?.area ?? "",
        post_code: customer?.post_code ?? "",
        dob: customer?.dob ?? "",
        anniversary_date: customer?.anniversary_date ?? "",
        notes: customer?.notes ?? "",
    });
    const [existing, setExisting] = useState<Existing | null>(null);
    const [confirmDiscard, setConfirmDiscard] = useState(false);
    const errors = form.errors as Partial<Record<string, string>>;

    const checkPhone = async (phone: string) => {
        if (phone.trim().length < 6) {
            setExisting(null);

            return;
        }
        try {
            const query = new URLSearchParams({ phone });
            if (customer) {
                query.set("except", customer.uuid);
            }
            const data = await apiRequest<{ existing: Existing | null }>(
                `/app/customers/check-phone?${query.toString()}`,
            );
            setExisting(data.existing);
        } catch {
            setExisting(null);
        }
    };

    const submit = () => {
        if (form.processing) {
            return;
        }
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
            onError: (errs: Record<string, string>) => {
                if (errs["phone"]) {
                    void checkPhone(form.data.phone);
                }
            },
        };
        if (customer) {
            form.put(`/app/customers/${customer.uuid}`, options);
        } else {
            form.post("/app/customers", options);
        }
    };

    const requestClose = () => {
        if (form.isDirty && !form.processing) {
            setConfirmDiscard(true);

            return;
        }
        onClose();
    };

    useHotkeys("dialog", [
        {
            id: "customer_dialog.save",
            keys: "mod+enter",
            labelKey: "hotkeys.save",
            allowInInput: true,
            handler: (event) => {
                event.preventDefault();
                submit();
            },
        },
        {
            id: "customer_dialog.save_s",
            keys: "mod+s",
            labelKey: "hotkeys.save",
            allowInInput: true,
            handler: (event) => {
                event.preventDefault();
                submit();
            },
        },
    ]);

    const group = groups.find(
        (g) => String(g.id) === form.data.customer_group_id,
    );
    const discountPercent = (bp: number) => digits.n(String(bp / 100));

    const text = (
        key: keyof FormData,
        label: string,
        extra: React.ComponentProps<typeof Input> & { hint?: ReactNode } = {},
    ) => {
        const { hint, ...rest } = extra;

        return (
            <Field
                label={label}
                error={errors[key]}
                hint={hint}
                required={rest.required}
            >
                {(props) => (
                    <Input
                        {...props}
                        name={key}
                        value={String(form.data[key] ?? "")}
                        onChange={(e) => form.setData(key, e.target.value)}
                        {...rest}
                    />
                )}
            </Field>
        );
    };

    return (
        <>
            <Dialog
                open
                onOpenChange={(next) => (next ? undefined : requestClose())}
            >
                <DialogContent
                    layout="panel"
                    size="4xl"
                    aria-describedby={undefined}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        document
                            .querySelector<HTMLInputElement>(
                                '#customer-form input[name="name"]',
                            )
                            ?.focus();
                    }}
                >
                    <DialogBar
                        icon={editing ? User : UserPlus}
                        title={
                            editing
                                ? t("customers.dialog.edit_title")
                                : t("customers.dialog.new_title")
                        }
                        badge={
                            customer?.customer_no
                                ? `#${digits.n(customer.customer_no)}`
                                : undefined
                        }
                        subtitle={
                            editing
                                ? t("customers.dialog.edit_subtitle")
                                : t("customers.dialog.new_subtitle")
                        }
                        closeLabel={t("customers.dialog.close")}
                    />
                    <form
                        id="customer-form"
                        noValidate
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                    >
                        <DialogBody className="space-y-4 bg-background">
                            {errors["form"] ? (
                                <p
                                    role="alert"
                                    className="flex items-center gap-1.5 rounded-lg border border-destructive bg-danger-soft p-2.5 text-xs font-semibold text-destructive"
                                >
                                    <AlertCircle
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {errors["form"]}
                                </p>
                            ) : null}

                            <Section
                                icon={User}
                                title={t("customers.dialog.section_identity")}
                                hint={t("customers.dialog.required_hint")}
                            >
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {text(
                                        "name",
                                        t("customers.dialog.name_en"),
                                        {
                                            required: true,
                                            placeholder: t(
                                                "customers.dialog.name_en_ph",
                                            ),
                                        },
                                    )}
                                    {text(
                                        "name_bn",
                                        t("customers.dialog.name_bn"),
                                        {
                                            lang: "bn",
                                            placeholder: t(
                                                "customers.dialog.name_bn_ph",
                                            ),
                                        },
                                    )}
                                    {text(
                                        "shop_name",
                                        t("customers.form.shop_name"),
                                        {
                                            placeholder: t(
                                                "customers.dialog.shop_ph",
                                            ),
                                        },
                                    )}
                                    <Field
                                        label={t("customers.dialog.phone")}
                                        required
                                        error={errors["phone"]}
                                        hint={t("customers.dialog.phone_hint")}
                                        extra={
                                            existing ? (
                                                <p
                                                    role="alert"
                                                    className="mt-1 rounded-lg border border-warning bg-warning-soft p-2 text-[11px] text-warning"
                                                >
                                                    {t(
                                                        "customers.form.duplicate",
                                                        {
                                                            name: existing.name,
                                                        },
                                                    )}{" "}
                                                    <Link
                                                        href={`/app/customers/${existing.uuid}`}
                                                        className="font-bold underline"
                                                    >
                                                        {t(
                                                            "customers.form.open_existing",
                                                        )}
                                                    </Link>
                                                </p>
                                            ) : null
                                        }
                                    >
                                        {(props) => (
                                            <Input
                                                {...props}
                                                name="phone"
                                                inputMode="tel"
                                                required
                                                placeholder="01711-234567"
                                                className="font-mono font-semibold"
                                                value={form.data.phone}
                                                onChange={(e) =>
                                                    form.setData(
                                                        "phone",
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={(e) =>
                                                    void checkPhone(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </Field>
                                    {text(
                                        "alt_phone",
                                        t("customers.dialog.alt_phone"),
                                        {
                                            inputMode: "tel",
                                            className: "font-mono",
                                            placeholder: t(
                                                "customers.dialog.alt_phone_ph",
                                            ),
                                        },
                                    )}
                                    {text(
                                        "email",
                                        t("customers.dialog.email"),
                                        {
                                            type: "email",
                                            placeholder: t(
                                                "customers.dialog.email_ph",
                                            ),
                                        },
                                    )}
                                </div>
                            </Section>

                            <Section
                                icon={MapPin}
                                title={t("customers.dialog.section_address")}
                                hint={t("customers.dialog.address_hint")}
                            >
                                <LocationFields
                                    value={{
                                        geo_division_id:
                                            form.data.geo_division_id,
                                        geo_district_id:
                                            form.data.geo_district_id,
                                        geo_upazila_id:
                                            form.data.geo_upazila_id,
                                        geo_union_id: form.data.geo_union_id,
                                        post_code: form.data.post_code,
                                    }}
                                    names={customer?.location}
                                    errors={{
                                        geo_division_id:
                                            errors["geo_division_id"],
                                        geo_district_id:
                                            errors["geo_district_id"],
                                        geo_upazila_id:
                                            errors["geo_upazila_id"],
                                        geo_union_id: errors["geo_union_id"],
                                        post_code: errors["post_code"],
                                    }}
                                    onChange={(patch) => {
                                        for (const [
                                            key,
                                            value,
                                        ] of Object.entries(patch)) {
                                            form.setData(
                                                key as keyof FormData,
                                                value as never,
                                            );
                                        }
                                    }}
                                />
                                {customer &&
                                !customer.location_verified &&
                                customer.geo_district_id !== null ? (
                                    <p className="text-[10px] text-warning">
                                        {t("location.unverified")}
                                    </p>
                                ) : null}
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {text(
                                        "address_line",
                                        t("customers.dialog.street"),
                                        {
                                            placeholder: t(
                                                "customers.dialog.street_ph",
                                            ),
                                        },
                                    )}
                                    {text("area", t("customers.dialog.area"), {
                                        placeholder: t(
                                            "customers.dialog.area_ph",
                                        ),
                                    })}
                                </div>
                            </Section>

                            <Section
                                icon={CreditCard}
                                title={t("customers.dialog.section_credit")}
                                hint={t("customers.dialog.credit_hint")}
                            >
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <Field
                                        label={t("customers.dialog.group")}
                                        error={errors["customer_group_id"]}
                                    >
                                        {(props) => (
                                            <NativeSelect
                                                {...props}
                                                name="customer_group_id"
                                                className="font-semibold"
                                                value={
                                                    form.data.customer_group_id
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        "customer_group_id",
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    {t(
                                                        "customers.form.no_group",
                                                    )}
                                                </option>
                                                {groups.map((g) => (
                                                    <option
                                                        key={g.id}
                                                        value={String(g.id)}
                                                    >
                                                        {g.discount_bp > 0
                                                            ? t(
                                                                  "customers.dialog.group_option",
                                                                  {
                                                                      name: g.name,
                                                                      pct: discountPercent(
                                                                          g.discount_bp,
                                                                      ),
                                                                  },
                                                              )
                                                            : g.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        )}
                                    </Field>
                                    <Field
                                        label={t(
                                            "customers.dialog.credit_limit",
                                        )}
                                        error={errors["credit_limit"]}
                                    >
                                        {(props) => (
                                            <MoneyInput
                                                id={props.id}
                                                name="credit_limit"
                                                {...(props["aria-describedby"]
                                                    ? {
                                                          "aria-describedby":
                                                              props[
                                                                  "aria-describedby"
                                                              ],
                                                      }
                                                    : {})}
                                                className="font-mono font-bold"
                                                value={form.data.credit_limit}
                                                onChange={(v) =>
                                                    form.setData(
                                                        "credit_limit",
                                                        v,
                                                    )
                                                }
                                                invalid={
                                                    !!errors["credit_limit"]
                                                }
                                            />
                                        )}
                                    </Field>
                                    <Field
                                        label={t("customers.dialog.discount")}
                                        hint={t(
                                            "customers.dialog.discount_hint",
                                        )}
                                    >
                                        {(props) => (
                                            <Input
                                                {...props}
                                                readOnly
                                                tabIndex={-1}
                                                className="text-right font-mono font-bold text-primary"
                                                value={`${discountPercent(group?.discount_bp ?? 0)}%`}
                                            />
                                        )}
                                    </Field>
                                </div>
                            </Section>

                            <Section
                                icon={CalendarDays}
                                title={t("customers.dialog.section_dates")}
                            >
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {text("dob", t("customers.form.dob"), {
                                        type: "date",
                                    })}
                                    {text(
                                        "anniversary_date",
                                        t("customers.form.anniversary"),
                                        {
                                            type: "date",
                                        },
                                    )}
                                    <div className="sm:col-span-2">
                                        <Field
                                            label={t("customers.form.notes")}
                                            error={errors["notes"]}
                                        >
                                            {(props) => (
                                                <textarea
                                                    {...props}
                                                    name="notes"
                                                    rows={2}
                                                    value={form.data.notes}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            "notes",
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="w-full rounded-lg border bg-surface-sunken px-2.5 py-1.5 text-base text-foreground outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-xs"
                                                />
                                            )}
                                        </Field>
                                    </div>
                                </div>
                            </Section>
                        </DialogBody>
                        <DialogActions
                            hints={
                                <>
                                    <span className="flex items-center gap-1">
                                        <Kbd keys="mod+enter" />
                                        {t("hotkeys.save")}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Kbd keys="escape" />
                                        {t("common.cancel")}
                                    </span>
                                </>
                            }
                        >
                            <Button
                                type="button"
                                variant="outline"
                                onClick={requestClose}
                            >
                                {t("common.cancel")}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                <CheckCircle2 aria-hidden="true" />
                                {t("customers.form.save")}
                            </Button>
                        </DialogActions>
                    </form>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={confirmDiscard}
                onOpenChange={setConfirmDiscard}
                title={t("customers.dialog.discard_title")}
                description={t("customers.dialog.discard_body")}
                confirmLabel={t("customers.dialog.discard")}
                destructive
                onConfirm={onClose}
            />
        </>
    );
}

/** Mockup form section: bordered card, icon + numbered uppercase title, right-hand hint. */
function Section({
    icon: Icon,
    title,
    hint,
    children,
}: {
    icon: LucideIcon;
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    const id = useId();

    return (
        <section
            aria-labelledby={id}
            className="space-y-3 rounded-xl border bg-surface p-4 shadow-xs"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                <h3
                    id={id}
                    className="flex items-center gap-2 text-xs font-bold tracking-wider text-foreground uppercase"
                >
                    <Icon
                        className="size-3.75 text-primary"
                        aria-hidden="true"
                    />
                    {title}
                </h3>
                {hint ? (
                    <span className="text-[10px] text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </div>
            {children}
        </section>
    );
}

type ControlProps = {
    id: string;
    "aria-invalid"?: boolean;
    "aria-describedby"?: string;
};

/** Mockup field: 11 px semibold muted label, control, 10 px error (with icon) or hint. */
function Field({
    label,
    required,
    error,
    hint,
    extra,
    children,
}: {
    label: string;
    required?: boolean | undefined;
    error?: string | undefined;
    hint?: ReactNode;
    extra?: ReactNode;
    children: (props: ControlProps) => ReactNode;
}) {
    const id = useId();
    const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;

    return (
        <div>
            <label
                htmlFor={id}
                className="text-[11px] font-semibold text-muted-foreground"
            >
                {label}
                {required ? " *" : null}
            </label>
            <div className={cn("mt-1", error && "[&_input]:bg-danger-soft")}>
                {children({
                    id,
                    ...(error ? { "aria-invalid": true } : {}),
                    ...(describedBy ? { "aria-describedby": describedBy } : {}),
                })}
            </div>
            {error ? (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="mt-1 flex items-center gap-1 text-[10px] font-semibold text-destructive"
                >
                    <AlertCircle className="size-2.75" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            ) : hint ? (
                <p
                    id={`${id}-hint`}
                    className="mt-0.5 text-[10px] text-muted-foreground"
                >
                    {hint}
                </p>
            ) : null}
            {extra}
        </div>
    );
}
