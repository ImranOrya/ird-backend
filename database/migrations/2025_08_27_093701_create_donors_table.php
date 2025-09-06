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
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->string('abbr', 16);
            $table->unsignedBigInteger('role_id')->nullable();
            $table->foreign('role_id')->references('id')->on('roles')
                ->onUpdate('cascade')
                ->onDelete('set null');
            $table->unsignedBigInteger('address_id');
            $table->foreign('address_id')->references('id')->on('addresses')
                ->onUpdate('cascade')
                ->onDelete('no action');
            $table->unsignedBigInteger('email_id')->nullable();
            $table->foreign('email_id')->references('id')->on('emails')
                ->onUpdate('cascade')
                ->onDelete('set null');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->foreign('contact_id')->references('id')->on('contacts')
                ->onUpdate('cascade')
                ->onDelete('set null');
            $table->string('username');
            $table->string('password');
            $table->string('profile')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->rememberToken()->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donors');
    }
};
