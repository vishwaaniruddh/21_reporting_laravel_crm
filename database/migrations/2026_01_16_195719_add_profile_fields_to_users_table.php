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
        Schema::connection('pgsql')->table('users', function (Blueprint $table) {
            $table->string('contact', 20)->nullable()->after('email');
            $table->string('profile_image')->nullable()->after('contact');
            $table->text('bio')->nullable()->after('profile_image');
            $table->date('dob')->nullable()->after('bio');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('dob');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->table('users', function (Blueprint $table) {
            $table->dropColumn(['contact', 'profile_image', 'bio', 'dob', 'gender']);
        });
    }
};
