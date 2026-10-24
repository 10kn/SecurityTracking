<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Technology;
use App\Services\Catalog\EndoflifeDateClient;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The display name endoflife.date gives a product, e.g. "Node.js" for "nodejs", to prefill the technology form.
 */
class EndoflifeProductController extends Controller
{
    public function show(string $product, EndoflifeDateClient $client): JsonResponse
    {
        if (preg_match(Technology::ENDOFLIFE_PRODUCT_PATTERN, $product) !== 1) {
            return response()->json(['label' => null]);
        }

        try {
            return response()->json(['label' => $client->productLabel($product)]);
        } catch (Throwable) {
            // The form's validation reports an unreachable endoflife.date when it is saved.
            return response()->json(['label' => null]);
        }
    }
}
