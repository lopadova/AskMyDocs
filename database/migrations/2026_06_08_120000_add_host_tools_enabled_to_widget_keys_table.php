<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * host_tools_enabled — abilita la modalità Host Tool Provider (HTP) per la key.
 *
 * Quando true, le sessioni avviate con questa `widget_key` possono ricevere
 * definizioni di tool fornite inline dall'app ospite (es. gescat) ed esporle
 * all'LLM, instradandone l'esecuzione FE-proxied verso l'host. È il gate
 * lato credenziale che si affianca a `host_tools_enabled` della skill (la
 * capability vive su due livelli: key + skill).
 *
 * Default false: tutte le key esistenti restano sul comportamento attuale
 * (solo FE DOM tools + search_knowledge_base), nessun backfill richiesto.
 *
 * R31: la tabella è già tenant-aware (vedi create_widget_keys_table);
 * aggiungiamo solo una colonna booleana di capability, nessun impatto su
 * tenant_id / uniqueness. Boolean semplice → nessun tipo vector da swappare
 * nel mirror SQLite dei test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->boolean('host_tools_enabled')->default(false)->after('skill');
        });
    }

    public function down(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn('host_tools_enabled');
        });
    }
};
