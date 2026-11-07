import { useForm } from '@inertiajs/react';
import { Shield, UserCog } from 'lucide-react';
import type { FormEvent } from 'react';
import { CheckField } from '@/components/admin/check-field';
import { FormField } from '@/components/form-field';
import { Kbd } from '@/components/kbd';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogActions,
    DialogBar,
    DialogBody,
    DialogContent,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useHotkeys } from '@/hotkeys/use-hotkeys';
import { useT } from '@/i18n';

export type StaffRole = {
    id: number;
    name: string;
    is_system: boolean;
    permissions?: string[];
};
export type StaffBranch = { id: number; name: string; code: string };
export type StaffForm = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    phone: string | null;
    is_active: boolean;
    must_change_password: boolean;
    role_ids: number[];
    branch_ids: number[];
};

const toggle = (list: number[], id: number) =>
    list.includes(id) ? list.filter((x) => x !== id) : [...list, id];

/** Digits only, at most 6 (staff PIN is 4–6 digits). */
export const pinDigits = (value: string) =>
    value.replace(/\D/g, '').slice(0, 6);

/**
 * Mockup "Add Staff Member (কর্মী যোগ)" dialog, also used to edit: the existing user form fields (name,
 * sign-in by email/username/phone, password, roles, branches, flags) plus an optional initial register PIN.
 * Ctrl+Enter saves, Esc cancels. Posts to the existing users.store / users.update routes.
 */
