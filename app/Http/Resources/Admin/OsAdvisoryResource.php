<?php

namespace App\Http\Resources\Admin;

use App\Models\Advisory;
use App\Services\Kev\KnownExploitation;
use App\Services\OsAdvisories\OsReleaseSupport;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A row of the operating system advisories table: the advisory's own title without its id, which is shown apart, and
 * each release it fixes with whether it is past its end of life (see OsReleaseSupport).
 *
 * @mixin Advisory
 */
class OsAdvisoryResource extends JsonResource
{
    /**
     * The CVE ids and packages a row lists before "+n".
     */
    private const int LISTED = 4;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $releaseSupport = app(OsReleaseSupport::class);
        $packages = collect($this->fixed_packages ?? [])->pluck('name')->unique()->values();
        $cves = collect($this->aliases ?? []);

        return [
            'id' => $this->id,
            'reference' => $this->external_id,
            'title' => Str::after($this->title, "{$this->external_id}: "),
            'severity' => ['value' => $this->severity->value, 'label' => $this->severity->getLabel()],
            'os' => ['value' => $this->operating_system->value, 'label' => $this->operating_system->getLabel()],
            'releases' => collect($this->os_releases ?? [])->map(function (string $release) use ($releaseSupport): array {
                $known = $releaseSupport->find($this->operating_system, $release);

                return ['name' => $release, 'is_eol' => $known !== null && $known['is_eol'], 'eol_from' => $known === null || $known['eol_from'] === null ? null : CarbonImmutable::parse($known['eol_from'])->translatedFormat(__('d/m/Y'))];
            })->all(),
            'packages' => $packages->take(self::LISTED)->all(),
            'more_packages' => max(0, $packages->count() - self::LISTED),
            'cves' => $cves->take(self::LISTED)->all(),
            'more_cves' => max(0, $cves->count() - self::LISTED),
            'known_exploited' => $this->isKnownExploited() ? KnownExploitation::cves($this->known_exploited) : null,
            'published' => LocalTime::date($this->published_at),
            'url' => $this->url,
            'is_sent' => $this->news_notification_id !== null,
        ];
    }
}
