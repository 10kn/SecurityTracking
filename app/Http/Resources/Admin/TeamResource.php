<?php

namespace App\Http\Resources\Admin;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the teams table. Load latestChatNotification and count projects and open findings first.
 *
 * @mixin Team
 *
 * @property int $open_findings_count
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'projects_count' => $this->projects_count,
            'open_findings_count' => $this->open_findings_count,
            'google_chat' => $this->googleChatStatus()->dot(),
        ];
    }
}
