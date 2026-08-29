<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_color_variants', function (Blueprint $table) {
            // New family-color system
            $table->foreignId('family_color_id')
                ->nullable()
                ->after('product_id')
                ->constrained('family_colors')
                ->nullOnDelete();
            $table->foreignId('family_color_child_id')
                ->nullable()
                ->after('family_color_id')
                ->constrained('family_color_children')
                ->nullOnDelete();
        });
        // Old color_id is now optional/deprecated — requires doctrine/dbal for ->change()
        Schema::table('product_color_variants', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Drop family_color_id FK only if it actually exists
        $fk1 = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'product_color_variants'
            AND COLUMN_NAME = 'family_color_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        if (!empty($fk1)) {
            Schema::table('product_color_variants', function (Blueprint $table) {
                $table->dropForeign(['family_color_id']);
            });
        }

        // Drop family_color_child_id FK only if it actually exists
        $fk2 = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'product_color_variants'
            AND COLUMN_NAME = 'family_color_child_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        if (!empty($fk2)) {
            Schema::table('product_color_variants', function (Blueprint $table) {
                $table->dropForeign(['family_color_child_id']);
            });
        }

        // Drop the columns themselves, if present
        Schema::table('product_color_variants', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                'family_color_id',
                'family_color_child_id',
            ], fn ($col) => Schema::hasColumn('product_color_variants', $col));
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });

        if (Schema::hasColumn('product_color_variants', 'color_id')) {
            Schema::table('product_color_variants', function (Blueprint $table) {
                $table->foreignId('color_id')->nullable(false)->change();
            });
        }
    }
};
