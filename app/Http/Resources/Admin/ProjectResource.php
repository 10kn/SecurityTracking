<?php

namespace App\Http\Resources\Admin;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of a projects table. Count services first; the team is included when loaded.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
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
            'services_count' => $this->services_count,
            'is_paused' => $this->isPaused(),
            'team' => $this->whenLoaded('team', fn (): array => ['id' => $this->team->id, 'name' => $this->team->name]),
        ];
    }
}
