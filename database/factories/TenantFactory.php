<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'phone' => '+8801'.fake()->numerify('#########'),
            'settings' => ['require_customer_on_sale' => false],
        ];
    }

    public function suspended(): self
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        });
    }
}
