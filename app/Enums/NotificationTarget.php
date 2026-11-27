<?php

namespace App\Enums;

/**
 * Where a project's Google Chat messages go: the space of its team, or a webhook of the project's own. A project on
 * its own webhook is left out of its team's messages entirely, and nothing falls back to the team when it fails.
 */
enum NotificationTarget: string
{
    case Team = 'team';
    case Project = 'project';

    public function getLabel(): string
    {
        return match ($this) {
            self::Team => __('Theo team'),
            self::Project => __('Webhook riêng của project'),
        };
    }
}
