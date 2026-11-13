import { router } from "@inertiajs/react";
import { Copy, Lock, Plus, RotateCcw, Save, Trash2 } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { RecordDialog } from "@/components/admin/record-dialog";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FormField } from "@/components/form-field";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { useDigits } from "@/hooks/use-digits";
import { useHotkeys } from "@/hotkeys/use-hotkeys";
import { useT } from "@/i18n";
import { cn } from "@/lib/utils";
import { permissionLabel } from "@/lib/labels";

export type MatrixRole = {
    id: number;
    name: string;
    is_system: boolean;
    max_discount_pct_bp: number;
    permissions: string[];
    users: number;
};

export type MatrixProps = {
    permissions: string[];
    roles: MatrixRole[];
    sensitive_permissions?: string[] | undefined;
    is_owner?: boolean | undefined;
};

type Draft = { permissions: string[]; max_discount_pct_bp: number };

export const sameSet = (a: string[], b: string[]) =>
    a.length === b.length && a.every((p) => b.includes(p));

/** Visible box: a strong border (the default input border is too faint on the surface), checked fill, focus ring. */
const BOX =
    "size-5 rounded-[5px] border-2 border-muted-foreground bg-surface shadow-none data-[state=checked]:border-primary data-[state=indeterminate]:border-primary focus-visible:ring-[3px] focus-visible:ring-ring disabled:opacity-60";

/** Basis points <-> a percent text with up to two decimals ("12.5"). */
export const toPercent = (bp: number) => String(bp / 100);
export const fromPercent = (text: string) =>
    Math.min(
        10000,
        Math.max(0, Math.round(Number.parseFloat(text || "0") * 100) || 0),
    );

/** Permissions grouped by their prefix ("sales.void" → "sales"), in Permission::ALL order. */
export function groupPermissions(permissions: string[]): [string, string[]][] {
    const map = new Map<string, string[]>();
    for (const name of permissions) {
        const group = name.split(".")[0] ?? name;
        map.set(group, [...(map.get(group) ?? []), name]);
    }

    return [...map.entries()];
}

/**
 * Mockup "Granular Operational Permissions Matrix": permission rows (plain-language label + code) x role
 * columns of real checkboxes. Toggling only edits a local draft; nothing is sent until Save (per role, or
 * Save all / Ctrl+S for every changed role). Cancel/Reset discards. Leaving the page with unsaved edits
 * asks first. The Owner column is locked on; sensitive cells stay locked for non-Owner admins.
 */
