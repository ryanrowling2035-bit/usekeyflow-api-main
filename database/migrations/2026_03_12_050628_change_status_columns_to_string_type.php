<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Users: tinyInteger(0/1) → string('Active'/'InActive')
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default(Constants::USER_STATUS['active'])->change();
        });
        DB::table('users')->where('status', '0')->update(['status' => Constants::USER_STATUS['inActive']]);
        DB::table('users')->where('status', '1')->update(['status' => Constants::USER_STATUS['active']]);

        // Teams: tinyInteger(0/1) → string('Active'/'InActive')
        Schema::table('teams', function (Blueprint $table) {
            $table->string('status')->default(Constants::USER_STATUS['active'])->change();
        });
        DB::table('teams')->where('status', '0')->update(['status' => Constants::USER_STATUS['inActive']]);
        DB::table('teams')->where('status', '1')->update(['status' => Constants::USER_STATUS['active']]);

        // ManageEmails: integer(0/1) → string('Active'/'InActive')
        Schema::table('manage_emails', function (Blueprint $table) {
            $table->string('status')->default(Constants::USER_STATUS['active'])->change();
        });
        DB::table('manage_emails')->where('status', '0')->update(['status' => Constants::USER_STATUS['inActive']]);
        DB::table('manage_emails')->where('status', '1')->update(['status' => Constants::USER_STATUS['active']]);

        // PropertyCategories: enum('Active','InActive') → string('Active'/'InActive')
        Schema::table('property_categories', function (Blueprint $table) {
            $table->string('status')->default(Constants::USER_STATUS['active'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert data BEFORE changing column type (string → int would lose 'Active'/'InActive')
        DB::table('users')->where('status', Constants::USER_STATUS['active'])->update(['status' => '1']);
        DB::table('users')->where('status', Constants::USER_STATUS['inActive'])->update(['status' => '0']);
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->change();
        });

        DB::table('teams')->where('status', Constants::USER_STATUS['active'])->update(['status' => '1']);
        DB::table('teams')->where('status', Constants::USER_STATUS['inActive'])->update(['status' => '0']);
        Schema::table('teams', function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->change();
        });

        DB::table('manage_emails')->where('status', Constants::USER_STATUS['active'])->update(['status' => '1']);
        DB::table('manage_emails')->where('status', Constants::USER_STATUS['inActive'])->update(['status' => '0']);
        Schema::table('manage_emails', function (Blueprint $table) {
            $table->integer('status')->default(1)->change();
        });

        Schema::table('property_categories', function (Blueprint $table) {
            $table->enum('status', ['Active', 'InActive'])->default('Active')->change();
        });
    }
};
