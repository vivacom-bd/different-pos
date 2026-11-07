import { Head, router } from '@inertiajs/react';
import { KeyRound, Plus, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ResetPinDialog } from '@/components/admin/reset-pin-dialog';
import { type MatrixRole, RoleMatrix } from '@/components/admin/role-matrix';
import {
    type StaffBranch,
    StaffDialog,
    type StaffForm,
    type StaffRole,
} from '@/components/admin/staff-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable, type DataColumn } from '@/components/data-table/data-table';
import {
    buildTableQuery,
    useServerTable,
} from '@/components/data-table/server-table';
import { EmptyState } from '@/components/empty-state';
import { Kbd } from '@/components/kbd';
import { Page, PageHeader } from '@/components/page-header';
import { TableToolbar } from '@/components/table-toolbar';
import { Button } from '@/components/ui/button';
import { actions, type AppAction } from '@/command/actions';
import { useDigits } from '@/hooks/use-digits';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';

type UserRow = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    phone: string | null;
    is_active: boolean;
    has_pin: boolean;
    pin_locked: boolean;
    last_login_at: string | null;
    roles: string[];
    branches: string[];
};

type Props = {
    users: UserRow[];
    meta: { page: number; perPage: number; total: number; lastPage: number };
    roles: StaffRole[];
    branches: StaffBranch[];
    matrix: {
        permissions: string[];
        roles: MatrixRole[];
        sensitive_permissions?: string[];
        is_owner?: boolean;
    };
    business: string;
    dialog: 'create' | 'edit' | null;
    editing: StaffForm | null;
    focus: 'roles' | null;
};

const LIST = '/app/settings/users';
const editUrl = (row: { id: number }) => `${LIST}/${row.id}/edit`;

const resetPinAction: AppAction<UserRow> = {
    id: 'user.reset_pin',
    entity: 'user',
    labelKey: 'users.reset_pin',
    icon: KeyRound,
    keys: 'p',
    permission: 'users.manage',
    requires: 'handler',
    visible: (row) => row.is_active,
    run: ({ row, handlers }) => handlers?.['user.reset_pin']?.(row),
};

/**
 * Users & Roles (baseline mockup screen 20): "Staff Users & Role Permissions" with the staff table (role
 * chips, Reset PIN), the Add/Edit Staff dialog and the permission matrix below. /app/settings/users/create,
 * /users/{id}/edit and /app/settings/roles are deep links into this one page.
 */
