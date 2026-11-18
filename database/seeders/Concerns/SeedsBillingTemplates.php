<?php

namespace Database\Seeders\Concerns;

use App\Models\BillingTemplate;
use App\Models\Group;
use App\Models\ManagementGroup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds a Sharegroup's Betriebskostenabrechnung-Templates, including the "From-File"
 * shorthand that loads a template's fields from a separate YAML file instead of
 * bloating seeddata.yaml itself.
 */
trait SeedsBillingTemplates
{
    /**
     * Seeds a Sharegroup's "Templates"/"Template-*" entries: an Abrechnungsvorlage
     * (BillingTemplate) scoped to this Group (matched by name for idempotent
     * reseeding). "Standard: true" makes it the default_billing_template_id of every
     * ManagementGroup this Sharegroup owns (its own "Groups" list, already applied by
     * the time this runs) – not the model's global `is_default` flag, which stays
     * reserved for the one built-in template without a group.
     *
     * @param  array<string, mixed>  $templatesData
     */
    private function seedGroupTemplates(array $templatesData, Group $sharegroup): void
    {
        foreach ($this->templateEntries($templatesData) as $templateData) {
            $templateData = $this->resolveTemplateFromFile($templateData);

            $name = trim((string) ($templateData['Name'] ?? ''));

            if ($name === '') {
                throw new \RuntimeException('Ein Template-Eintrag hat keinen (auch nicht per "From-File" geerbten) "Name".');
            }

            $template = BillingTemplate::firstOrCreate(
                ['user_group_id' => $sharegroup->id, 'name' => $name],
                ['content' => '']
            );

            $template->update([
                'header' => $templateData['Header'] ?? $template->header,
                'content' => $templateData['Content'] ?? $template->content,
                // Konzept.md "### Template": optionale, vom Content getrennte
                // Formatierung – beim Rendern wieder zusammengeführt (siehe
                // BillingTemplateRenderer).
                'css_content' => $templateData['CSS-Content'] ?? $template->css_content,
                'footer' => $templateData['Footer'] ?? $template->footer,
            ]);

            $this->seedTemplateImage($template, $templateData['Image'] ?? null);

            if ((bool) ($templateData['Standard'] ?? false)) {
                ManagementGroup::where('user_group_id', $sharegroup->id)
                    ->update(['default_billing_template_id' => $template->id]);
            }
        }
    }

    /**
     * A "Templates" block may either list several "Template-*"/"Template" entries, or –
     * as a shorthand for a single template – carry the template's fields (typically just
     * a "From-File") directly, without a "Template-*" wrapper. Returns the list of
     * individual template-data arrays either way.
     *
     * @param  array<string, mixed>  $templatesData
     * @return array<int, array<string, mixed>>
     */
    private function templateEntries(array $templatesData): array
    {
        $entries = $this->entriesMatching($templatesData, 'Template');

        // Shorthand: the "Templates" block itself already carries a single template's
        // fields (e.g. "from-File" directly) instead of nested "Template-*" entries.
        if ($entries === [] && ($this->fromFileValue($templatesData) !== '' || isset($templatesData['Name']))) {
            $entries = [$templatesData];
        }

        return $entries;
    }

    /**
     * Reads a template entry's "From-File" reference, accepting the case/spelling
     * variants that occur in real testdata (e.g. "From-File" and "from-File").
     *
     * @param  array<string, mixed>  $data
     */
    private function fromFileValue(array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && strtolower($key) === 'from-file') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Resolves a "From-File" reference on a template entry: loads the referenced YAML
     * file (absolute path, or relative to the project root – same convention as the
     * "Image" local path) and uses it as the template's Name/Standard/Image/Header/
     * Content/Footer, since templates can get very large and this lets them live in
     * their own file instead of bloating seeddata.yaml itself. The file may wrap its
     * fields in a single top-level "Template"/"Template-*" key, which is unwrapped
     * automatically. "Name" and "Standard" given directly on the seeddata.yaml entry
     * still override the file's own values; everything else comes from the file.
     * Silently returns the entry unchanged if no "From-File" is given at all (optional
     * field); throws if a "From-File" IS given but doesn't resolve to a real file (a
     * typo'd/misconfigured reference, see resolveLocalAssetPath()) or that file's
     * content isn't valid YAML (empty/malformed file – referencing it was clearly
     * intentional, so producing nothing useful is an error, not omitted data).
     *
     * @param  array<string, mixed>  $templateData
     * @return array<string, mixed>
     */
    private function resolveTemplateFromFile(array $templateData): array
    {
        $fromFile = $this->fromFileValue($templateData);

        if ($fromFile === '') {
            return $templateData;
        }

        $resolvedPath = $this->resolveLocalAssetPath($fromFile);

        $contents = file_get_contents($resolvedPath);
        $fileData = $contents !== false ? Yaml::parse($contents) : null;

        if (! is_array($fileData)) {
            throw new \RuntimeException("Template-Datei \"{$fromFile}\" ist leer oder kein gültiges YAML.");
        }

        $fileData = $this->unwrapTemplateFile($fileData);

        return array_merge($fileData, array_filter([
            'Name' => $templateData['Name'] ?? null,
            'Standard' => $templateData['Standard'] ?? null,
        ], fn ($value) => $value !== null));
    }

    /**
     * A template file may hold its fields directly (Name/Content/…) or wrap them in a
     * single top-level "Template"/"Template-*" key. Returns the inner field array in
     * either case.
     *
     * @param  array<string, mixed>  $fileData
     * @return array<string, mixed>
     */
    private function unwrapTemplateFile(array $fileData): array
    {
        if (isset($fileData['Name']) || isset($fileData['Content'])) {
            return $fileData;
        }

        $wrapped = $this->entriesMatching($fileData, 'Template');

        return $wrapped[0] ?? $fileData;
    }

    /**
     * Uploads the image at the given local filesystem path (absolute, relative to the
     * project root, or relative to ".claude/") as the template's header image. Does
     * nothing if no path is given at all (optional field); throws if one is given but
     * doesn't resolve to a real file (see resolveLocalAssetPath()).
     */
    private function seedTemplateImage(BillingTemplate $template, mixed $localPath): void
    {
        $localPath = trim((string) $localPath);

        if ($localPath === '') {
            return;
        }

        $resolvedPath = $this->resolveLocalAssetPath($localPath);

        if ($template->header_image_path) {
            Storage::disk('public')->delete($template->header_image_path);
        }

        $extension = pathinfo($resolvedPath, PATHINFO_EXTENSION);
        $storedPath = 'billing-template-images/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');
        Storage::disk('public')->put($storedPath, file_get_contents($resolvedPath));

        $template->update(['header_image_path' => $storedPath]);
    }
}
