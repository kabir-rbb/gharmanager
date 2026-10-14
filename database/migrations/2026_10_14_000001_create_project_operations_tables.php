<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20);
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });
        Schema::create('workers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('trade', 80);
            $t->unsignedBigInteger('daily_rate_paisa');
            $t->timestamps();
        });
        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $t->date('recorded_on');
            $t->unsignedInteger('day_milli');
            $t->unsignedBigInteger('rate_paisa');
            $t->unsignedBigInteger('total_paisa');
            $t->string('notes', 500)->default('');
            $t->timestamps();
            $t->unique(['worker_id', 'recorded_on']);
        });
        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->string('reference', 80);
            $t->string('payee', 120);
            $t->string('category', 20);
            $t->string('description', 500);
            $t->unsignedBigInteger('amount_paisa');
            $t->date('issued_on');
            $t->timestamps();
            $t->unique(['project_id', 'client_uuid']);
            $t->unique(['project_id', 'reference']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->unsignedBigInteger('amount_paisa');
            $t->string('method', 20);
            $t->string('reference', 120)->default('');
            $t->date('paid_on');
            $t->timestamps();
            $t->unique(['project_id', 'client_uuid']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_name', 255);
            $t->string('action', 80);
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->json('details');
            $t->timestamp('created_at');
            $t->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'payments', 'bills', 'attendance', 'workers', 'project_members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
