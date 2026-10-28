<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Districts (ADR 0031). Runs on the live RDS database, so it only adds:
 *
 * - districts: one row, the Golden Triangle BID, with DC's published
 *   boundary copied from data/sources/golden_triangle_bid.geojson (and its
 *   SHA-256, source and dates). No runtime fetch.
 * - merchants: where each one is (latitude, longitude), its district, when
 *   it was approved for it and when it went live. The 8 fictional demo
 *   merchants are placed at a point on their block (inside the boundary,
 *   DistrictsMigrationSafetyTest); any other merchant stays unplaced.
 *   Two demo addresses named blocks just outside the boundary and are moved
 *   to blocks inside it.
 * - employers.district_id: the district the employer's credit program runs
 *   in. Every existing employer is in the one BID, so it is the Golden
 *   Triangle.
 *
 * No money row, ledger entry or balance changes.
 */
return new class extends Migration
{
    /** Name => [latitude, longitude], a MAR address point on the merchant's block. Same as DatabaseSeeder::DEMO_COORDINATES. */
    private const PLACES = [
        'Farragut Grind' => [38.902184, -77.037528],
        'Seventeenth Street Kitchen' => [38.904107, -77.038752],
        'Connecticut Noodle Bar' => [38.904105, -77.039919],
        'Press Room Deli' => [38.901178, -77.043744],
        'K Street Cuts' => [38.902224, -77.042408],
        'Lafayette Cycle Studio' => [38.900494, -77.040595],
        'Triangle Stems' => [38.906107, -77.043869],
        'Page & Quill Books' => [38.903408, -77.045785],
    ];

    /** Old fictional address => new one, for the two that named blocks outside the boundary. */
    private const MOVED = [
        'Connecticut Noodle Bar' => ['1700 block of Connecticut Ave NW', '1100 block of Connecticut Ave NW'],
        'Lafayette Cycle Studio' => ['1500 block of H St NW', '1700 block of H St NW'],
    ];

    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->longText('boundary_geojson');
            $table->char('boundary_sha256', 64);
            $table->text('description');
            $table->string('source_name');
            $table->string('source_url', 500);
            $table->date('source_edited_on');
            $table->date('retrieved_on');
            $table->timestamps();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('district_approved_at')->nullable();
            $table->foreignId('district_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('live_at')->nullable();
        });

        Schema::table('employers', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
        });

        $config = config('districts.golden_triangle');
        $geojson = (string) file_get_contents($config['boundary_path']);
        $now = now();

        $districtId = DB::table('districts')->insertGetId([
            'name' => $config['name'],
            'slug' => $config['slug'],
            'boundary_geojson' => $geojson,
            'boundary_sha256' => hash('sha256', $geojson),
            'description' => $config['description'],
            'source_name' => $config['source_name'],
            'source_url' => $config['source_url'],
            'source_edited_on' => $config['source_edited_on'],
            'retrieved_on' => $config['retrieved_on'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (self::MOVED as $name => [$old, $new]) {
            DB::table('merchants')->where('name', $name)->where('address', $old)->update(['address' => $new]);
        }

        foreach (self::PLACES as $name => [$latitude, $longitude]) {
            DB::table('merchants')->where('name', $name)->whereNull('district_id')->update([
                'district_id' => $districtId, 'latitude' => $latitude, 'longitude' => $longitude,
                'district_approved_at' => $now, 'live_at' => $now,
            ]);
        }

        DB::table('employers')->whereNull('district_id')->update(['district_id' => $districtId]);
    }

    public function down(): void
    {
        foreach (self::MOVED as $name => [$old, $new]) {
            DB::table('merchants')->where('name', $name)->where('address', $new)->update(['address' => $old]);
        }

        Schema::table('employers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_approved_by_user_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropColumn(['latitude', 'longitude', 'district_approved_at', 'live_at']);
        });

        Schema::dropIfExists('districts');
    }
};
