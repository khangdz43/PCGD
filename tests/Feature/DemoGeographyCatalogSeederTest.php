<?php

namespace Tests\Feature;

use Database\Seeders\DemoGeographyCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DemoGeographyCatalogSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('provinces', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
        });
        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('province_code');
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('commune_code');
            $table->timestamps();
        });
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('level')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamps();
        });

        DB::table('provinces')->insert([
            ['code' => '01', 'name' => 'Thành phố Hà Nội'],
            ['code' => '89', 'name' => 'Tỉnh An Giang'],
        ]);
        DB::table('communes')->insert([
            ['code' => '00001', 'name' => 'Xã Tân Lập', 'province_code' => '01'],
            ['code' => '00002', 'name' => 'Phường An Bình', 'province_code' => '89'],
        ]);
    }

    public function test_demo_catalog_seeder_creates_realistic_stable_rows_without_overwriting_existing_rows(): void
    {
        DB::table('villages')->insert([
            'code' => 'MPL-VIL-00001-01',
            'name' => 'Tên thôn đã có',
            'commune_code' => '00001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $seeder = app(DemoGeographyCatalogSeeder::class);
        $seeder->run();
        $firstVillageCount = DB::table('villages')->count();
        $firstSchoolCount = DB::table('schools')->count();
        $seeder->run();

        $this->assertSame($firstVillageCount, DB::table('villages')->count());
        $this->assertSame($firstSchoolCount, DB::table('schools')->count());
        $this->assertSame('Tên thôn đã có', DB::table('villages')->where('code', 'MPL-VIL-00001-01')->value('name'));

        foreach (['00001', '00002'] as $communeCode) {
            $this->assertGreaterThanOrEqual(2, DB::table('schools')->where('commune_code', $communeCode)->count());
            $this->assertLessThanOrEqual(4, DB::table('schools')->where('commune_code', $communeCode)->count());
            $this->assertGreaterThanOrEqual(3, DB::table('villages')->where('commune_code', $communeCode)->count());
            $this->assertLessThanOrEqual(6, DB::table('villages')->where('commune_code', $communeCode)->count());
        }

        $this->assertSame(
            0,
            DB::table('schools as s')
                ->join('communes as c', 'c.code', '=', 's.commune_code')
                ->whereColumn('s.province_code', '!=', 'c.province_code')
                ->count(),
        );
        $this->assertSame(0, DB::table('schools')->where('code', 'not like', 'MPL-SCH-%')->count());
    }
}
