<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only: every tenant with no couriers yet gets the common Bangladesh ones (active, none default).
 * Idempotent: a tenant that already has any courier row is left alone. The list is a copy of
 * App\Models\Courier::DEFAULT_NAMES on purpose (a migration must not depend on app code).
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'Steadfast Courier', 'Pathao Courier', 'RedX', 'Sundarban Courier Service', 'SA Paribahan',
            'Janani Express', 'Karatoa Courier Service', 'eCourier', 'Paperfly', 'Delivery Tiger',
        ];
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            if (DB::table('couriers')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }
            DB::table('couriers')->insert(array_map(static fn (string $name): array => [
                'tenant_id' => $tenantId,
                'name' => $name,
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $names));
        }
    }

    public function down(): void
    {
        // Data only: nothing to undo.
    }
};
