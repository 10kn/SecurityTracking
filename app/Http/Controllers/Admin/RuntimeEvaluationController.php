<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Technology;
use App\Services\RuntimeEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How a version would be evaluated, shown under the version field before the service form is saved.
 */
class RuntimeEvaluationController extends Controller
{
    public function __invoke(Request $request, RuntimeEvaluator $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'technology_id' => ['required', 'integer'],
            'version' => ['required', 'string', 'max:255'],
        ]);

        $technology = Technology::findOrFail($validated['technology_id']);
        $evaluation = $evaluator->evaluate($technology, $validated['version']);
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
