<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A purchase may be received without a vendor (fully paid on the spot, no payable). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable(false)->change();
        });
    }
};
