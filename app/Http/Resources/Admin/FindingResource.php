<?php

namespace App\Http\Resources\Admin;

use App\Models\Finding;
use App\Services\Kev\KnownExploitation;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the findings table. Load `deployment.service.project`, `team` and `acknowledgedBy`, and the latest opening
 * or rise in severity as `version` (see AcknowledgeFindings), first. Times are shown as LocalTime shows them; the
 * deadline is a day in the digest timezone, where it is decided.
 *
 * @mixin Finding
 *
 * @property int|null $version
 */
class FindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = $this->deployment->service;

        return [
            'id' => $this->id,
            'version' => $this->version === null ? null : (int) $this->version,
            'severity' => ['value' => $this->severity->value, 'label' => $this->severity->getLabel()],
            'title' => $this->localizedTitle(),
            'rule' => $this->rule->getLabel(),
            'lockfiles' => $this->lockfilePaths(),
            'service' => ['id' => $service->id, 'name' => $service->name, 'project' => $service->project->name],
            'team' => $this->team->name,
            'first_seen' => LocalTime::date($this->first_seen_at),
            'deadline' => $this->date($this->sla_due_at),
            'is_overdue' => $this->sla_due_at?->isPast() ?? false,
            'known_exploited' => blank($this->context['known_exploited'] ?? null) ? null : KnownExploitation::cves($this->context['known_exploited']),
            'acknowledged' => $this->acknowledged_at === null ? null : [
                'by' => $this->acknowledgedBy?->name,
                'at' => LocalTime::format($this->acknowledged_at),
            ],
        ];
    }

    private function date(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone(config('psm.digest.timezone'))->translatedFormat(__('d/m/Y'));
    }
}
