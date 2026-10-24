<?php

namespace Database\Seeders;

use App\Enums\ReleaseSource;
use App\Models\Technology;
use Illuminate\Database\Seeder;

/**
 * The three runtimes of the MVP, with their official release feeds. Safe to run in production and more than once:
 * php artisan db:seed --class=TechnologySeeder
 */
class TechnologySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'PHP', 'slug' => 'php', 'endoflife_product' => 'php', 'release_source' => ReleaseSource::PhpNet],
            ['name' => 'Node.js', 'slug' => 'nodejs', 'endoflife_product' => 'nodejs', 'release_source' => ReleaseSource::Nodejs],
            ['name' => 'Ruby', 'slug' => 'ruby', 'endoflife_product' => 'ruby', 'release_source' => ReleaseSource::RubyLang],
        ] as $technology) {
            Technology::firstOrCreate(['slug' => $technology['slug']], $technology);
        }
    }
}
