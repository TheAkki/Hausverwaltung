<?php

namespace Tests\Unit;

use App\Models\BillingTemplate;
use App\Models\Group;
use App\Models\ManagementGroup;
use Database\Seeders\TestDataSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Covers SeedsBillingTemplates: Sharegroup templates, header images and the
 * "From-File" external-template mechanism.
 */
class TestDataSeederTemplatesTest extends TestDataSeederTestCase
{
    public function test_a_sharegroups_template_is_scoped_to_it_and_marked_standard_becomes_its_management_groups_default(): void
    {
        Storage::fake('public');

        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Template Owner"
            Passwort: "geheim123"

            Group-1:
                Name: "Template Sharegroup"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        Name: "Eigenes Anschreiben"
                        Standard: true
                        Header: "Kopf"
                        Content: "Inhalt"
                        CSS-Content: "body { color: red; }"
                        Footer: "Fuß"

            Verwaltungsgruppe-1:
                Beschreibung: "Template VG1"
        YAML);

        $this->seed(TestDataSeeder::class);

        $sharegroup = Group::where('name', 'Template Sharegroup')->first();
        $this->assertNotNull($sharegroup);

        $template = BillingTemplate::where('name', 'Eigenes Anschreiben')->first();
        $this->assertNotNull($template);
        $this->assertSame($sharegroup->id, $template->user_group_id);
        $this->assertSame('Kopf', $template->header);
        $this->assertSame('Inhalt', $template->content);
        $this->assertSame('body { color: red; }', $template->css_content);
        $this->assertSame('Fuß', $template->footer);
        // "Standard" bedeutet Default-Template dieser Gruppe, nicht der globale Fallback.
        $this->assertFalse($template->is_default);

