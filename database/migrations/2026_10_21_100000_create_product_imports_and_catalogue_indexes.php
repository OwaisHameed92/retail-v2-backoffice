<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.2: the product CSV import (upload → mapping → preview → queued apply, one row per import) and the list
 * index for the products screen (company, name: sorted pages stay fast at 100k products per business).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('file_name', 255);
            $table->string('path', 255);
            $table->string('status', 20);                 // uploaded, queued, running, completed, failed
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();           // [{row, messages}], capped
            $table->json('preview')->nullable();          // new / update counts and the first rows as read
            $table->unsignedBigInteger('cursor')->default(0);   // byte offset of the next row to apply
            $table->dateTime('previewed_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'name'], 'products_company_id_name_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->index(['company_id', 'department_id'], 'categories_company_id_department_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_company_id_department_id_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_id_name_index');
        });

        Schema::dropIfExists('product_imports');
    }
};
