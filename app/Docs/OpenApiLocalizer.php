<?php

declare(strict_types=1);

namespace App\Docs;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Str;

/**
 * Localizes a Scramble-generated OpenAPI document into a single target locale.
 *
 * Scramble produces one static document per registered API. We register two APIs
 * ("es" and "en"), each wiring an instance of this class as its
 * `afterOpenApiGenerated` hook, so every endpoint's title, summary, description
 * and tags come from the matching lang/{locale}/api.php file instead of from the
 * PHPDoc. This keeps the rendered docs fully bilingual and lets the docs UI swap
 * languages in real time by switching between /docs/api/es.json and en.json.
 */
final class OpenApiLocalizer
{
    /**
     * Map of canonical operation key ("<method> <path>") to the security scheme
     * names that apply to it. Operations missing from this map are documented as
     * public (no security requirement).
     *
     * @var array<string, list<string>>
     */
    private const SECURITY_MAP = [
        'post v3/webhook/singapur' => ['singapurSecret'],
        'post v3/webhook/docusign' => ['docusignSignature'],
        'post v3/webhook/mua-bot' => ['muaBotSignature'],
        'get v3/mua-bot/pending' => ['muaBotApiKey'],
        'get v3/auth/me' => ['bearerAuth'],
        'post v3/auth/logout' => ['bearerAuth'],
        'post v3/auth/refresh' => ['bearerAuth'],
        'get v3/registrations' => ['bearerAuth'],
        'get v3/registrations/{singapurClientCode}' => ['bearerAuth'],
        'post v3/registrations/{singapurClientCode}/advance' => ['bearerAuth'],
        'post v3/registrations/{registration}/legal-names' => ['bearerAuth'],
        'delete v3/registrations/{registration}/legal-names/{legalName}' => ['bearerAuth'],
    ];

    /**
     * @param  string  $locale  Target locale ("es" or "en") whose lang/{locale}/api.php drives the output.
     */
    public function __construct(
        private readonly string $locale,
    ) {}

    /**
     * Apply the localization to the generated OpenAPI document.
     *
     * @param  OpenApi  $openApi  The freshly generated document (mutated in place).
     */
    public function __invoke(OpenApi $openApi): void
    {
        $strings = (array) trans('api', [], $this->locale);

        $this->applyInfo($openApi, $strings);
        $this->registerSecuritySchemes($openApi);
        $this->localizeOperations($openApi, $strings);
    }

    /**
     * Set the document title and description from the lang file.
     *
     * @param  OpenApi  $openApi  Document to mutate.
     * @param  array<string, mixed>  $strings  Decoded lang/{locale}/api.php contents.
     */
    private function applyInfo(OpenApi $openApi, array $strings): void
    {
        $info = $strings['info'] ?? [];

        if (! empty($info['title'])) {
            $openApi->info->title = (string) $info['title'];
        }

        if (! empty($info['description'])) {
            $openApi->info->description = (string) $info['description'];
        }
    }

    /**
     * Register the security schemes used across the China integration endpoints.
     *
     * Defined once on the document components; individual operations reference them
     * by name through {@see self::SECURITY_MAP}.
     *
     * @param  OpenApi  $openApi  Document to mutate.
     */
    private function registerSecuritySchemes(OpenApi $openApi): void
    {
        $schemes = [
            'bearerAuth' => SecurityScheme::http('bearer', 'JWT'),
            'singapurSecret' => SecurityScheme::apiKey('header', 'X-Nexum-Secret'),
            'muaBotSignature' => SecurityScheme::apiKey('header', 'X-Signature'),
            'muaBotApiKey' => SecurityScheme::apiKey('header', 'X-Bot-Api-Key'),
            'docusignSignature' => SecurityScheme::apiKey('header', 'X-DocuSign-Signature-1'),
        ];

        foreach ($schemes as $name => $scheme) {
            $openApi->components->addSecurityScheme($name, $scheme->as($name));
        }
    }

    /**
     * Localize every operation's summary, description, tags and security.
     *
     * @param  OpenApi  $openApi  Document to mutate.
     * @param  array<string, mixed>  $strings  Decoded lang/{locale}/api.php contents.
     */
    private function localizeOperations(OpenApi $openApi, array $strings): void
    {
        /** @var array<string, array<string, string>> $operations */
        $operations = $strings['operations'] ?? [];

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                $key = $this->canonicalKey($operation->method, $path->path);

                if (isset($operations[$key])) {
                    $translation = $operations[$key];

                    if (! empty($translation['summary'])) {
                        $operation->summary = (string) $translation['summary'];
                    }

                    if (! empty($translation['description'])) {
                        $operation->description = (string) $translation['description'];
                    }

                    if (! empty($translation['tag'])) {
                        $operation->tags = [(string) $translation['tag']];
                    }
                }

                $this->applySecurity($operation, $key);
            }
        }
    }

    /**
     * Attach the security requirement(s) for an operation, or mark it public.
     *
     * @param  Operation  $operation  Operation to mutate.
     * @param  string  $key  Canonical "<method> <path>" key.
     */
    private function applySecurity(Operation $operation, string $key): void
    {
        $schemes = self::SECURITY_MAP[$key] ?? [];

        $operation->security = array_map(
            static fn (string $scheme): SecurityRequirement => new SecurityRequirement([$scheme => []]),
            $schemes,
        );
    }

    /**
     * Build the canonical lookup key for an operation: lowercased HTTP method, a
     * space, then the path stripped of any leading "api/" prefix and slashes.
     *
     * Scramble strips the "api" path segment from documented paths, but we
     * normalize defensively so the same lang keys work regardless of how the
     * server prefix is configured.
     *
     * @param  string  $method  HTTP method (any case).
     * @param  string  $path  OpenAPI path, e.g. "/v3/webhook/singapur".
     * @return string Canonical key, e.g. "post v3/webhook/singapur".
     */
    private function canonicalKey(string $method, string $path): string
    {
        $normalized = ltrim($path, '/');
        $normalized = Str::after($normalized, 'api/') ?: $normalized;
        $normalized = ltrim($normalized, '/');

        return strtolower($method).' '.$normalized;
    }
}
