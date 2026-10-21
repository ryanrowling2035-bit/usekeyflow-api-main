<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert existing string values to JSON arrays before changing column type
        $rows = DB::table('marketing_assets')
            ->whereNotNull('fileName')
            ->where('fileName', '!=', '')
            ->get(['id', 'fileName', 'filePath']);

        foreach ($rows as $row) {
            DB::table('marketing_assets')
                ->where('id', $row->id)
                ->update([
                    'fileName' => json_encode([$row->fileName]),
                    'filePath' => json_encode([$row->filePath]),
                ]);
        }

        // Set remaining nulls/empty to valid JSON
        DB::table('marketing_assets')
            ->where(function ($q) {
                $q->whereNull('fileName')->orWhere('fileName', '');
            })
            ->update(['fileName' => null, 'filePath' => null]);

        Schema::table('marketing_assets', function (Blueprint $table) {
            $table->json('fileName')->nullable()->change();
            $table->json('filePath')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_assets', function (Blueprint $table) {
            $table->string('fileName')->nullable()->change();
            $table->string('filePath')->nullable()->change();
        });
    }
};
