<?php

declare(strict_types=1);

namespace App\Support\Permissions;

/** The single source of permission names. */
final class Permission
{
    public const string GUARD = 'web';

    /** @var list<string> */
    public const array ALL = [
        'dashboard.view',
        'pos.sell', 'pos.discount_override', 'pos.price_override', 'pos.backdate', 'pos.sell_below_cost', 'pos.customer_pin_override',
        'devices.manage',
        'sales.view', 'sales.void', 'returns.view', 'returns.create',
        'payments.collect', 'payments.pay',
        'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.reset_pin', 'customers.export',
        'sms.view', 'sms.send',
        'cards.view', 'cards.issue',
        'products.view', 'products.create', 'products.update', 'products.delete', 'products.view_cost',
        'stock.view', 'stock.adjust', 'stock.count', 'stock.transfer',
        'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.cancel',
        'vendors.view', 'vendors.create', 'vendors.update', 'vendors.delete',
        'money.view', 'money.create', 'money.manage',
        'reports.view', 'reports.export', 'reports.profit', 'reports.all_branches',
        'branches.switch', 'users.manage', 'settings.manage', 'audit.view',
        'backup.view', 'backup.run',
    ];

    /**
     * Permissions only an Owner may grant or revoke on a role. A non-Owner holder of
     * users.manage keeps day-to-day staff admin but cannot mint or remove these.
     *
     * @var list<string>
     */
    public const array SENSITIVE = [
        'users.manage', 'settings.manage', 'audit.view', 'backup.view', 'backup.run',
    ];

    /**
     * Expands patterns: `*` = every permission, `prefix.*` = every permission with that prefix.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                $matches = self::ALL;
            } elseif (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);
                $matches = array_values(array_filter(self::ALL, static fn (string $name): bool => str_starts_with($name, $prefix)));
            } else {
                $matches = in_array($pattern, self::ALL, true) ? [$pattern] : [];
            }

            throw_if($matches === [], \InvalidArgumentException::class, 'Unknown permission pattern: '.$pattern);
            array_push($result, ...$matches);
        }

        return array_values(array_unique($result));
    }
}
