<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
        });

        Schema::table('student_files', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_files', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropForeign(['archived_by']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['description', 'issue_date', 'expiry_date', 'uploaded_by', 'archived_at', 'archived_by', 'archive_reason']);
        });
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn(['gender', 'birth_date', 'address']));
    }
};