export default function UsersIndex({
    users,
    meta,
    roles,
    branches,
    matrix,
    business,
    dialog,
    editing,
    focus,
}: Props) {
    const { t } = useT();
    const digits = useDigits();
    const table = useServerTable({ only: ['users', 'meta'] });
    const [staff, setStaff] = useState<StaffForm | 'new' | null>(
        dialog === 'create'
            ? 'new'
            : dialog === 'edit' && editing
              ? editing
              : null,
    );
    const [pinFor, setPinFor] = useState<UserRow | null>(null);
    const [deactivating, setDeactivating] = useState<UserRow | null>(null);
    const [reactivating, setReactivating] = useState<UserRow | null>(null);

    useEffect(
        () => actions.register(resetPinAction as unknown as AppAction<never>),
        [],
    );
    useEffect(() => {
        if (focus === 'roles') {
            const el = document.getElementById('roles');
            el?.scrollIntoView({ block: 'start' });
            el?.focus({ preventScroll: true });
        }
    }, [focus]);

    const openNew = () => setStaff('new');
    const closeStaff = () => {
        setStaff(null);
        if (window.location.pathname !== LIST) {
            // Leave the deep link so a reload does not reopen the dialog.
            router.get(LIST, buildTableQuery(table.state), {
                replace: true,
                preserveState: true,
                preserveScroll: true,
            });
        }
    };

    useHotkeys('list', [
        {
            id: 'users.new',
            keys: 'n',
            labelKey: 'users.add_staff',
            handler: openNew,
        },
        {
            id: 'users.roles',
            keys: 'shift+r',
            labelKey: 'roles.matrix_title',
            handler: () => {
                const el = document.getElementById('roles');
                el?.scrollIntoView({ block: 'start', behavior: 'smooth' });
                el?.focus({ preventScroll: true });
            },
        },
    ]);

    const handlers = useMemo(
        () => ({
            'row.edit': (row: UserRow) =>
                router.visit(editUrl(row), { preserveScroll: true }),
            'user.reset_pin': (row: UserRow) => setPinFor(row),
            'user.deactivate': (row: UserRow) =>
                row.is_active ? setDeactivating(row) : undefined,
            'user.reactivate': (row: UserRow) =>
                !row.is_active ? setReactivating(row) : undefined,
        }),
        [],
    );

    const chip = 'rounded-full px-2 py-0.5 text-[10px] font-bold';
    const columns: DataColumn<UserRow>[] = [
        {
            id: 'name',
            headerKey: 'admin.name',
            sortKey: 'name',
            required: true,
            mobile: 'title',
            cell: (r) => (
                <div className="min-w-0">
                    <div className="font-semibold text-foreground">
                        {r.name}
                    </div>
                    <div className="truncate text-[10px] text-muted-foreground">
                        {r.email ??
                            r.username ??
                            (r.phone ? digits.n(r.phone) : '')}
                    </div>
                </div>
            ),
        },
        {
            id: 'roles',
            headerKey: 'users.role',
            cell: (r) =>
                r.roles.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {r.roles.map((role) => (
                            <span
                                key={role}
                                className={cn(
                                    chip,
                                    'bg-primary-soft text-primary',
                                )}
                            >
                                {role}
                            </span>
                        ))}
                    </div>
                ) : (
                    '-'
                ),
        },
        {
            id: 'branches',
            headerKey: 'users.assigned_branch',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {r.branches.length === branches.length &&
                    branches.length > 1
                        ? t('users.all_branches')
                        : r.branches.join(', ') || '-'}
                </span>
            ),
        },
        {
            id: 'last_login_at',
            headerKey: 'users.last_active',
            sortKey: 'last_login_at',
            cell: (r) => (
                <span className="num text-muted-foreground">
                    {r.last_login_at
                        ? digits.n(
                              r.last_login_at.slice(0, 16).replace('T', ' '),
                          )
                        : t('users.never')}
                </span>
            ),
        },
        {
            id: 'status',
            headerKey: 'admin.status',
            cell: (r) => (
                <div className="flex flex-wrap gap-1">
                    <span
                        className={cn(
                            'rounded px-1.5 py-0.5 text-[10px] font-medium',
                            r.is_active
                                ? 'bg-success-soft text-success'
                                : 'bg-surface-sunken text-muted-foreground',
                        )}
                    >
                        {t(r.is_active ? 'admin.active' : 'admin.inactive')}
                    </span>
                    {r.pin_locked ? (
                        <span className="rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-medium text-destructive">
                            {t('users.pin_locked')}
                        </span>
                    ) : !r.has_pin ? (
                        <span className="rounded bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium text-warning">
                            {t('users.no_pin')}
                        </span>
                    ) : null}
                </div>
            ),
        },
        {
            id: 'actions',
            headerKey: 'admin.actions',
            align: 'center',
            required: true,
            cell: (r) =>
                r.is_active ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-6 bg-surface-sunken px-2 text-[10px] hover:border-primary [@media(pointer:coarse)]:min-h-11"
                        aria-label={`${t('users.reset_pin')}: ${r.name}`}
                        // The table's Enter opens the row; this button keeps its own Enter/Space.
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.stopPropagation();
                            }
                        }}
                        onClick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            setPinFor(r);
                        }}
                    >
                        {t('users.reset_pin')}
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-6 bg-surface-sunken px-2 text-[10px] hover:border-primary [@media(pointer:coarse)]:min-h-11"
                        aria-label={`${t('users.reactivate')}: ${r.name}`}
                        onClick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            setReactivating(r);
                        }}
                    >
                        {t('users.reactivate')}
                    </Button>
                ),
        },
    ];

    return (
        <>
            <Head title={t('users.page_title')} />
            <Page>
                <PageHeader
                    title={t('users.page_title')}
                    subtitle={t('users.page_subtitle')}
                    className="border-b pb-3"
                    actions={
                        <Button type="button" onClick={openNew}>
                            <Plus aria-hidden="true" />
                            {t('users.add_staff')}
                            <Kbd keys="n" onButton />
                        </Button>
                    }
                />

                <section
                    aria-labelledby="staff-title"
                    className="flex flex-col"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-t-xl border border-b-0 bg-surface-sunken p-3">
                        <h2
                            id="staff-title"
                            className="text-xs font-bold text-foreground"
                        >
                            {t('users.staff_title', { business })}
                            <span className="num ml-2 rounded-full border bg-surface px-2 py-0.5 font-mono text-[10px] text-muted-foreground">
                                {digits.n(meta.total)}
                            </span>
                        </h2>
                        <TableToolbar
                            search={table.searchText}
                            onSearch={table.setSearch}
                            placeholderKey="users.search"
                            className="border-0 bg-transparent p-0"
                            filters={[
                                {
                                    key: 'status',
                                    labelKey: 'admin.status',
                                    value: table.state.filters['status'] ?? '',
                                    options: [
                                        {
                                            value: 'active',
                                            label: t('admin.active'),
                                        },
                                        {
                                            value: 'inactive',
                                            label: t('admin.inactive'),
                                        },
                                    ],
                                    onChange: (v) =>
                                        table.setFilter('status', v),
                                },
                                {
                                    key: 'role',
                                    labelKey: 'users.roles',
                                    value: table.state.filters['role'] ?? '',
                                    options: roles.map((r) => ({
                                        value: String(r.id),
                                        label: r.name,
                                    })),
                                    onChange: (v) => table.setFilter('role', v),
                                },
                            ]}
                        />
                    </div>
                    <div className="[&>div>div:first-child]:rounded-t-none">
                        <DataTable<UserRow>
                            entity="user"
                            caption={t('users.staff_title', { business })}
                            rows={users}
                            columns={columns}
                            meta={meta}
                            getRowId={(r) => String(r.id)}
                            rowHref={editUrl}
                            handlers={handlers}
                            sort={table.state.sort}
                            onSort={table.toggleSort}
                            onPage={table.setPage}
                            loading={table.loading}
                            selectable={false}
                            chooser="none"
                            empty={
                                <EmptyState
                                    icon={Users}
                                    title={t('users.empty')}
                                />
                            }
                        />
                    </div>
                </section>

                <RoleMatrix
                    permissions={matrix.permissions}
                    roles={matrix.roles}
                    sensitive_permissions={matrix.sensitive_permissions}
                    is_owner={matrix.is_owner}
                />
            </Page>

            {staff !== null ? (
                <StaffDialog
                    key={staff === 'new' ? 'new' : staff.id}
                    user={staff === 'new' ? null : staff}
                    roles={roles}
                    branches={branches}
                    sensitive_permissions={matrix.sensitive_permissions}
                    is_owner={matrix.is_owner}
                    onClose={closeStaff}
                />
            ) : null}
            {pinFor ? (
                <ResetPinDialog user={pinFor} onClose={() => setPinFor(null)} />
            ) : null}
            <ConfirmDialog
                open={deactivating !== null}
                onOpenChange={(open) =>
                    open ? undefined : setDeactivating(null)
                }
                title={t('users.deactivate_title')}
                description={t('users.deactivate_body')}
                confirmLabel={t('users.deactivate')}
                recordName={deactivating?.name ?? ''}
                destructive
                onConfirm={() =>
                    deactivating
                        ? router.post(
                              `${LIST}/${deactivating.id}/deactivate`,
                              {},
                              { preserveScroll: true },
                          )
                        : undefined
                }
            />
            <ConfirmDialog
                open={reactivating !== null}
                onOpenChange={(open) =>
                    open ? undefined : setReactivating(null)
                }
                title={t('users.reactivate_title')}
                description={t('users.reactivate_body')}
                confirmLabel={t('users.reactivate')}
                recordName={reactivating?.name ?? ''}
                onConfirm={() =>
                    reactivating
                        ? router.post(
                              `${LIST}/${reactivating.id}/reactivate`,
                              {},
                              { preserveScroll: true },
                          )
                        : undefined
                }
            />
        </>
    );
}
