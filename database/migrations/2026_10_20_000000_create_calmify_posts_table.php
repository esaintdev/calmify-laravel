<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('calmify.table', 'calmify_posts'), function (Blueprint $table) {
            $table->id();
            // Same value for every retry of one article version: one post, never two.
            $table->string('idempotency_key', 200)->unique();
            $table->string('slug', 120)->unique();
            $table->string('title', 300);
            $table->longText('content_html'); // sanitized with HTML Purifier
            $table->string('category', 100)->default('');
            $table->string('meta_description', 320)->default('');
            $table->string('meta_keywords', 1000)->default('');
            $table->string('image_path')->nullable();
            $table->string('image_alt', 300)->default('');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('calmify.table', 'calmify_posts'));
    }
};
