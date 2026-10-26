<?php

namespace App\Enums;

/**
 * Why a team got a card about an incident.
 */
enum IncidentAlertReason: string
{
    case New = 'new';
    case SeverityRise = 'severity_rise';
    case PatchConfirmed = 'patch_confirmed';
}
