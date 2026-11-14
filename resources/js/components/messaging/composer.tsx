import { randomUuid } from "@/lib/browser";
import { Link, router } from "@inertiajs/react";
import { AlertTriangle, Search, Send, Smartphone, X } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { Kbd } from "@/components/kbd";
import { NativeSelect } from "@/components/native-select";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useDigits } from "@/hooks/use-digits";
import { useHotkeys } from "@/hotkeys/use-hotkeys";
import { useT } from "@/i18n";
import { ApiError, apiRequest } from "@/lib/api";
import { preOpenTab, sendDirect } from "@/lib/whatsapp";
import type { MessageChannel, WhatsAppConfig } from "@/types/messaging";
import { cn } from "@/lib/utils";
import { PhonePreview } from "./phone-preview";
import {
    type BroadcastKey,
    broadcastKey,
    insertVariable,
    previewValues,
    renderPreview,
    smsCost,
    smsSegments,
} from "./sms-text";

export type ComposerConfig = {
    configured: boolean;
    sending_enabled: boolean;
    driver: string;
    whatsapp: WhatsAppConfig;
    cost_per_segment: number;
    shop: string;
    max_body: number;
    variables: string[];
    templates: {
        key: string;
        body: string;
        enabled: boolean;
        broadcastable: boolean;
    }[];
    counts: { all: number; due: number };
    groups: { id: number; name: string; count: number }[];
};

type Picked = {
    uuid: string;
    name: string;
    phone: string | null;
    customer_no: string | number | null;
};

/** The audience select's value: `due`, `all`, `customer` or `group:<id>`. */
export type Target = string;

/** Recipient count for the chosen audience (the server counts again when it sends). */
export function audienceCount(
    target: Target,
    config: ComposerConfig,
    picked: Picked | null,
): number {
    if (target === "customer") {
        return picked?.phone ? 1 : 0;
    }
    if (target.startsWith("group:")) {
        const id = Number(target.slice(6));

        return config.groups.find((g) => g.id === id)?.count ?? 0;
    }

    return target === "all" ? config.counts.all : config.counts.due;
}

/**
 * Mockup "Dispatch Campaign Composer" (screen 10): channel (SMS or WhatsApp Business), recipient
 * target, a template from the tenant's SMS templates, variable chips, the text with characters / SMS parts,
 * the cost estimate and Send (Ctrl+Enter) behind a confirm with the recipient count and total cost.
 * WhatsApp has no per-part cost; with click-to-chat only the Single target works (the wa.me link opens in
 * a tab pre-opened on the click), bulk needs the Cloud API, and the UI says so.
 */
