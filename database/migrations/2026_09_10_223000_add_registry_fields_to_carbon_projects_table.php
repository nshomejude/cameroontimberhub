<?php

use App\Models\CarbonProject;
use App\Support\CarbonProjectIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch F (§2.6) — carbon project registry core. Additive registry fields on
 * carbon_projects plus a locked-sequence table for the `CTH-CARB-00000`
 * public id, mirroring products / product_public_id_sequences (B1).
 *
 * REGISTRY ONLY — no credit ledger / trading / lifecycle (§2.7).
 *
 * public_id + verification_token are kept nullable at the schema level and
 * assigned on create via CarbonProject::booted(); existing rows are
 * backfilled inline here so every row ends up with values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_project_public_id_sequences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('last_value')->default(0);
        });

        Schema::table('carbon_projects', function (Blueprint $table): void {
            $table->string('public_id', 40)->nullable()->unique()->after('id');
            $table->string('verification_token', 64)->nullable()->unique()->after('public_id');
            $table->jsonb('boundary')->nullable()->after('description');
            $table->string('registry_status', 30)->default('draft')->after('boundary');
        });

        CarbonProject::query()->orderBy('id')->get()->each(function (CarbonProject $project): void {
            $project->newQuery()->whereKey($project->getKey())->update([
                'public_id' => CarbonProjectIdentifier::next(),
                'verification_token' => bin2hex(random_bytes(32)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('carbon_projects', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['verification_token']);
            $table->dropColumn(['public_id', 'verification_token', 'boundary', 'registry_status']);
        });

        Schema::dropIfExists('carbon_project_public_id_sequences');
    }
};
