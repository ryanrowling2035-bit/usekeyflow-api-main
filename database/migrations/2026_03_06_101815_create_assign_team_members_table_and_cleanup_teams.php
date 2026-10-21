<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assign_team_members')) {
            Schema::create('assign_team_members', function (Blueprint $table) {
                $table->id();
                $table->integer('teamId');
                $table->integer('userId');
                $table->string('userType');
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('teams', 'managerId')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn(['managerId', 'agentId']);
            });
        }

        if (Schema::hasColumn('users', 'teamId')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('teamId');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'teamId')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('teamId')->nullable();
            });
        }

        if (! Schema::hasColumn('teams', 'managerId')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->json('managerId')->nullable();
                $table->json('agentId')->nullable();
            });
        }

        Schema::dropIfExists('assign_team_members');
    }
};
