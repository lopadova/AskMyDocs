<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * theme_config — identità grafica per-key del widget KITT embeddabile.
 *
 * JSON nullable: un tema validato/sanificato (colori, tipografia, launcher,
 * pannello) salvato per ogni `widget_key`. È la FONTE DI VERITÀ lato server:
 * `GET /api/widget/setup` lo restituisce così il widget lo applica al boot
 * (consegna dinamica), e l'embed snippet può opzionalmente "congelarlo" inline.
 *
 * Nullable di default: le key esistenti restano sul tema di default (il widget
 * risolve `null` → default), nessun backfill richiesto. R31: la tabella è già
 * tenant-aware, qui aggiungiamo solo una colonna di presentazione (nessun
 * impatto su uniqueness/tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->json('theme_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn('theme_config');
        });
    }
};
