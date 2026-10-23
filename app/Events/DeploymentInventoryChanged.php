<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An admin changed a deployment's runtime versions or lockfiles, so the deployment must be re-evaluated.
 *
 * M2 adds the queued listener that runs the runtime evaluator.
 */
class DeploymentInventoryChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Deployment $deployment) {}
}
