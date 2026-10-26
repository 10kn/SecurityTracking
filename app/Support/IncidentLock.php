<?php

namespace App\Support;

use App\Enums\Technology;
use Illuminate\Support\Facades\DB;

/**
 * The lock of a technology's incidents, held until the transaction ends: it serializes the assignment, merges and
 * splits of its incidents, and the "Đã có bản vá" cards with the evaluations that store the findings those cards stand
 * for, so neither misses a finding or a card the other is writing.
 *
 * Every transaction takes it before any row lock, so two of them never wait on each other in opposite order; one that
 * needs several technologies takes them in the order of take(). Taking it again in the same transaction is free.
 */
final class IncidentLock
{
    /**
     * @param  iterable<Technology>  $technologies
     */
    public static function take(iterable $technologies): void
    {
        collect($technologies)
            ->unique(fn (Technology $technology): string => $technology->value)
            ->sortBy(fn (Technology $technology): string => $technology->value)
            ->each(fn (Technology $technology) => DB::select('select pg_advisory_xact_lock(?)', [crc32("incidents:{$technology->value}")]));
    }
}
