<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;

/**
 * Seeds the built-in UI themes (Standard, Matrix, Matrix Blue, Norton Commander) with their CSS overrides.
 */
class ThemeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Theme::updateOrCreate(
            ['slug' => 'standard'],
            ['name' => 'Standard', 'css' => null, 'is_builtin' => true]
        );

        // Renamed from "NCurses"; update any pre-existing row in place so it keeps
        // its id (and thus stays attached to users who already selected it) instead
        // of leaving a stale duplicate behind under the old slug. Falls back to the
        // already-renamed "matrix" row (or creates a fresh one) so re-running the
        // seeder after the rename has already happened doesn't collide on the
        // unique slug constraint.
        $matrix = Theme::where('slug', 'ncurses')->first()
            ?? Theme::where('slug', 'matrix')->first()
            ?? new Theme(['slug' => 'matrix']);
        $matrix->fill(['slug' => 'matrix', 'name' => 'Matrix', 'css' => $this->matrixCss(), 'is_builtin' => true]);
        $matrix->save();

        Theme::updateOrCreate(
            ['slug' => 'matrix-blue'],
            ['name' => 'Matrix Blue', 'css' => $this->matrixBlueCss(), 'is_builtin' => true]
        );

        Theme::updateOrCreate(
            ['slug' => 'norton-commander'],
            ['name' => 'Norton Commander', 'css' => $this->nortonCommanderCss(), 'is_builtin' => true]
        );
    }

    private function matrixCss(): string
    {
        return <<<'CSS'
body {
    background-color: #000 !important;
    color: #33ff66 !important;
    font-family: "Courier New", Courier, monospace !important;
}
.bg-gray-100, .bg-gray-50, .bg-gray-900 { background-color: #000 !important; }
.bg-white, .bg-gray-800, .bg-gray-700 { background-color: #111 !important; }
.text-gray-900, .text-gray-800, .text-gray-700 { color: #33ff66 !important; }
.text-gray-600, .text-gray-500, .text-gray-400 { color: #1f8a3d !important; }
.text-white { color: #33ff66 !important; }
.border-gray-100, .border-gray-200, .border-gray-300, .border, .border-t, .border-b {
    border-color: #1f8a3d !important;
}
.shadow, .shadow-sm { box-shadow: none !important; }
a, .hover\:underline:hover { color: #33ff66 !important; }
button, .rounded-md, .rounded-lg {
    border-radius: 0 !important;
}
input, select, textarea {
    background-color: #000 !important;
    color: #33ff66 !important;
    border-color: #1f8a3d !important;
}
.text-red-600, .text-red-700 { color: #ff5555 !important; }
.bg-red-600, .bg-red-500, .bg-red-700 { background-color: #330000 !important; color: #ff5555 !important; }
CSS;
    }

    private function matrixBlueCss(): string
    {
        return <<<'CSS'
body {
    background-color: #000 !important;
    color: #00d2ff !important;
    text-shadow: 0 0 6px rgba(0, 210, 255, 0.85) !important;
    font-family: "Courier New", Courier, monospace !important;
}
.bg-gray-100, .bg-gray-50, .bg-gray-900 { background-color: #000 !important; }
.bg-white, .bg-gray-800, .bg-gray-700 { background-color: #111 !important; }
.text-gray-900, .text-gray-800, .text-gray-700 {
    color: #00d2ff !important;
    text-shadow: 0 0 6px rgba(0, 210, 255, 0.85) !important;
}
.text-gray-600, .text-gray-500, .text-gray-400 { color: #3ab6e8 !important; }
.text-white { color: #00d2ff !important; }
.border-gray-100, .border-gray-200, .border-gray-300, .border, .border-t, .border-b {
    border-color: #3ab6e8 !important;
}
.shadow, .shadow-sm { box-shadow: none !important; }
a, .hover\:underline:hover {
    color: #00d2ff !important;
    text-shadow: 0 0 6px rgba(0, 210, 255, 0.85) !important;
}
button, .rounded-md, .rounded-lg {
    border-radius: 0 !important;
}
input, select, textarea {
    background-color: #000 !important;
    color: #00d2ff !important;
    text-shadow: 0 0 6px rgba(0, 210, 255, 0.85) !important;
    border-color: #3ab6e8 !important;
}
.text-red-600, .text-red-700 { color: #ff5555 !important; }
.bg-red-600, .bg-red-500, .bg-red-700 { background-color: #330000 !important; color: #ff5555 !important; }
CSS;
    }

    private function nortonCommanderCss(): string
    {
        return <<<'CSS'
body {
    background-color: #0000aa !important;
    color: #ffffff !important;
    font-family: "Courier New", Courier, monospace !important;
}
.bg-gray-100, .bg-gray-50, .bg-gray-900 { background-color: #0000aa !important; }
.bg-white, .bg-gray-700 { background-color: #0000cc !important; }
.bg-gray-800 { background-color: #00aaaa !important; }
.text-gray-900, .text-gray-800, .text-gray-700 { color: #ffffff !important; }
.text-gray-600, .text-gray-500, .text-gray-400 { color: #55ffff !important; }
.text-white { color: #000000 !important; }
.border-gray-100, .border-gray-200, .border-gray-300, .border, .border-t, .border-b {
    border-color: #ffffff !important;
}
.shadow, .shadow-sm { box-shadow: none !important; }
a, .hover\:underline:hover { color: #ffff55 !important; }
button, .rounded-md, .rounded-lg {
    border-radius: 0 !important;
}
input, select, textarea {
    background-color: #ffffff !important;
    color: #000000 !important;
    border-color: #0000aa !important;
}
.text-red-600, .text-red-700 { color: #ffff55 !important; }
.bg-red-600, .bg-red-500, .bg-red-700 { background-color: #aa0000 !important; color: #ffffff !important; }
.bg-green-500 { background-color: #0000aa !important; }
CSS;
    }
}
