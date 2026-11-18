<?php

namespace App\Http\Controllers;

use App\Models\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD for UI Themes, protecting built-in themes from editing or deletion.
 */
class ThemeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $themes = Theme::orderBy('name')->get();

        return view('themes.index', compact('themes'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $knownSelectors = Theme::knownSelectors();

        return view('themes.create', compact('knownSelectors'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:themes,slug'],
            'css' => ['nullable', 'string'],
        ]);

        Theme::create($validated);

        return redirect()->route('themes.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Theme $theme): View
    {
        abort_if($theme->is_builtin, 403);

        $knownSelectors = Theme::knownSelectors();

        return view('themes.edit', compact('theme', 'knownSelectors'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Theme $theme): RedirectResponse
    {
        abort_if($theme->is_builtin, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'css' => ['nullable', 'string'],
        ]);

        $theme->update($validated);

        return redirect()->route('themes.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Theme $theme): RedirectResponse
    {
        abort_if($theme->is_builtin, 403);

        $theme->users()->update(['theme_id' => null]);
        $theme->delete();

        return redirect()->route('themes.index');
    }
}
