<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('article_group_id');
            $table->enum('locale', ['es', 'en']);
            $table->string('slug', 100);
            $table->string('title', 160);
            $table->string('excerpt', 500);
            $table->string('author_name', 100);
            $table->json('content_json');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'slug']);
            $table->unique(['article_group_id', 'locale']);
            $table->index(['status', 'locale', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
