<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Short marketing strapline shown under the H1 on the mobile detail
            // page ("Premium African Hardwood – Export Quality"). Nullable: the
            // line is omitted entirely when the supplier has not written one.
            $table->string('tagline', 160)->nullable();

            // Optional product video. The gallery's "Video" tile is rendered
            // ONLY when this is set — it is never faked.
            $table->string('video_url', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'video_url']);
        });
    }
};