export function StaffDialog({
    user,
    roles,
    branches,
    sensitive_permissions = [],
    is_owner = true,
    onClose,
}: {
    /** null = new staff member. */
    user: StaffForm | null;
    roles: StaffRole[];
    branches: StaffBranch[];
    sensitive_permissions?: string[] | undefined;
    is_owner?: boolean | undefined;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        username: user?.username ?? '',
        phone: user?.phone ?? '',
        password: '',
        pin: '',
        is_active: user?.is_active ?? true,
        must_change_password: user?.must_change_password ?? false,
        role_ids: user?.role_ids ?? [],
        branch_ids:
            user?.branch_ids ??
            (branches.length === 1 ? [branches[0]!.id] : []),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const sensitive = (role: StaffRole) =>
        (role.permissions ?? []).some((p) => sensitive_permissions.includes(p));
    const sensitiveSelected = roles
        .filter((role) => form.data.role_ids.includes(role.id))
        .some(sensitive);
    const sensitiveBlocked = sensitiveSelected && !is_owner;

    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        if (sensitiveBlocked) {
            return;
        }
        const options = { preserveScroll: true, onSuccess: onClose };
        if (user) {
            form.put(`/app/settings/users/${user.id}`, options);
        } else {
            form.post('/app/settings/users', options);
        }
    };

    useHotkeys('dialog', [
        {
            id: 'staff.save',
            keys: 'mod+enter',
            labelKey: 'hotkeys.save',
            allowInInput: true,
            inDialog: true,
            handler: (event) => {
                event.preventDefault();
                submit();
            },
        },
    ]);

    return (
        <Dialog open onOpenChange={(open) => (open ? undefined : onClose())}>
            <DialogContent
                layout="panel"
                size="lg"
                aria-describedby={undefined}
            >
                <DialogBar
                    icon={user ? UserCog : Shield}
                    title={user ? t('users.edit_title') : t('users.add_title')}
                    subtitle={t('users.dialog_subtitle')}
                    closeLabel={t('admin.cancel')}
                />
                <form
                    id="staff-form"
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <DialogBody>
                        {errors['form'] ? (
                            <p
                                role="alert"
                                className="rounded-lg bg-danger-soft px-3 py-2 text-xs text-destructive"
                            >
                                {errors['form']}
                            </p>
                        ) : null}
                        <FormField
                            label={`${t('users.full_name')} *`}
                            error={errors['name']}
                        >
                            <Input
                                name="name"
                                value={form.data.name}
                                required
                                autoFocus
                                className="font-semibold"
                                placeholder={t('users.full_name_ph')}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </FormField>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <FormField
                                label={t('admin.email')}
                                error={errors['email']}
                            >
                                <Input
                                    type="email"
                                    name="email"
                                    autoComplete="off"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                label={t('users.username')}
                                error={errors['username']}
                            >
                                <Input
                                    name="username"
                                    autoComplete="off"
                                    value={form.data.username}
                                    onChange={(e) =>
                                        form.setData('username', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                label={t('admin.phone')}
                                error={errors['phone']}
                            >
                                <Input
                                    type="tel"
                                    name="phone"
                                    autoComplete="off"
                                    className="font-mono"
                                    value={form.data.phone}
                                    onChange={(e) =>
                                        form.setData('phone', e.target.value)
                                    }
                                />
                            </FormField>
                        </div>
                        <p className="-mt-1 text-[11px] text-muted-foreground">
                            {t('users.login_hint')}
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField
                                label={
                                    user
                                        ? t('users.new_password')
                                        : `${t('users.password')} *`
                                }
                                hint={
                                    user ? t('users.password_keep') : undefined
                                }
                                error={errors['password']}
                            >
                                <Input
                                    type="password"
                                    name="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(e) =>
                                        form.setData('password', e.target.value)
                                    }
                                />
                            </FormField>
                            {user ? null : (
                                <FormField
                                    label={t('users.initial_pin')}
                                    hint={t('users.pin_hint')}
                                    error={errors['pin']}
                                >
                                    <Input
                                        type="password"
                                        name="pin"
                                        inputMode="numeric"
                                        autoComplete="off"
                                        maxLength={6}
                                        placeholder="••••"
                                        className="text-center font-mono tracking-widest"
                                        value={form.data.pin}
                                        onChange={(e) =>
                                            form.setData(
                                                'pin',
                                                pinDigits(e.target.value),
                                            )
                                        }
                                    />
                                </FormField>
                            )}
                        </div>
                        <fieldset className="space-y-1">
                            <legend className="text-[11px] font-semibold text-foreground">
                                {t('users.system_roles')} *
                            </legend>
                            <div className="grid gap-x-3 sm:grid-cols-3">
                                {roles.map((role) => {
                                    const ownerOnly = sensitive(role);

                                    return (
                                        <div
                                            key={role.id}
                                            className="flex items-center gap-1"
                                        >
                                            <CheckField
                                                label={role.name}
                                                checked={form.data.role_ids.includes(
                                                    role.id,
                                                )}
                                                onChange={() =>
                                                    form.setData(
                                                        'role_ids',
                                                        toggle(
                                                            form.data.role_ids,
                                                            role.id,
                                                        ),
                                                    )
                                                }
                                            />
                                            {ownerOnly ? (
                                                <span
                                                    className="shrink-0 rounded border px-1 py-0.5 text-[9px] font-bold text-muted-foreground"
                                                    title={t(
                                                        'users.grant_sensitive_body',
                                                    )}
                                                >
                                                    {t('users.owner_only_role')}
                                                </span>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                            {sensitiveBlocked ? (
                                <p
                                    role="alert"
                                    className="rounded-lg bg-danger-soft px-3 py-2 text-xs text-destructive"
                                >
                                    {t('users.grant_sensitive_body')}
                                </p>
                            ) : null}
                            <p
                                className={
                                    errors['role_ids']
                                        ? 'text-[11px] text-destructive'
                                        : 'text-[11px] text-muted-foreground'
                                }
                                role={errors['role_ids'] ? 'alert' : undefined}
                            >
                                {errors['role_ids'] ?? t('users.roles_hint')}
                            </p>
                        </fieldset>
                        <fieldset className="space-y-1">
                            <legend className="text-[11px] font-semibold text-foreground">
                                {t('users.branches')} *
                            </legend>
                            <div className="grid gap-x-3 sm:grid-cols-3">
                                {branches.map((branch) => (
                                    <CheckField
                                        key={branch.id}
                                        label={`${branch.name} (${branch.code})`}
                                        checked={form.data.branch_ids.includes(
                                            branch.id,
                                        )}
                                        onChange={() =>
                                            form.setData(
                                                'branch_ids',
                                                toggle(
                                                    form.data.branch_ids,
                                                    branch.id,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            <p
                                className={
                                    errors['branch_ids']
                                        ? 'text-[11px] text-destructive'
                                        : 'text-[11px] text-muted-foreground'
                                }
                                role={
                                    errors['branch_ids'] ? 'alert' : undefined
                                }
                            >
                                {errors['branch_ids'] ??
                                    t('users.branches_hint')}
                            </p>
                        </fieldset>
                        <div className="grid border-t pt-2 sm:grid-cols-2">
                            <CheckField
                                label={t('users.must_change')}
                                checked={form.data.must_change_password}
                                onChange={(v) =>
                                    form.setData('must_change_password', v)
                                }
                            />
                            {user ? (
                                <CheckField
                                    label={t('admin.active')}
                                    checked={form.data.is_active}
                                    onChange={(v) =>
                                        form.setData('is_active', v)
                                    }
                                />
                            ) : null}
                        </div>
                    </DialogBody>
                    <DialogActions
                        hints={
                            <>
                                <span className="flex items-center gap-1">
                                    <Kbd keys="mod+enter" />
                                    {t('hotkeys.save')}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Kbd keys="escape" />
                                    {t('admin.cancel')}
                                </span>
                            </>
                        }
                    >
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            {t('admin.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || sensitiveBlocked}
                        >
                            {form.processing ? <Spinner /> : null}
                            {user ? t('admin.save') : t('users.save_staff')}
                        </Button>
                    </DialogActions>
                </form>
            </DialogContent>
        </Dialog>
    );
}
