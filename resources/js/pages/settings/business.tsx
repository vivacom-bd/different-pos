import { Head, useForm } from '@inertiajs/react';
import { type ComponentProps, type FormEvent, useCallback } from 'react';
import { BusinessLogo } from '@/components/admin/business-logo';
import { HubCard, HubSection, HubToggle } from '@/components/admin/hub-section';
import { FormField } from '@/components/form-field';
import { MoneyInput } from '@/components/money-input';
import { NativeSelect } from '@/components/native-select';
import { Input } from '@/components/ui/input';
import { useT } from '@/i18n';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout, { useSettingsSave } from '@/layouts/settings/layout';

type Business = {
    name: string;
    slug: string;
    legal_name: string | null;
    vat_bin: string | null;
    vat_registered_address: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    currency: string;
    timezone: string;
    locale: string;
    tax_inclusive: boolean;
    backdate_days: number;
    return_days: number | null;
    default_unit_id: number | null;
    default_credit_limit: number | null;
    customer_pin_policy: string;
    offline_credit_cap: number;
    logo_url: string | null;
};

type TextKey =
    | 'name'
    | 'legal_name'
    | 'vat_bin'
    | 'vat_registered_address'
    | 'phone'
    | 'email'
    | 'address'
    | 'currency';

/**
 * Settings hub → Business Profile (mockup "Business & Company Profile"), plus the business-wide rules the
 * menu's Language & Regional, Returns & Exchanges and Customer PIN Policy entries jump to (#anchors).
 * One form, saved by the hub's "Save Changes (Ctrl+S)"; the logo keeps its own upload form.
 */
