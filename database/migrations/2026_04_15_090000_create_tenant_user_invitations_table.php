<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('email');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('invited_by_user_id');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->string('status', 20);
            $table->string('pending_guard')->nullable();
            $table->string('token_hash')->unique();
            $table->json('role_codes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invited_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('accepted_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'pending_guard']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_invitations');
    }
};
