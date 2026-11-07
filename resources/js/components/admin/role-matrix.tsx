import { router } from '@inertiajs/react';
import { Check, Copy, Lock, Plus, Save, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { RecordDialog } from '@/components/admin/record-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDigits } from '@/hooks/use-digits';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import { permissionLabel } from '@/lib/labels';

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

const sameSet = (a: string[], b: string[]) =>
    a.length === b.length && a.every((p) => b.includes(p));

/** Basis points <-> a percent text with up to two decimals ("12.5"). */
export const toPercent = (bp: number) => String(bp / 100);
export const fromPercent = (text: string) =>
    Math.min(
        10000,
        Math.max(0, Math.round(Number.parseFloat(text || '0') * 100) || 0),
    );

/** Permissions grouped by their prefix ("sales.void" → "sales"), in Permission::ALL order. */
export function groupPermissions(permissions: string[]): [string, string[]][] {
    const map = new Map<string, string[]>();
    for (const name of permissions) {
        const group = name.split('.')[0] ?? name;
        map.set(group, [...(map.get(group) ?? []), name]);
    }

    return [...map.entries()];
}

/**
 * Mockup "Granular Operational Permissions Matrix": permission rows (plain-language label + code) × role
 * columns with the ✓ / — marks. Each cell is a toggle (Space/Enter); the Owner column is locked on.
 * Sensitive cells stay locked for non-Owner admins. A dirty banner offers Save-all; custom roles can be
 * duplicated, added and, unused, deleted.
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
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<MatrixRole | null>(null);
    const groups = useMemo(() => groupPermissions(permissions), [permissions]);

    const draftOf = (role: MatrixRole): Draft =>
        drafts[role.id] ?? {
            permissions: role.permissions,
            max_discount_pct_bp: role.max_discount_pct_bp,
        };
    const dirty = (role: MatrixRole) => {
        const d = drafts[role.id];

        return (
            d !== undefined &&
            (!sameSet(d.permissions, role.permissions) ||
                d.max_discount_pct_bp !== role.max_discount_pct_bp)
        );
    };
    // The Owner always holds every permission.
    const locked = (role: MatrixRole) =>
        role.is_system && role.name === 'Owner';
    const cellLocked = (role: MatrixRole, permission: string) =>
        locked(role) ||
        (!is_owner && sensitive_permissions.includes(permission));

    const dirtyCount = useMemo(
        () => roles.filter(dirty).length,
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [drafts, roles],
    );
    const saveAll = () => roles.filter(dirty).forEach((role) => save(role));
    const discardDrafts = () => setDrafts({});

    const duplicate = (role: MatrixRole) => {
        const name = `${role.name} ${t('roles.copy_suffix')}`.slice(0, 60);
        router.post(
            '/app/settings/roles',
            {
                name,
                permissions: draftOf(role).permissions,
                max_discount_pct_bp: draftOf(role).max_discount_pct_bp,
            },
            { preserveScroll: true },
        );
    };

    const toggle = (role: MatrixRole, permission: string) => {
        const current = draftOf(role);
        const next = current.permissions.includes(permission)
            ? current.permissions.filter((p) => p !== permission)
            : [...current.permissions, permission];
        setDrafts({ ...drafts, [role.id]: { ...current, permissions: next } });
    };

    const save = (role: MatrixRole) => {
        const d = draftOf(role);
        router.put(
            `/app/settings/roles/${role.id}`,
            {
                permissions: d.permissions,
                max_discount_pct_bp: d.max_discount_pct_bp,
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setDrafts(({ [role.id]: _gone, ...rest }) => rest),
            },
        );
    };

    const cell = 'px-2 py-2 text-center';
    const rowHead = 'sticky left-0 z-[1] bg-surface px-3 py-2 text-left';

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
                        {t('roles.matrix_title')}
                    </h2>
                    <p className="text-[11px] text-muted-foreground">
                        {t('roles.hint')}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="bg-surface"
                    onClick={() => setCreating(true)}
                >
                    <Plus aria-hidden="true" />
                    {t('roles.new')}
                </Button>
            </div>
            {dirtyCount > 0 ? (
                <div className="flex flex-wrap items-center gap-2 border-b bg-warning-soft px-3 py-2 text-xs">
                    <span className="font-semibold">
                        {t('roles.unsaved', { n: digits.n(dirtyCount) })}
                    </span>
                    <span className="ml-auto flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            className="h-7 px-2 text-[11px]"
                            onClick={saveAll}
                        >
                            <Save aria-hidden="true" />
                            {t('roles.save_all')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-7 px-2 text-[11px]"
                            onClick={discardDrafts}
                        >
                            {t('admin.cancel')}
                        </Button>
                    </span>
                </div>
            ) : null}
            {!is_owner ? (
                <p className="flex items-center gap-1.5 border-b bg-surface-sunken px-3 py-1.5 text-[11px] text-muted-foreground">
                    <Lock aria-hidden="true" className="size-3" />
                    {t('roles.owner_only_hint')}
                </p>
            ) : null}
            <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <caption className="sr-only">
                        {t('roles.matrix_title')}
                    </caption>
                    <thead className="border-b bg-surface font-semibold text-muted-foreground">
                        <tr>
                            <th scope="col" className={cn(rowHead, 'py-2.5')}>
                                {t('roles.permission_area')}
                            </th>
                            {roles.map((role) => (
                                <th
                                    key={role.id}
                                    scope="col"
                                    className="min-w-24 px-2 py-2.5 text-center align-bottom"
                                >
                                    <div className="text-foreground">
                                        {role.name}
                                    </div>
                                    <div className="text-[10px] font-normal">
                                        {t('roles.users_count', {
                                            n: digits.n(role.users),
                                        })}
                                    </div>
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
                                    'font-medium text-foreground',
                                )}
                            >
                                {t('roles.max_discount')}
                            </th>
                            {roles.map((role) => (
                                <td key={role.id} className={cell}>
                                    <Input
                                        type="text"
                                        inputMode="decimal"
                                        aria-label={`${role.name}: ${t('roles.max_discount')}`}
                                        className="num mx-auto h-7 w-16 text-right"
                                        value={toPercent(
                                            draftOf(role).max_discount_pct_bp,
                                        )}
                                        onChange={(e) =>
                                            setDrafts({
                                                ...drafts,
                                                [role.id]: {
                                                    ...draftOf(role),
                                                    max_discount_pct_bp:
                                                        fromPercent(
                                                            e.target.value,
                                                        ),
                                                },
                                            })
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
                                has={(role, name) =>
                                    locked(role) ||
                                    draftOf(role).permissions.includes(name)
                                }
                                cellLocked={cellLocked}
                                toggle={toggle}
                                toggleAll={(role, target) => {
                                    const current = draftOf(role);
                                    const wanted = target
                                        ? [
                                              ...new Set([
                                                  ...current.permissions,
                                                  ...names.filter(
                                                      (n) =>
                                                          !cellLocked(role, n),
                                                  ),
                                              ]),
                                          ]
                                        : current.permissions.filter(
                                              (p) =>
                                                  !names.includes(p) ||
                                                  cellLocked(role, p),
                                          );
                                    setDrafts({
                                        ...drafts,
                                        [role.id]: {
                                            ...current,
                                            permissions: wanted,
                                        },
                                    });
                                }}
                            />
                        ))}
                        <tr>
                            <th scope="row" className={rowHead}>
                                <span className="sr-only">
                                    {t('admin.actions')}
                                </span>
                            </th>
                            {roles.map((role) => (
                                <td key={role.id} className={cell}>
                                    <div className="flex justify-center gap-1">
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="h-7 px-2 text-[11px] [@media(pointer:coarse)]:min-h-11"
                                            disabled={!dirty(role)}
                                            aria-label={`${t('admin.save')}: ${role.name}`}
                                            onClick={() => save(role)}
                                        >
                                            <Save aria-hidden="true" />
                                            {t('admin.save')}
                                        </Button>
                                        {!role.is_system ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="outline"
                                                className="size-7 [@media(pointer:coarse)]:size-11"
                                                aria-label={`${t('roles.duplicate')}: ${role.name}`}
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
                                                className="size-7 [@media(pointer:coarse)]:size-11"
                                                aria-label={`${t('roles.delete')}: ${role.name}`}
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
                    title={t('roles.new')}
                    initial={{
                        name: '',
                        permissions: [] as string[],
                        max_discount_pct_bp: 0,
                    }}
                    request={['post', '/app/settings/roles']}
                    onClose={() => setCreating(false)}
                >
                    {(f) => (
                        <FormField
                            label={t('admin.name')}
                            error={f.error('name')}
                        >
                            <Input
                                value={f.data.name}
                                required
                                autoFocus
                                onChange={(e) => f.set('name', e.target.value)}
                            />
                        </FormField>
                    )}
                </RecordDialog>
            ) : null}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => (open ? undefined : setDeleting(null))}
                title={t('roles.delete')}
                description={t('roles.delete_body')}
                recordName={deleting?.name ?? ''}
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
    toggle,
    toggleAll,
}: {
    group: string;
    names: string[];
    roles: MatrixRole[];
    has: (role: MatrixRole, permission: string) => boolean;
    cellLocked: (role: MatrixRole, permission: string) => boolean;
    toggle: (role: MatrixRole, permission: string) => void;
    toggleAll: (role: MatrixRole, target: boolean) => void;
}) {
    const { t, locale } = useT();

    return (
        <>
            <tr className="bg-surface-sunken/60">
                <th
                    scope="colgroup"
                    colSpan={roles.length + 1}
                    className="px-3 py-1.5 text-left text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                >
                    <span className="flex items-center gap-2">
                        {t(`roles.group.${group}`)}
                        <span className="flex gap-1">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-5 px-1.5 text-[10px]"
                                onClick={() =>
                                    roles.forEach((r) => toggleAll(r, true))
                                }
                            >
                                {t('roles.select_all')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-5 px-1.5 text-[10px]"
                                onClick={() =>
                                    roles.forEach((r) => toggleAll(r, false))
                                }
                            >
                                {t('roles.clear_all')}
                            </Button>
                        </span>
                    </span>
                </th>
            </tr>
            {names.map((name) => (
                <tr
                    key={name}
                    className="transition-colors hover:bg-surface-sunken"
                >
                    <th
                        scope="row"
                        title={name}
                        className="sticky left-0 z-[1] bg-surface px-3 py-2 text-left font-medium text-foreground"
                    >
                        <span className="block">
                            {permissionLabel(t, name)}
                        </span>
                        {/* The technical code helps English-speaking admins; Bangla shows the label only (code on hover). */}
                        {locale === 'en' ? (
                            <span className="font-mono text-[10px] font-normal text-muted-foreground">
                                {name}
                            </span>
                        ) : null}
                    </th>
                    {roles.map((role) => {
                        const on = has(role, name);
                        const disabled = cellLocked(role, name);

                        return (
                            <td key={role.id} className="px-2 py-1 text-center">
                                <label
                                    className={cn(
                                        'inline-flex size-8 items-center justify-center rounded-lg transition-colors hover:bg-surface-sunken has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 [@media(pointer:coarse)]:size-11',
                                        disabled
                                            ? 'cursor-not-allowed'
                                            : 'cursor-pointer',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={on}
                                        disabled={disabled}
                                        aria-label={`${role.name}: ${permissionLabel(t, name)}`}
                                        onChange={() => toggle(role, name)}
                                    />
                                    {on ? (
                                        <span className="inline-flex size-4 items-center justify-center rounded-full bg-success-soft text-success">
                                            <Check
                                                className="size-3"
                                                strokeWidth={3}
                                                aria-hidden="true"
                                            />
                                        </span>
                                    ) : (
                                        <span
                                            className="text-border"
                                            aria-hidden="true"
                                        >
                                            —
                                        </span>
                                    )}
                                </label>
                            </td>
                        );
                    })}
                </tr>
            ))}
        </>
    );
}
