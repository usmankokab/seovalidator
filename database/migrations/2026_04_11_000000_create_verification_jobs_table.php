<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('verification_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique()->index();
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->integer('progress')->default(0); // 0-100
            $table->string('workbook_name')->nullable();
            $table->string('file_path')->nullable();
            $table->text('report_mode')->nullable(); // complete, single_week, date_range, complete_worksheet
            $table->json('filter_values')->nullable(); // week, start_date, end_date
            
            // Results (populated when status = completed)
            $table->string('excel_file')->nullable();
            $table->string('word_file')->nullable();
            $table->string('pdf_file')->nullable();
            $table->json('summary')->nullable();
            $table->json('coverage')->nullable();
            $table->json('exceptions')->nullable();
            
            // Metadata
            $table->string('error_message')->nullable();
            $table->float('elapsed_time')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_jobs');
    }
};
