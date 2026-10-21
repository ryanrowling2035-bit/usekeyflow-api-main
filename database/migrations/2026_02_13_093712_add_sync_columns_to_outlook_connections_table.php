<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlook_connections', function (Blueprint $table) {

            $table->timestamp('lastSyncAt')->nullable()->after('status');
            $table->timestamp('lastEmailSyncAt')->nullable()->after('lastSyncAt');
            $table->timestamp('lastCalendarSyncAt')->nullable()->after('lastEmailSyncAt');
            $table->timestamp('lastTaskSyncAt')->nullable()->after('lastCalendarSyncAt');
            $table->json('syncSettings')->nullable()->after('lastTaskSyncAt');

        });
    }

    public function down(): void
    {
        Schema::table('outlook_connections', function (Blueprint $table) {

            $table->dropColumn([
                'lastSyncAt',
                'lastEmailSyncAt',
                'lastCalendarSyncAt',
                'lastTaskSyncAt',
                'syncSettings',
            ]);

        });
    }
};