export function Composer({
    config,
    active = true,
    draft,
}: {
    config: ComposerConfig;
    /** False while another hub tab is shown (the composer stays mounted; its keys pause). */
    active?: boolean;
    /** A template handed over from the Templates tab; a new `seq` replaces the text. */
    draft?: { body: string; seq: number };
}) {
    const { t } = useT();
    const digits = useDigits();
    const [channel, setChannel] = useState<MessageChannel>(
        config.configured || !config.whatsapp.configured ? "sms" : "whatsapp",
    );
    const [target, setTarget] = useState<Target>(
        config.counts.due > 0 ? "due" : "all",
    );
    const [picked, setPicked] = useState<Picked | null>(null);
    const [template, setTemplate] = useState("");
    const [body, setBody] = useState(draft?.body ?? "");
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState<string | null>(null);
    /** Recipients of the last accepted send (the success line under the Send bar). */
    const [sent, setSent] = useState<number | null>(null);
    const area = useRef<HTMLTextAreaElement>(null);
    // Idempotency key, made when the composer opens: a repeated send of the same payload reuses it.
    const sendKey = useRef<BroadcastKey>({
        key: randomUuid(),
        fingerprint: "",
    });

    const seq = draft?.seq ?? 0;
    const [seenSeq, setSeenSeq] = useState(seq);
    if (seenSeq !== seq) {
        setSeenSeq(seq);
        setBody(draft?.body ?? "");
        setTemplate("");
    }
    useEffect(() => {
        if (seq > 0) {
            area.current?.focus();
        }
    }, [seq]);

    const recipients = audienceCount(target, config, picked);
    const info = smsSegments(body);
    const perMessage = smsCost(
        Math.max(1, info.segments),
        1,
        config.cost_per_segment,
    );
    const total = smsCost(info.segments, recipients, config.cost_per_segment);
    const wa = channel === "whatsapp";
    const channelConfigured = wa
        ? config.whatsapp.configured
        : config.configured && config.sending_enabled;
    // Click-to-chat hands one chat to the user's own WhatsApp: only a single customer can be targeted.
    const clickOnly = wa && config.whatsapp.configured && !config.whatsapp.bulk;
    const canSend =
        channelConfigured &&
        body.trim() !== "" &&
        recipients > 0 &&
        (!clickOnly || target === "customer");

    // The picked customer's live template values (same source as the send), else samples.
    const [live, setLive] = useState<{
        uuid: string;
        values: Record<string, string>;
    } | null>(null);
    useEffect(() => {
        if (!picked) {
            return;
        }
        let stale = false;
        apiRequest<{ values: Record<string, string> }>(
            `/app/messages/preview?customer=${encodeURIComponent(picked.uuid)}`,
        )
            .then((r) => {
                if (!stale) {
                    setLive({ uuid: picked.uuid, values: r.values });
                }
            })
            .catch(() => undefined);

        return () => {
            stale = true;
        };
    }, [picked]);

    const sample = useMemo(
        () =>
            previewValues(
                {
                    name: picked?.name ?? t("sms.hub.sample_name"),
                    shop: config.shop,
                    due: t("sms.hub.sample_due"),
                    balance: t("sms.hub.sample_due"),
                    number: picked?.customer_no
                        ? String(picked.customer_no)
                        : t("sms.hub.sample_number"),
                },
                picked && live?.uuid === picked.uuid ? live.values : null,
            ),
        [picked, live, config.shop, t],
    );

    const chooseTemplate = (key: string) => {
        setTemplate(key);
        const tpl = config.templates.find((x) => x.key === key);
        if (tpl) {
            setBody(tpl.body);
        }
    };

    const addVariable = (variable: string) => {
        const el = area.current;
        const next = insertVariable(
            body,
            variable,
            el?.selectionStart ?? body.length,
            el?.selectionEnd ?? body.length,
        );
        setBody(next.text);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(next.caret, next.caret);
        });
    };

    const sendHandoff = async () => {
        setError(null);
        setSent(null);
        const tab = preOpenTab();
        try {
            const result = await sendDirect({
                channel: "whatsapp",
                event: "manual",
                customers: picked ? [picked.uuid] : [],
                body,
            });
            const link = result.handoff[0]?.url;
            if (link && tab) {
                tab.location.href = link;
            } else if (link) {
                window.open(link, "_blank", "noopener");
            } else {
                tab?.close();
            }
            toast.success(
                t("sms.whatsapp.opened", { name: picked?.name ?? "" }),
            );
            setSent(1);
            setBody("");
            setTemplate("");
        } catch (e) {
            tab?.close();
            setError(
                e instanceof ApiError && e.detail
                    ? e.detail
                    : t("sms.whatsapp.send_failed"),
            );
        }
    };

    const send = () =>
        new Promise<void>((resolve) => {
            if (clickOnly) {
                void sendHandoff().finally(resolve);

                return;
            }
            setError(null);
            setSent(null);
            const group = target.startsWith("group:")
                ? Number(target.slice(6))
                : null;
            const payload = {
                channel,
                target: group !== null ? "group" : target,
                customer: target === "customer" ? (picked?.uuid ?? null) : null,
                group_id: group,
                body,
                template: template === "" ? null : template,
            };
            sendKey.current = broadcastKey(
                sendKey.current,
                JSON.stringify(payload),
            );
            router.post(
                "/app/messages/send",
                { ...payload, idempotency_key: sendKey.current.key },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setSent(recipients);
                        setBody("");
                        setTemplate("");
                        sendKey.current = {
                            key: randomUuid(),
                            fingerprint: "",
                        };
                    },
                    onError: (errors) =>
                        setError(Object.values(errors)[0] ?? null),
                    onFinish: () => resolve(),
                },
            );
        });

    useHotkeys("form", [
        {
            id: "messages.send",
            keys: "mod+enter",
            labelKey: "sms.hub.send",
            allowInInput: true,
            handler: (event) => {
                event.preventDefault();
                if (active && canSend) {
                    setConfirming(true);
                }
            },
        },
    ]);

    const sendLabel =
        target === "customer"
            ? t("sms.hub.send_one")
            : t("sms.hub.send_many", { n: digits.n(recipients) });

    return (
        <div className="flex flex-1 flex-col items-start gap-4 lg:flex-row">
            <div className="w-full flex-1 space-y-4 rounded-xl border bg-surface p-4 shadow-xs">
                <h2 className="text-xs font-bold tracking-wider text-foreground uppercase">
                    {t("sms.hub.composer_title")}
                </h2>

                {!channelConfigured ? (
                    <p
                        role="alert"
                        className="flex items-center gap-2 rounded-lg border border-warning bg-warning-soft p-2.5 text-xs text-foreground"
                    >
                        <AlertTriangle
                            className="size-4 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <span className="flex-1">
                            {wa
                                ? t("sms.whatsapp.not_configured")
                                : config.configured
                                  ? t("sms.hub.sending_off")
                                  : t("sms.hub.not_configured")}
                        </span>
                        <Link
                            href="/app/settings/sms"
                            className="font-bold text-primary underline"
                        >
                            {t("sms.hub.open_settings")}
                        </Link>
                    </p>
                ) : null}

                <div className="space-y-3">
                    <fieldset>
                        <legend className="text-[11px] font-semibold text-muted-foreground">
                            {t("sms.hub.channel")}
                        </legend>
                        <div className="mt-1 grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                aria-pressed={wa}
                                onClick={() => setChannel("whatsapp")}
                                className={cn(
                                    "flex h-8 items-center justify-center gap-1.5 rounded-lg border text-xs font-semibold [@media(pointer:coarse)]:min-h-11",
                                    wa
                                        ? "border-success bg-success-soft font-bold text-success"
                                        : "bg-surface-sunken text-muted-foreground hover:text-foreground",
                                )}
                            >
                                <Smartphone
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                <span>{t("sms.hub.whatsapp")}</span>
                            </button>
                            <button
                                type="button"
                                aria-pressed={!wa}
                                onClick={() => setChannel("sms")}
                                className={cn(
                                    "flex h-8 items-center justify-center gap-1.5 rounded-lg border text-xs font-semibold [@media(pointer:coarse)]:min-h-11",
                                    !wa
                                        ? "border-primary bg-primary-soft font-bold text-primary"
                                        : "bg-surface-sunken text-muted-foreground hover:text-foreground",
                                )}
                            >
                                <Send className="size-3.5" aria-hidden="true" />
                                <span>{t("sms.hub.sms")}</span>
                            </button>
                        </div>
                        {wa && channelConfigured ? (
                            <p className="mt-1.5 text-[11px] text-muted-foreground">
                                {clickOnly
                                    ? t("sms.whatsapp.composer_click_single")
                                    : t("sms.whatsapp.composer_no_cost")}
                            </p>
                        ) : null}
                    </fieldset>

                    <div>
                        <Label
                            htmlFor="sms-target"
                            className="text-muted-foreground"
                        >
                            {t("sms.hub.target")}
                        </Label>
                        <NativeSelect
                            id="sms-target"
                            className="mt-1 font-semibold"
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                        >
                            <option value="due">
                                {t("sms.hub.target_due", {
                                    n: digits.n(config.counts.due),
                                })}
                            </option>
                            <option value="customer">
                                {t("sms.hub.target_one", {
                                    name: picked
                                        ? picked.name
                                        : t("sms.hub.pick_customer"),
                                })}
                            </option>
                            {config.groups.map((g) => (
                                <option key={g.id} value={`group:${g.id}`}>
                                    {t("sms.hub.target_group", {
                                        name: g.name,
                                        n: digits.n(g.count),
                                    })}
                                </option>
                            ))}
                            <option value="all">
                                {t("sms.hub.target_all", {
                                    n: digits.n(config.counts.all),
                                })}
                            </option>
                        </NativeSelect>
                        {target === "customer" ? (
                            <CustomerPicker
                                picked={picked}
                                onPick={setPicked}
                            />
                        ) : null}
                    </div>

                    <div>
                        <Label
                            htmlFor="sms-template"
                            className="text-muted-foreground"
                        >
                            {t("sms.hub.template")}
                        </Label>
                        <NativeSelect
                            id="sms-template"
                            className="mt-1"
                            value={template}
                            onChange={(e) => chooseTemplate(e.target.value)}
                        >
                            <option value="">
                                {t("sms.hub.template_custom")}
                            </option>
                            {config.templates
                                .filter((tpl) => tpl.broadcastable)
                                .map((tpl) => (
                                    <option key={tpl.key} value={tpl.key}>
                                        {t(`sms.template.${tpl.key}`)}
                                    </option>
                                ))}
                        </NativeSelect>
                    </div>

                    <div>
                        <div className="mb-1 text-[10px] text-muted-foreground">
                            {t("sms.hub.variables")}
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {config.variables.map((v) => (
                                <button
                                    key={v}
                                    type="button"
                                    onClick={() => addVariable(v)}
                                    className="rounded border bg-surface-sunken px-2 py-0.5 font-mono text-[10px] text-foreground hover:border-primary [@media(pointer:coarse)]:min-h-11"
                                    aria-label={t("sms.hub.insert_variable", {
                                        name: `{${v}}`,
                                    })}
                                >
                                    + {`{${v}}`}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                            <Label htmlFor="sms-body">
                                {t("sms.hub.body")}
                            </Label>
                            <span className="num font-mono" aria-live="polite">
                                {wa
                                    ? digits.n(body.length)
                                    : t("sms.hub.counter", {
                                          chars: digits.n(body.length),
                                          parts: digits.n(info.segments),
                                          encoding: t(
                                              `sms.hub.encoding_${info.encoding}`,
                                          ),
                                      })}
                            </span>
                        </div>
                        <textarea
                            id="sms-body"
                            ref={area}
                            rows={4}
                            maxLength={config.max_body}
                            value={body}
                            placeholder={t("sms.hub.body_ph")}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={error ? "sms-error" : undefined}
                            onChange={(e) => {
                                setBody(e.target.value);
                                setSent(null);
                            }}
                            className="mt-1 w-full rounded-lg border bg-surface-sunken p-2.5 font-mono text-xs leading-relaxed text-foreground outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive"
                        />
                        {error ? (
                            <p
                                id="sms-error"
                                role="alert"
                                className="text-[11px] text-destructive"
                            >
                                {error}
                            </p>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                    <span className="text-xs text-muted-foreground">
                        {wa ? (
                            clickOnly ? (
                                t("sms.whatsapp.composer_handoff")
                            ) : (
                                t("sms.whatsapp.composer_no_cost")
                            )
                        ) : (
                            <>
                                {t("sms.hub.cost_estimate")}{" "}
                                <strong className="num text-foreground">
                                    {t("sms.hub.cost_per_msg", {
                                        amount: digits.money(perMessage),
                                    })}
                                </strong>
                                {recipients > 1 ? (
                                    <>
                                        {" · "}
                                        <span className="num">
                                            {t("sms.hub.cost_total", {
                                                amount: digits.money(total),
                                            })}
                                        </span>
                                    </>
                                ) : null}
                            </>
                        )}
                    </span>
                    <Button
                        type="button"
                        className="h-9 bg-success px-5 font-bold text-primary-foreground hover:bg-success/90"
                        disabled={!canSend}
                        onClick={() => setConfirming(true)}
                    >
                        <Send aria-hidden="true" />
                        {sendLabel}
                        <Kbd keys="mod+enter" onButton />
                    </Button>
                </div>
                {sent !== null ? (
                    <output className="block rounded-lg border border-success bg-success-soft p-2.5 text-xs text-foreground">
                        {t("sms.hub.sent_banner", { n: digits.n(sent) })}
                    </output>
                ) : null}
            </div>

            <PhonePreview
                shop={config.shop}
                text={renderPreview(body, sample)}
            />

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={
                    wa
                        ? t("sms.whatsapp.confirm_title")
                        : t("sms.hub.confirm_title")
                }
                description={
                    wa
                        ? t("sms.whatsapp.confirm_body", {
                              n: digits.n(recipients),
                          })
                        : t("sms.hub.confirm_body", {
                              n: digits.n(recipients),
                              parts: digits.n(info.segments),
                              amount: digits.money(total),
                          })
                }
                confirmLabel={sendLabel}
                onConfirm={send}
            />
        </div>
    );
}

/** Single-customer search (the customer search API); shows the pick with a clear button. */
function CustomerPicker({
    picked,
    onPick,
}: {
    picked: Picked | null;
    onPick: (c: Picked | null) => void;
}) {
    const { t } = useT();
    const digits = useDigits();
    const [q, setQ] = useState("");
    const [results, setResults] = useState<Picked[]>([]);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            return;
        }
        const ctrl = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/app/customers/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: "application/json" },
                signal: ctrl.signal,
            })
                .then((r) => (r.ok ? r.json() : { customers: [] }))
                .then((data: { customers: Picked[] }) =>
                    setResults(data.customers.slice(0, 8)),
                )
                .catch(() => undefined);
        }, 200);

        return () => {
            ctrl.abort();
            window.clearTimeout(timer);
        };
    }, [q]);

    if (picked) {
        return (
            <div className="mt-2 flex items-center justify-between gap-2 rounded-lg border bg-surface-sunken px-2.5 py-1.5 text-xs">
                <span>
                    <span className="font-semibold">{picked.name}</span>{" "}
                    <span className="num font-mono text-muted-foreground">
                        {picked.phone
                            ? digits.n(picked.phone)
                            : t("sms.hub.no_phone")}
                    </span>
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6"
                    aria-label={t("sms.hub.clear_customer")}
                    onClick={() => onPick(null)}
                >
                    <X aria-hidden="true" />
                </Button>
            </div>
        );
    }

    const shown = q.trim().length >= 2 ? results : [];

    return (
        <div className="relative mt-2">
            <Search
                className="pointer-events-none absolute top-2 left-2.5 size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                type="search"
                className="pl-8"
                value={q}
                autoFocus
                placeholder={t("sms.hub.search_customer")}
                aria-label={t("sms.hub.search_customer")}
                onChange={(e) => setQ(e.target.value)}
            />
            {shown.length > 0 ? (
                <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border bg-surface-raised p-1 shadow-lg">
                    {shown.map((c) => (
                        <li key={c.uuid}>
                            <button
                                type="button"
                                className={cn(
                                    "flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-xs hover:bg-surface-sunken focus-visible:bg-surface-sunken focus-visible:outline-none",
                                    !c.phone && "opacity-60",
                                )}
                                onClick={() => {
                                    onPick(c);
                                    setQ("");
                                }}
                            >
                                <span className="font-semibold">{c.name}</span>
                                <span className="num font-mono text-muted-foreground">
                                    {c.phone
                                        ? digits.n(c.phone)
                                        : t("sms.hub.no_phone")}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
