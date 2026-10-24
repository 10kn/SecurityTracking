<?php

namespace App\Http\Resources\Admin;

use App\Models\Technology;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the technologies table. Count branches and runtimes first.
 *
 * @mixin Technology
 */
class TechnologyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $heartbeat = $this->catalogHeartbeat();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'endoflife_product' => $this->endoflife_product,
            'release_source' => $this->release_source->getLabel(),
            'branches_count' => $this->branches_count,
            'runtimes_count' => $this->runtimes_count,
            'catalog' => $this->catalogStatus()->dot(),
            'last_synced' => $heartbeat?->last_success_at === null
                ? null
                : __('lần cuối :time', ['time' => $heartbeat->last_success_at->diffForHumans()]),
            'last_error' => $heartbeat?->last_error,
        ];
    }
}
