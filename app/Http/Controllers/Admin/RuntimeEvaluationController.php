<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Services\RuntimeEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How a version would be evaluated, shown under the version field before the service form is saved.
 */
class RuntimeEvaluationController extends Controller
{
    public function __invoke(Request $request, RuntimeEvaluator $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'technology' => ['required', Rule::enum(Technology::class)],
            'version' => ['required', 'string', 'max:255'],
        ]);

        $evaluation = $evaluator->evaluate(Technology::from($validated['technology']), $validated['version']);
        $details = [$evaluation->reasonText()];

        if ($evaluation->version !== null && $evaluation->branch !== null && $evaluation->version !== trim($validated['version'])) {
            $details[] = __('hiểu là :version, nhánh :branch', ['version' => $evaluation->version, 'branch' => $evaluation->branch]);
        }

        return response()->json([
            'status' => $evaluation->status->dot(),
            'details' => implode(' · ', $details),
        ]);
    }
}
