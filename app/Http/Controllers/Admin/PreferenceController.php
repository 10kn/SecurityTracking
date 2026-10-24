<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePreferencesRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The theme and the interface language of the signed-in user. The page reloads afterwards because both are applied
 * while the page is rendered.
 */
class PreferenceController extends Controller
{
    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back();
    }
}
