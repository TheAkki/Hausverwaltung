<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiDocumentationController;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

// KI-generiert
class ApiDocumentationControllerTest extends TestCase
{
    private const API_PREFIX = 'api/';

    private const IGNORED_METHODS = ['HEAD', 'PATCH'];

    public function test_the_swagger_ui_page_points_to_the_spec(): void
    {
        $this->withoutVite();

        $this->get(route('api-docs'))->assertOk()->assertSee(route('api-docs.spec'), false);
    }

    public function test_the_spec_is_served_as_yaml(): void
    {
        $response = $this->get(route('api-docs.spec'));

        $response->assertOk()->assertHeader('content-type', 'application/yaml');
        $this->assertSame('3.0.3', Yaml::parse($response->getContent())['openapi']);
    }

    public function test_every_api_route_is_documented(): void
    {
        $paths = Yaml::parseFile(resource_path(ApiDocumentationController::SPEC_PATH))['paths'];

        $apiRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => Str::startsWith($route->uri(), self::API_PREFIX));

        $this->assertNotEmpty($apiRoutes);

        foreach ($apiRoutes as $route) {
            $path = '/'.Str::after($route->uri(), self::API_PREFIX);

            foreach (array_diff($route->methods(), self::IGNORED_METHODS) as $method) {
                $this->assertArrayHasKey(
                    strtolower($method), $paths[$path] ?? [],
                    "{$method} {$path} fehlt in der OpenAPI-Spezifikation."
                );
            }
        }
    }

    public function test_every_documented_operation_exists_as_a_route(): void
    {
        $paths = Yaml::parseFile(resource_path(ApiDocumentationController::SPEC_PATH))['paths'];

        foreach ($paths as $path => $operations) {
            foreach (array_diff(array_keys($operations), ['parameters']) as $method) {
                $uri = self::API_PREFIX.ltrim($path, '/');
                $route = collect(RouteFacade::getRoutes()->getRoutes())
                    ->first(fn (Route $route) => $route->uri() === $uri && in_array(strtoupper($method), $route->methods(), true));

                $this->assertNotNull($route, strtoupper($method)." {$path} ist dokumentiert, aber nicht geroutet.");
            }
        }
    }
}
