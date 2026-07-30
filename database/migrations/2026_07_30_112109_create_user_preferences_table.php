<?php

declare(strict_types=1);

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
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->index(['user_id', 'team_id']);
        });

        // Una preferencia por usuario, ámbito y clave. Van dos índices parciales porque en
        // Postgres los NULL son distintos entre sí en un índice único: uno solo sobre
        // (user_id, team_id, key) dejaría insertar filas duplicadas de ámbito global.
        DB::statement(
            'CREATE UNIQUE INDEX user_preferences_team_scoped_unique
             ON user_preferences (user_id, team_id, key)
             WHERE team_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX user_preferences_global_unique
             ON user_preferences (user_id, key)
             WHERE team_id IS NULL'
        );
    }
};
