<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingTemplate;
use App\Support\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST-API-CRUD für Abrechnungsvorlagen, gespiegelt von BillingTemplateController
 * (ohne Vorschau/Prüfung/Duplizieren). Das Kopfbild kann beim Anlegen mitgeschickt
 * oder per eigenem POST-Endpunkt ersetzt werden, da PHP multipart/form-data bei PUT
 * nicht auswertet.
 */
class BillingTemplateController extends Controller
{
    private const IMAGE_RULES = ['image', 'max:2048'];

    public function __construct()
    {
        $this->authorizeResource(BillingTemplate::class, 'billingTemplate', ['except' => ['storeHeaderImage']]);
    }

    public function index(Request $request): JsonResponse
    {
        $groupIds = $request->user()->groups->pluck('id');

        return response()->json(
            BillingTemplate::whereNull('user_group_id')->orWhereIn('user_group_id', $groupIds)->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->rules($request),
            'header_image' => ['nullable', ...self::IMAGE_RULES],
        ]);

        $template = BillingTemplate::create([
            ...collect($validated)->except('header_image')->all(),
            // Wie im Web: "Standard" ist der systemweiten Vorlage vorbehalten.
            'is_default' => false,
            'header_image_path' => $request->hasFile('header_image')
                ? FileStorage::store($request->file('header_image'), FileStorage::BILLING_TEMPLATE_IMAGES)
                : null,
        ]);

        return response()->json($template, 201);
    }

    public function show(BillingTemplate $billingTemplate): JsonResponse
    {
        return response()->json($billingTemplate);
    }

    public function update(Request $request, BillingTemplate $billingTemplate): JsonResponse
    {
        $validated = $request->validate([
            ...$this->rules($request),
            'remove_header_image' => ['boolean'],
        ]);

        if ($request->boolean('remove_header_image')) {
            $this->deleteHeaderImage($billingTemplate);
            $validated['header_image_path'] = null;
        }

        $billingTemplate->update([...collect($validated)->except('remove_header_image')->all(), 'is_default' => false]);

        return response()->json($billingTemplate);
    }

    public function destroy(BillingTemplate $billingTemplate): JsonResponse
    {
        $this->deleteHeaderImage($billingTemplate);

        $billingTemplate->delete();

        return response()->json(status: 204);
    }

    public function storeHeaderImage(Request $request, BillingTemplate $billingTemplate): JsonResponse
    {
        $this->authorize('update', $billingTemplate);

        $request->validate(['header_image' => ['required', ...self::IMAGE_RULES]]);

        $this->deleteHeaderImage($billingTemplate);
        $billingTemplate->update([
            'header_image_path' => FileStorage::store($request->file('header_image'), FileStorage::BILLING_TEMPLATE_IMAGES),
        ]);

        return response()->json($billingTemplate);
    }

    private function deleteHeaderImage(BillingTemplate $billingTemplate): void
    {
        FileStorage::delete($billingTemplate->header_image_path);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request): array
    {
        return [
            'user_group_id' => ['required', 'integer', Rule::in($request->user()->groups->pluck('id'))],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'css_content' => ['nullable', 'string'],
            'header' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
        ];
    }
}
