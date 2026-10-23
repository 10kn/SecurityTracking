<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * Status as a small dot plus text, without a background (prototype v2). The "unknown" tone draws a dashed
 * outline so missing data never looks like a passing state.
 */
class StatusDot
{
    /**
     * @param  'danger'|'warning'|'success'|'neutral'|'unknown'  $tone
     */
    public static function make(string $label, string $tone): HtmlString
    {
        return new HtmlString('<span class="psm-state" data-tone="'.e($tone).'">'.e($label).'</span>');
    }
}
