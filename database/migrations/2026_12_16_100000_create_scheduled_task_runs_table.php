<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin "Sync & jobs": one row per run of a scheduled task (routes/console.php), written by
     * App\Domain\Operations\Support\ScheduledTaskRecorder from the scheduler's own events. Kept 30 days (model:prune).
     */
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('task', 191);
            $table->string('expression', 64);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('succeeded')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['task', 'expression', 'started_at'], 'scheduled_task_runs_task_started_index');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
