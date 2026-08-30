<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only amendment log for finalised inspections (blueprint §27:
 * "immutable after finalisation except through a formal amended-report
 * workflow"). Created only via App\Models\Inspection::amend().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_amendments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inspection_id')->constrained('inspections')->cascadeOnDelete();
            $table->foreignId('amended_by')->constrained('users');
            $table->text('reason');
            $table->jsonb('changes');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_amendments');
    }
};
