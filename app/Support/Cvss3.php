<?php

namespace App\Support;

use App\Enums\Severity;

/**
 * The CVSS v3.0 / v3.1 base score of a vector such as `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H` (9.8), computed
 * with the formula of the CVSS v3.1 specification, section 7.
 */
final class Cvss3
{
    /**
     * @var array<string, array<string, float>>
     */
    private const array WEIGHTS = [
        'AV' => ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2],
        'AC' => ['L' => 0.77, 'H' => 0.44],
        'UI' => ['N' => 0.85, 'R' => 0.62],
        'C' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
        'I' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
        'A' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
    ];

    /**
     * Privileges required weigh more when the scope changes.
     *
     * @var array<string, array<string, float>>
     */
    private const array PRIVILEGES = [
        'U' => ['N' => 0.85, 'L' => 0.62, 'H' => 0.27],
        'C' => ['N' => 0.85, 'L' => 0.68, 'H' => 0.5],
    ];

    /**
     * Null when the text is not a complete CVSS v3 vector.
     */
    public static function baseScore(string $vector): ?float
    {
        $parts = explode('/', trim($vector));

        if (! in_array(array_shift($parts), ['CVSS:3.0', 'CVSS:3.1'], true)) {
            return null;
        }

        $metrics = [];

        foreach ($parts as $part) {
            [$metric, $value] = array_pad(explode(':', $part, 2), 2, '');
            $metrics[$metric] = $value;
        }

        $scope = $metrics['S'] ?? null;

        if (! isset(self::PRIVILEGES[$scope][$metrics['PR'] ?? ''])) {
            return null;
        }

        $weights = [];

        foreach (self::WEIGHTS as $metric => $values) {
            if (! isset($values[$metrics[$metric] ?? ''])) {
                return null;
            }

            $weights[$metric] = $values[$metrics[$metric]];
        }

        $impactSubScore = 1 - (1 - $weights['C']) * (1 - $weights['I']) * (1 - $weights['A']);
        $impact = $scope === 'U'
            ? 6.42 * $impactSubScore
            : 7.52 * ($impactSubScore - 0.029) - 3.25 * (($impactSubScore - 0.02) ** 15);
        $exploitability = 8.22 * $weights['AV'] * $weights['AC'] * self::PRIVILEGES[$scope][$metrics['PR']] * $weights['UI'];

        if ($impact <= 0) {
            return 0.0;
        }

        return self::roundUp(min(($scope === 'U' ? 1 : 1.08) * ($impact + $exploitability), 10));
    }

    /**
     * The qualitative rating of the specification: 9.0 and up is Critical, 7.0 High, 4.0 Medium, anything lower Low.
     */
    public static function severity(float $score): Severity
    {
        return match (true) {
            $score >= 9.0 => Severity::Critical,
            $score >= 7.0 => Severity::High,
            $score >= 4.0 => Severity::Medium,
            default => Severity::Low,
        };
    }

    /**
     * The smallest number with one decimal that is equal to or higher than the input, avoiding floating point errors
     * (specification, appendix A).
     */
    private static function roundUp(float $value): float
    {
        $integer = (int) round($value * 100000);

        return $integer % 10000 === 0 ? $integer / 100000 : (floor($integer / 10000) + 1) / 10;
    }
}
