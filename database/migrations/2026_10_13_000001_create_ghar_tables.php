<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('projects',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('name',120);$t->string('location',160);$t->string('status')->default('active');$t->unsignedBigInteger('budget_paisa')->default(0);$t->timestamps();});
  Schema::create('materials',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->string('name',100);$t->string('supplier',120);$t->string('unit',20);$t->unsignedBigInteger('quantity_milli');$t->unsignedBigInteger('rate_paisa');$t->unsignedBigInteger('total_paisa');$t->date('recorded_on');$t->timestamps();});
  Schema::create('daily_logs',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->uuid('client_uuid');$t->date('recorded_on');$t->text('notes');$t->timestamps();$t->unique(['project_id','client_uuid']);});
  Schema::create('consultations',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->string('topic',160);$t->string('mode');$t->dateTime('requested_at');$t->string('status')->default('requested');$t->timestamps();});
 }
 public function down():void {foreach(['consultations','daily_logs','materials','projects'] as $table)Schema::dropIfExists($table);}
};
