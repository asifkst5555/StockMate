<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'store_limit_override')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('store_limit_override')->nullable()->after('status')
                    ->comment('NULL = use plan limit, -1 = unlimited, >=1 = custom max stores');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'store_limit_override')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('store_limit_override');
            });
        }
    }
};
