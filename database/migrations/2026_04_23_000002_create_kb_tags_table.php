<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_key', 120)->index();
            $table->string('slug', 120);
            $table->string('label', 200);
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->unique(['project_key', 'slug'], 'uq_kb_tags_project_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_tags');
    }
};
