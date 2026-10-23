<?php

namespace Database\Seeders;

use App\Enums\Exposure;
use App\Enums\Language;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data from the handover plan: 3 teams, 5 projects, 8 services, each with a production deployment and one runtime.
 */
class DemoSeeder extends Seeder
{
    /**
     * @var array<string, array<string, list<array{0: string, 1: ServiceKind, 2: Exposure, 3: Language, 4: string}>>>
     */
    private const TEAMS = [
        'Commerce' => [
            'Shop' => [
                ['shop-api', ServiceKind::Ecs, Exposure::Public, Language::Php, '8.3.12'],
                ['shop-web', ServiceKind::Ecs, Exposure::Public, Language::Nodejs, '22.11.0'],
            ],
            'Partner Portal' => [
                ['partner-portal', ServiceKind::Server, Exposure::Public, Language::Php, '8.4.2'],
            ],
        ],
        'Booking' => [
            'Booking' => [
                ['booking-api', ServiceKind::Server, Exposure::Public, Language::Ruby, '3.3.6'],
                ['booking-worker', ServiceKind::Server, Exposure::Internal, Language::Ruby, '3.2.6'],
                ['realtime-gateway', ServiceKind::Ecs, Exposure::Public, Language::Nodejs, '20.18.0'],
            ],
        ],
        'Internal Tools' => [
            'Legacy CMS' => [
                ['legacy-cms', ServiceKind::Server, Exposure::Internal, Language::Php, '8.1.31'],
            ],
            'HR' => [
                ['hr-internal', ServiceKind::Server, Exposure::Internal, Language::Php, '8.2.27'],
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TEAMS as $teamName => $projects) {
            $team = Team::factory()->create([
                'name' => $teamName,
                'slug' => Str::slug($teamName),
            ]);

            foreach ($projects as $projectName => $services) {
                $project = Project::factory()->for($team)->create([
                    'name' => $projectName,
                    'slug' => Str::slug($projectName),
                    'repository_url' => 'https://github.com/example/'.Str::slug($projectName),
                ]);

                foreach ($services as [$slug, $kind, $exposure, $language, $version]) {
                    $service = Service::factory()->for($project)->create([
                        'name' => $slug,
                        'slug' => $slug,
                        'kind' => $kind,
                        'exposure' => $exposure,
                    ]);

                    $deployment = Deployment::factory()->for($service)->create();

                    Runtime::factory()->for($deployment)->create([
                        'language' => $language,
                        'raw_version' => $version,
                        'version' => $version,
                    ]);
                }
            }
        }
    }
}