export default function BusinessSettings({
    business,
    timezones,
    units,
}: {
    business: Business;
    timezones: string[];
    units: { id: number; name: string; short_name: string }[];
}) {
    const { t } = useT();
    const form = useForm({
        name: business.name,
        legal_name: business.legal_name ?? '',
        vat_bin: business.vat_bin ?? '',
        vat_registered_address: business.vat_registered_address ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        address: business.address ?? '',
        currency: business.currency,
        timezone: business.timezone,
        locale: business.locale,
        tax_inclusive: business.tax_inclusive,
        backdate_days: business.backdate_days,
        return_days: business.return_days as number | null,
        default_unit_id: business.default_unit_id as number | null,
        default_credit_limit: business.default_credit_limit as number | null,
        customer_pin_policy: business.customer_pin_policy,
        offline_credit_cap: business.offline_credit_cap,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const { put, reset, clearErrors } = form;
    const save = useCallback(
        () => put('/app/settings/business', { preserveScroll: true }),
        [put],
    );
    const discard = useCallback(() => {
        reset();
        clearErrors();
    }, [reset, clearErrors]);
    useSettingsSave({
        save,
        dirty: form.isDirty,
        processing: form.processing,
        discard,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save();
    };

    const field = (
        key: TextKey,
        label: string,
        extra: ComponentProps<typeof Input> = {},
    ) => (
        <FormField label={label} error={errors[key]}>
            <Input
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
                {...extra}
            />
        </FormField>
    );

    return (
        <>
            <Head title={t('business.title')} />
            <div className="space-y-8">
                {errors['form'] ? (
                    <p
                        role="alert"
                        className="max-w-3xl rounded-md bg-danger-soft px-3 py-2 text-sm text-destructive"
                    >
                        {errors['form']}
                    </p>
                ) : null}
                <form
                    id="business-settings"
                    onSubmit={submit}
                    className="space-y-8"
                >
                    <HubSection
                        title={t('business.hub_profile')}
                        subtitle={
                            <>
                                {t('business.hub_profile_hint')}{' '}
                                {t('business.code_is')}{' '}
                                <span className="font-mono font-semibold text-foreground">
                                    {business.slug}
                                </span>
                            </>
                        }
                    >
                        <HubCard>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {field('name', t('business.name'), {
                                    required: true,
                                    autoFocus: true,
                                    className: 'font-bold',
                                })}
                                {field('legal_name', t('business.legal_name'))}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {field('vat_bin', t('business.vat_bin'), {
                                    className: 'font-mono font-bold',
                                })}
                                {field(
                                    'vat_registered_address',
                                    t('business.vat_address'),
                                )}
                            </div>
                            {field('address', t('admin.address'))}
                            <div className="grid gap-3 sm:grid-cols-2">
                                {field('phone', t('admin.phone'), {
                                    type: 'tel',
                                    className: 'font-mono',
                                })}
                                {field('email', t('admin.email'), {
                                    type: 'email',
                                })}
                            </div>
                            <div className="grid gap-3 border-t pt-2 sm:grid-cols-2">
                                <FormField label={t('business.currency')}>
                                    <Input
                                        value={form.data.currency}
                                        disabled
                                        readOnly
                                    />
                                </FormField>
                                <FormField label={t('business.timezone')}>
                                    <Input
                                        value={form.data.timezone}
                                        disabled
                                        readOnly
                                    />
                                </FormField>
                            </div>
                        </HubCard>
                    </HubSection>

                    {/* The logo uploads on its own (multipart, its own buttons); it renders no <form>. */}
                    <BusinessLogo
                        name={business.name}
                        logoUrl={business.logo_url}
                    />

                    <HubSection
                        id="regional"
                        title={t('business.section_regional')}
                        subtitle={t('business.regional_hint')}
                    >
                        <HubCard>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <FormField
                                    label={t('business.locale')}
                                    error={errors['locale']}
                                >
                                    <NativeSelect
                                        value={form.data.locale}
                                        onChange={(e) =>
                                            form.setData(
                                                'locale',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="en">English</option>
                                        <option value="bn">বাংলা</option>
                                    </NativeSelect>
                                </FormField>
                                <FormField
                                    label={t('business.timezone')}
                                    error={errors['timezone']}
                                >
                                    <NativeSelect
                                        value={form.data.timezone}
                                        onChange={(e) =>
                                            form.setData(
                                                'timezone',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {timezones.map((tz) => (
                                            <option key={tz} value={tz}>
                                                {tz}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                                {field('currency', t('business.currency'), {
                                    maxLength: 3,
                                    className: 'uppercase font-mono',
                                })}
                            </div>
                        </HubCard>
                    </HubSection>

                    <HubSection
                        id="sales-rules"
                        title={t('business.section_sales')}
                        subtitle={t('business.sales_hint')}
                    >
                        <HubCard>
                            <HubToggle
                                label={t('business.tax_inclusive')}
                                description={t('business.tax_inclusive_hint')}
                                checked={form.data.tax_inclusive}
                                onChange={(v) =>
                                    form.setData('tax_inclusive', v)
                                }
                            />
                            <FormField
                                label={t('business.default_unit')}
                                hint={t('business.default_unit_hint')}
                                error={errors['default_unit_id']}
                            >
                                <select
                                    className="h-9 w-full rounded-lg border bg-surface-sunken px-2.5 text-sm text-foreground outline-none focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 [@media(pointer:coarse)]:min-h-11"
                                    value={form.data.default_unit_id ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'default_unit_id',
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                >
                                    {units.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name} ({u.short_name})
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField
                                    label={t('business.return_days')}
                                    hint={t('business.return_hint')}
                                    error={errors['return_days']}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        className="num font-mono"
                                        value={form.data.return_days ?? ''}
                                        onChange={(e) =>
                                            form.setData(
                                                'return_days',
                                                e.target.value === ''
                                                    ? null
                                                    : Number(e.target.value),
                                            )
                                        }
                                    />
                                </FormField>
                                <FormField
                                    label={t('business.backdate_days')}
                                    hint={t('business.backdate_hint')}
                                    error={errors['backdate_days']}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        max={365}
                                        className="num font-mono"
                                        value={form.data.backdate_days}
                                        onChange={(e) =>
                                            form.setData(
                                                'backdate_days',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </FormField>
                            </div>
                            <div className="space-y-3 border-t pt-2">
                                <div className="text-xs font-bold text-foreground">
                                    {t('business.credit_terms')}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField
                                        label={t('business.credit_limit')}
                                        hint={t('business.credit_limit_hint')}
                                        error={errors['default_credit_limit']}
                                    >
                                        <MoneyInput
                                            value={
                                                form.data.default_credit_limit
                                            }
                                            onChange={(v) =>
                                                form.setData(
                                                    'default_credit_limit',
                                                    v,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        label={t('business.offline_credit_cap')}
                                        hint={t('business.offline_credit_hint')}
                                        error={errors['offline_credit_cap']}
                                    >
                                        <MoneyInput
                                            value={form.data.offline_credit_cap}
                                            onChange={(v) =>
                                                form.setData(
                                                    'offline_credit_cap',
                                                    v ?? 0,
                                                )
                                            }
                                        />
                                    </FormField>
                                </div>
                            </div>
                        </HubCard>
                    </HubSection>

                    <HubSection
                        id="pin-policy"
                        title={t('business.pin_section')}
                        subtitle={t('business.pin_section_hint')}
                    >
                        <HubCard>
                            <FormField
                                label={t('business.pin_policy')}
                                error={errors['customer_pin_policy']}
                            >
                                <NativeSelect
                                    value={form.data.customer_pin_policy}
                                    onChange={(e) =>
                                        form.setData(
                                            'customer_pin_policy',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="off">
                                        {t('business.pin_off')}
                                    </option>
                                    <option value="optional">
                                        {t('business.pin_optional')}
                                    </option>
                                    <option value="required">
                                        {t('business.pin_required')}
                                    </option>
                                </NativeSelect>
                            </FormField>
                            <p className="text-[11px] text-muted-foreground">
                                {t('business.pin_policy_hint')}
                            </p>
                        </HubCard>
                    </HubSection>
                    {/* Enter in a field submits; the visible Save is the hub header's button. */}
                    <button type="submit" className="sr-only" tabIndex={-1}>
                        {t('admin.save')}
                    </button>
                </form>
            </div>
        </>
    );
}

BusinessSettings.layout = [AppLayout, SettingsLayout];