        $managementGroup = ManagementGroup::where('description', 'Template VG1')->first();
        $this->assertSame($template->id, $managementGroup->default_billing_template_id);
    }

    public function test_a_sharegroups_template_without_standard_does_not_touch_the_management_groups_default(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Template Owner 2"
            Passwort: "geheim123"

            Group-1:
                Name: "Template Sharegroup 2"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        Name: "Nicht Standard"
                        Header: "Kopf"
                        Content: "Inhalt"

            Verwaltungsgruppe-1:
                Beschreibung: "Template VG2"
        YAML);

        $this->seed(TestDataSeeder::class);

        $managementGroup = ManagementGroup::where('description', 'Template VG2')->first();
        $this->assertNull($managementGroup->default_billing_template_id);
    }

    public function test_a_sharegroups_template_image_is_uploaded_from_the_given_local_path(): void
    {
        Storage::fake('public');

        $imagePath = sys_get_temp_dir().'/testdata-seeder-logo-'.uniqid().'.png';
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($imagePath, $pngBytes);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Template Owner 3"
            Passwort: "geheim123"

            Group-1:
                Name: "Template Sharegroup 3"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        Name: "Mit Bild"
                        Content: "Inhalt"
                        Image: $imagePath

            Verwaltungsgruppe-1:
                Beschreibung: "Template VG3"
        YAML);

        $this->seed(TestDataSeeder::class);

        unlink($imagePath);

        $template = BillingTemplate::where('name', 'Mit Bild')->first();
        $this->assertNotNull($template);
        $this->assertNotNull($template->header_image_path);
        Storage::disk('public')->assertExists($template->header_image_path);
        $this->assertSame($pngBytes, Storage::disk('public')->get($template->header_image_path));
    }

    public function test_reseeding_a_sharegroups_template_updates_it_in_place_without_duplicating(): void
    {
        $yaml = <<<'YAML'
        lang: "de"

        Account-1:
            Name: "Template Owner 4"
            Passwort: "geheim123"

            Group-1:
                Name: "Template Sharegroup 4"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        Name: "Wiederholt"
                        Content: "%s"

            Verwaltungsgruppe-1:
                Beschreibung: "Template VG4"
        YAML;

        file_put_contents($this->path, sprintf($yaml, 'Erste Fassung'));
        $this->seed(TestDataSeeder::class);

        file_put_contents($this->path, sprintf($yaml, 'Zweite Fassung'));
        $this->seed(TestDataSeeder::class);

        $this->assertSame(1, BillingTemplate::where('name', 'Wiederholt')->count());
        $this->assertSame('Zweite Fassung', BillingTemplate::where('name', 'Wiederholt')->first()->content);
    }

    public function test_a_templates_from_file_reference_is_loaded_and_used(): void
    {
        $templateFilePath = sys_get_temp_dir().'/testdata-seeder-template-'.uniqid().'.yaml';
        file_put_contents($templateFilePath, <<<'YAML'
        Name: "Datei-Template"
        Standard: true
        Header: "Datei-Kopf"
        Content: "Datei-Inhalt"
        Footer: "Datei-Fuß"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "From-File Owner"
            Passwort: "geheim123"

            Group-1:
                Name: "From-File Sharegroup"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        From-File: "$templateFilePath"

            Verwaltungsgruppe-1:
                Beschreibung: "From-File VG"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($templateFilePath);

        $template = BillingTemplate::where('name', 'Datei-Template')->first();
        $this->assertNotNull($template);
        $this->assertSame('Datei-Kopf', $template->header);
        $this->assertSame('Datei-Inhalt', $template->content);
        $this->assertSame('Datei-Fuß', $template->footer);
        $this->assertTrue($template->is_default === false); // globaler Fallback bleibt unberührt

        $managementGroup = ManagementGroup::where('description', 'From-File VG')->first();
        $this->assertSame($template->id, $managementGroup->default_billing_template_id);
    }

    public function test_a_templates_from_file_name_and_standard_can_be_overridden_by_the_entry_itself(): void
    {
        $templateFilePath = sys_get_temp_dir().'/testdata-seeder-template-'.uniqid().'.yaml';
        file_put_contents($templateFilePath, <<<'YAML'
        Name: "Ursprünglicher Name"
        Standard: true
        Content: "Datei-Inhalt"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "From-File Override Owner"
            Passwort: "geheim123"

            Group-1:
                Name: "From-File Override Sharegroup"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        Name: "Überschriebener Name"
                        Standard: false
                        From-File: "$templateFilePath"

            Verwaltungsgruppe-1:
                Beschreibung: "From-File Override VG"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($templateFilePath);

        $this->assertNull(BillingTemplate::where('name', 'Ursprünglicher Name')->first());

        $template = BillingTemplate::where('name', 'Überschriebener Name')->first();
        $this->assertNotNull($template);
        $this->assertSame('Datei-Inhalt', $template->content);

        $managementGroup = ManagementGroup::where('description', 'From-File Override VG')->first();
        $this->assertNull($managementGroup->default_billing_template_id);
    }

    /**
     * Regression: a From-File reference that doesn't resolve to a real file used to be
     * silently ignored (like genuinely optional/omitted data) – on Nutzerhinweis
     * geändert: das ist eine Fehlkonfiguration (typischerweise ein Tippfehler im Pfad)
     * und muss laut auffallen statt eine ganze Datei stillschweigend zu ignorieren.
     */
    public function test_a_missing_from_file_path_throws(): void
    {
        file_put_contents($this->path, <<<'YAML'
        lang: "de"

        Account-1:
            Name: "From-File Missing Owner"
            Passwort: "geheim123"

            Group-1:
                Name: "From-File Missing Sharegroup"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    Template-0:
                        From-File: "does/not/exist.yaml"

            Verwaltungsgruppe-1:
                Beschreibung: "From-File Missing VG"
        YAML);

        $this->expectException(\RuntimeException::class);

        $this->seed(TestDataSeeder::class);
    }

    public function test_a_from_file_template_wrapped_in_a_top_level_template_key_is_unwrapped(): void
    {
        // Real template files wrap their fields in a single top-level "Template:" key
        // (and use the "from-File" spelling) – both must be handled.
        $templateFilePath = sys_get_temp_dir().'/testdata-seeder-template-'.uniqid().'.yaml';
        file_put_contents($templateFilePath, <<<'YAML'
        Template:
            Name: "Gewickeltes Template"
            Standard: true
            Content: "Gewickelter Inhalt"
        YAML);

        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Wrapper Owner"
            Passwort: "geheim123"

            Group-1:
                Name: "Wrapper Sharegroup"
                Groups:
                    - Verwaltungsgruppe-1
                Templates:
                    from-File: "$templateFilePath"

            Verwaltungsgruppe-1:
                Beschreibung: "Wrapper VG"
        YAML);

        $this->seed(TestDataSeeder::class);
        unlink($templateFilePath);

        $template = BillingTemplate::where('name', 'Gewickeltes Template')->first();
        $this->assertNotNull($template);
        $this->assertSame('Gewickelter Inhalt', $template->content);

        $managementGroup = ManagementGroup::where('description', 'Wrapper VG')->first();
        $this->assertSame($template->id, $managementGroup->default_billing_template_id);
    }
}