export function RoleMatrix({
    permissions,
    roles,
    sensitive_permissions = [],
    is_owner = true,
}: MatrixProps) {
    const { t } = useT();
    const digits = useDigits();
    const [drafts, setDrafts] = useState<Record<number, Draft>>({});
    const [saving, setSaving] = useState(false);
    const savingRef = useRef(false);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<MatrixRole | null>(null);
    const groups = useMemo(() => groupPermissions(permissions), [permissions]);

    const draftOf = (role: MatrixRole): Draft =>
        drafts[role.id] ?? {
            permissions: role.permissions,
            max_discount_pct_bp: role.max_discount_pct_bp,
        };
    const isDirty = (role: MatrixRole) => {
        const d = drafts[role.id];

        return (
            d !== undefined &&
            (!sameSet(d.permissions, role.permissions) ||
                d.max_discount_pct_bp !== role.max_discount_pct_bp)
        );
    };
    // The Owner always holds every permission.
    const locked = (role: MatrixRole) =>
        role.is_system && role.name === "Owner";
    const cellLocked = (role: MatrixRole, permission: string) =>
        locked(role) ||
        (!is_owner && sensitive_permissions.includes(permission));
    const has = (role: MatrixRole, permission: string) =>
        locked(role) || draftOf(role).permissions.includes(permission);

    const dirtyRoles = roles.filter(isDirty);
    const dirtyCount = dirtyRoles.length;

    /** Edits one role's draft from the latest state (several roles can change in one click). */
    const edit = (role: MatrixRole, change: (d: Draft) => Draft) =>
        setDrafts((all) => {
            const base = all[role.id] ?? {
                permissions: role.permissions,
                max_discount_pct_bp: role.max_discount_pct_bp,
            };

            return { ...all, [role.id]: change(base) };
        });

    const setPermission = (role: MatrixRole, names: string[], on: boolean) =>
        edit(role, (d) => {
            const editable = names.filter((n) => !cellLocked(role, n));
            const next = on
                ? [...new Set([...d.permissions, ...editable])]
                : d.permissions.filter((p) => !editable.includes(p));

            return { ...d, permissions: next };
        });

    const discard = (role?: MatrixRole) =>
        setDrafts((all) => {
            if (!role) {
                return {};
            }
            const { [role.id]: _gone, ...rest } = all;

            return rest;
        });

    /** One PUT per role with its whole permission set; roles go one after another (a visit cancels the previous). */
    const saveRoles = useCallback(
        async (targets: MatrixRole[]) => {
            for (const role of targets) {
                const d = drafts[role.id];
                if (!d) {
                    continue;
                }
                const ok = await new Promise<boolean>((resolve) => {
                    router.put(
                        `/app/settings/roles/${role.id}`,
                        {
                            permissions: d.permissions,
                            max_discount_pct_bp: d.max_discount_pct_bp,
                        },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setDrafts((all) => {
                                    const { [role.id]: _gone, ...rest } = all;

                                    return rest;
                                });
                                resolve(true);
                            },
                            onError: (errors) => {
                                const first = Object.values(errors)[0];
                                toast.error(
                                    typeof first === "string"
                                        ? first
                                        : t("roles.save_failed"),
                                );
                                resolve(false);
                            },
                            onCancel: () => resolve(false),
                        },
                    );
                });
                if (!ok) {
                    break;
                }
            }
        },
        [drafts, t],
    );

    const saveAll = useCallback(async () => {
        if (saving || dirtyRoles.length === 0) {
            return;
        }
        savingRef.current = true;
        setSaving(true);
        try {
            await saveRoles(dirtyRoles);
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }, [saving, dirtyRoles, saveRoles]);

    const saveOne = async (role: MatrixRole) => {
        if (saving) {
            return;
        }
        savingRef.current = true;
        setSaving(true);
        try {
            await saveRoles([role]);
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    };

    // Warn before leaving with unsaved edits (tab close/reload and in-app visits to another page).
    const dirtyRef = useRef(false);
    useEffect(() => {
        dirtyRef.current = dirtyCount > 0;
    }, [dirtyCount]);
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (dirtyRef.current) {
                event.preventDefault();
            }
        };
        window.addEventListener("beforeunload", beforeUnload);
        const off = router.on("before", (event) => {
            const target = event.detail.visit.url;
            if (
                dirtyRef.current &&
                !savingRef.current &&
                target.pathname !== window.location.pathname &&
                !window.confirm(t("roles.leave_confirm"))
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener("beforeunload", beforeUnload);
            off();
        };
    }, [t]);

    useHotkeys("list", [
        {
            id: "roles.save",
            keys: "mod+s",
            labelKey: "roles.save_all",
            allowInInput: true,
            handler: (event) => {
                event.preventDefault();
                void saveAll();
            },
        },
    ]);

    const duplicate = (role: MatrixRole) => {
        const name = `${role.name} ${t("roles.copy_suffix")}`.slice(0, 60);
        router.post(
            "/app/settings/roles",
            {
                name,
                permissions: draftOf(role).permissions,
                max_discount_pct_bp: draftOf(role).max_discount_pct_bp,
            },
            { preserveScroll: true },
        );
    };

    const cell = "px-2 py-2 text-center";
    const rowHead = "sticky left-0 z-[1] bg-surface px-3 py-2 text-left";

    return (
        <section
            id="roles"
            tabIndex={-1}
            aria-labelledby="roles-title"
            className="scroll-mt-4 overflow-hidden rounded-xl border bg-surface shadow-xs outline-none"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-surface-sunken p-3">
                <div>
                    <h2
                        id="roles-title"
                        className="text-xs font-bold text-foreground"
                    >
                        {t("roles.matrix_title")}
                    </h2>
                    <p className="text-[11px] text-muted-foreground">
                        {t("roles.hint")}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="bg-surface"
                        onClick={() => setCreating(true)}
                    >
                        <Plus aria-hidden="true" />
                        {t("roles.new")}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        disabled={saving || dirtyCount === 0}
                        onClick={() => void saveAll()}
                    >
                        <Save aria-hidden="true" />
                        {t("roles.save_all")}
                        <kbd className="ml-1 text-[10px] opacity-80">
                            Ctrl+S
                        </kbd>
                    </Button>
                </div>
            </div>
            {dirtyCount > 0 ? (
                <output className="flex flex-wrap items-center gap-2 border-b bg-warning-soft px-3 py-2 text-xs">
                    <span className="font-semibold">
                        {t("roles.unsaved", { n: digits.n(dirtyCount) })}
                    </span>
                    <span className="ml-auto flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 px-3 text-xs [@media(pointer:coarse)]:min-h-11"
                            disabled={saving}
                            onClick={() => discard()}
                        >
                            <RotateCcw aria-hidden="true" />
                            {t("admin.cancel")}
                        </Button>
                    </span>
                </output>
            ) : null}
            {!is_owner ? (
                <p className="flex items-center gap-1.5 border-b bg-surface-sunken px-3 py-1.5 text-[11px] text-muted-foreground">
                    <Lock aria-hidden="true" className="size-3" />
                    {t("roles.owner_only_hint")}
                </p>
            ) : null}
            <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <caption className="sr-only">
                        {t("roles.matrix_title")}
                    </caption>
                    <thead className="border-b bg-surface font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" className={cn(rowHead, "py-2.5")}>
                                {t("roles.permission_area")}
                            </th>
                            {roles.map((role) => (
                                <th
                                    key={role.id}
                                    scope="col"
                                    className="min-w-28 px-2 py-2.5 text-center align-bottom"
                                >
                                    <div className="text-foreground">
                                        {role.name}
                                    </div>
                                    <div className="text-[10px] font-normal">
                                        {t("roles.users_count", {
                                            n: digits.n(role.users),
                                        })}
                                    </div>
                                    {isDirty(role) ? (
                                        <div className="mt-1 flex flex-col items-center gap-1">
                                            <span className="rounded-full bg-warning-soft px-2 py-0.5 text-[10px] font-bold text-foreground">
                                                {t("roles.unsaved_badge")}
                                            </span>
                                            <div className="flex gap-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    className="h-7 px-2 text-[11px] [@media(pointer:coarse)]:min-h-11"
                                                    disabled={saving}
                                                    aria-label={`${t("admin.save")}: ${role.name}`}
                                                    onClick={() =>
                                                        void saveOne(role)
                                                    }
                                                >
                                                    <Save aria-hidden="true" />
                                                    {t("admin.save")}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 px-2 text-[11px] [@media(pointer:coarse)]:min-h-11"
                                                    disabled={saving}
                                                    aria-label={`${t("roles.reset")}: ${role.name}`}
                                                    onClick={() =>
                                                        discard(role)
                                                    }
                                                >
                                                    <RotateCcw aria-hidden="true" />
                                                    {t("roles.reset")}
                                                </Button>
                                            </div>
                                        </div>
                                    ) : null}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        <tr className="hover:bg-surface-sunken">
                            <th
                                scope="row"
                                className={cn(
                                    rowHead,
                                    "font-medium text-foreground",
                                )}
                            >
                                {t("roles.max_discount")}
                            </th>
                            {roles.map((role) => (
                                <td key={role.id} className={cell}>
                                    <Input
                                        type="text"
                                        inputMode="decimal"
                                        aria-label={`${role.name}: ${t("roles.max_discount")}`}
                                        className="num mx-auto h-8 w-16 text-right [@media(pointer:coarse)]:h-11"
                                        value={toPercent(
                                            draftOf(role).max_discount_pct_bp,
                                        )}
                                        onChange={(e) =>
                                            edit(role, (d) => ({
                                                ...d,
                                                max_discount_pct_bp:
                                                    fromPercent(e.target.value),
                                            }))
                                        }
                                    />
                                </td>
                            ))}
                        </tr>
                        {groups.map(([group, names]) => (
                            <GroupRows
                                key={group}
                                group={group}
                                names={names}
                                roles={roles}
                                has={has}
                                cellLocked={cellLocked}
                                setPermission={setPermission}
                            />
                        ))}
                        <tr>
                            <th scope="row" className={rowHead}>
                                <span className="sr-only">
                                    {t("admin.actions")}
                                </span>
                            </th>
                            {roles.map((role) => (
                                <td key={role.id} className={cell}>
                                    <div className="flex justify-center gap-1">
                                        {!role.is_system ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="outline"
                                                className="size-8 [@media(pointer:coarse)]:size-11"
                                                aria-label={`${t("roles.duplicate")}: ${role.name}`}
                                                onClick={() => duplicate(role)}
                                            >
                                                <Copy aria-hidden="true" />
                                            </Button>
                                        ) : null}
                                        {!role.is_system ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="outline"
                                                className="size-8 [@media(pointer:coarse)]:size-11"
                                                aria-label={`${t("roles.delete")}: ${role.name}`}
                                                onClick={() =>
                                                    setDeleting(role)
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        ) : null}
                                    </div>
                                </td>
                            ))}
                        </tr>
                    </tbody>
                </table>
            </div>

            {creating ? (
                <RecordDialog
                    title={t("roles.new")}
                    initial={{
                        name: "",
                        permissions: [] as string[],
                        max_discount_pct_bp: 0,
                    }}
                    request={["post", "/app/settings/roles"]}
                    onClose={() => setCreating(false)}
                >
                    {(f) => (
                        <FormField
                            label={t("admin.name")}
                            error={f.error("name")}
                        >
                            <Input
                                value={f.data.name}
                                required
                                autoFocus
                                onChange={(e) => f.set("name", e.target.value)}
                            />
                        </FormField>
                    )}
                </RecordDialog>
            ) : null}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => (open ? undefined : setDeleting(null))}
                title={t("roles.delete")}
                description={t("roles.delete_body")}
                recordName={deleting?.name ?? ""}
                destructive
                onConfirm={() =>
                    deleting
                        ? router.delete(`/app/settings/roles/${deleting.id}`, {
                              preserveScroll: true,
                          })
                        : undefined
                }
            />
        </section>
    );
}

function GroupRows({
    group,
    names,
    roles,
    has,
    cellLocked,
    setPermission,
}: {
    group: string;
    names: string[];
    roles: MatrixRole[];
    has: (role: MatrixRole, permission: string) => boolean;
    cellLocked: (role: MatrixRole, permission: string) => boolean;
    setPermission: (role: MatrixRole, names: string[], on: boolean) => void;
}) {
    const { t, locale } = useT();
    const groupLabel = t(`roles.group.${group}`, { defaultValue: group });

    return (
        <>
            <tr className="bg-surface-sunken">
                <th
                    scope="row"
                    className="sticky left-0 z-[1] bg-surface-sunken px-3 py-1.5 text-left text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                >
                    <span className="flex items-center gap-2">
                        {groupLabel}
                        <span className="flex gap-1">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-6 bg-surface px-1.5 text-[10px] [@media(pointer:coarse)]:min-h-11"
                                onClick={() =>
                                    roles.forEach((r) =>
                                        setPermission(r, names, true),
                                    )
                                }
                            >
                                {t("roles.select_all")}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-6 bg-surface px-1.5 text-[10px] [@media(pointer:coarse)]:min-h-11"
                                onClick={() =>
                                    roles.forEach((r) =>
                                        setPermission(r, names, false),
                                    )
                                }
                            >
                                {t("roles.clear_all")}
                            </Button>
                        </span>
                    </span>
                </th>
                {roles.map((role) => {
                    const editable = names.filter((n) => !cellLocked(role, n));
                    const on = names.filter((n) => has(role, n)).length;
                    const state: boolean | "indeterminate" =
                        on === 0
                            ? false
                            : on === names.length
                              ? true
                              : "indeterminate";

                    return (
                        <td
                            key={role.id}
                            className="bg-surface-sunken px-2 py-1 text-center"
                        >
                            <label
                                htmlFor={`grp-${role.id}-${group}`}
                                className="mx-auto flex h-9 w-full cursor-pointer items-center justify-center [@media(pointer:coarse)]:h-11"
                            >
                                <Checkbox
                                    id={`grp-${role.id}-${group}`}
                                    className={BOX}
                                    checked={state}
                                    disabled={editable.length === 0}
                                    aria-label={`${role.name}: ${groupLabel}`}
                                    onCheckedChange={() =>
                                        setPermission(
                                            role,
                                            names,
                                            state !== true,
                                        )
                                    }
                                />
                            </label>
                        </td>
                    );
                })}
            </tr>
            {names.map((name) => (
                <tr
                    key={name}
                    className="transition-colors hover:bg-surface-sunken"
                >
                    <th
                        scope="row"
                        title={name}
                        className="sticky left-0 z-[1] bg-surface px-3 py-1.5 text-left font-medium text-foreground"
                    >
                        <span className="block">
                            {permissionLabel(t, name)}
                        </span>
                        {/* The technical code helps English-speaking admins; Bangla shows the label only (code on hover). */}
                        {locale === "en" ? (
                            <span className="font-mono text-[10px] font-normal text-muted-foreground">
                                {name}
                            </span>
                        ) : null}
                    </th>
                    {roles.map((role) => {
                        const disabled = cellLocked(role, name);

                        return (
                            <td key={role.id} className="px-2 py-0 text-center">
                                <label
                                    htmlFor={`perm-${role.id}-${name}`}
                                    className={cn(
                                        "mx-auto flex h-11 w-full items-center justify-center gap-1 rounded-lg",
                                        disabled
                                            ? "cursor-not-allowed"
                                            : "cursor-pointer",
                                    )}
                                >
                                    <Checkbox
                                        id={`perm-${role.id}-${name}`}
                                        className={BOX}
                                        checked={has(role, name)}
                                        disabled={disabled}
                                        aria-label={`${role.name}: ${permissionLabel(t, name)}`}
                                        onCheckedChange={(value) =>
                                            setPermission(
                                                role,
                                                [name],
                                                value === true,
                                            )
                                        }
                                    />
                                </label>
                            </td>
                        );
                    })}
                </tr>
            ))}
        </>
    );
}
