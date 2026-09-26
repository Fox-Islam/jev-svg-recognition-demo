<?php

declare(strict_types=1);

namespace Phox\JevSvgDemo;

use Closure;
use InvalidArgumentException;
use JsonException;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Exceptions\ApiException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Retry\RetryPolicy;

/**
 * The page, and the two things it needs a server for: preparing an SVG, and asking Jev a batch of questions.
 * The classifier runs in the page (resources/classify.js).
 */
final class Http
{
    /** Where a request may be addressed without JEV_SVG_DEMO_HOSTS; a server key must not be spent from elsewhere */
    public const string HOSTS = '127.0.0.1,localhost';

    /** Characters of prepared SVG text: a Noto SVG of 57,722 got no answer from Jev */
    public const int MAX_SVG = 20000;

    /** Questions per call, as the classifier's answers were recorded */
    public const int MAX_QUESTIONS = 25;

    public const array PROVIDERS = [
        'typesafe' => ['label' => 'TypeSafe', 'env' => 'TYPESAFE_API_KEY'],
        'openrouter' => ['label' => 'OpenRouter', 'env' => 'OPENROUTER_API_KEY'],
    ];

    private const array STATIC = [
        '/' => ['resources/page.html', 'text/html; charset=utf-8'],
        '/classify.js' => ['resources/classify.js', 'text/javascript; charset=utf-8'],
    ];

    /**
     * @param  list<string>  $hosts
     * @param  Closure(string $key, string $provider, string $svg, list<string> $questions): array  $jev  answers, model, usage
     * @param  Closure(string): ?string  $env
     */
    public function __construct(
        private readonly string $root,
        private readonly array $hosts,
        private readonly Closure $jev,
        private readonly Closure $env,
    ) {}

    public static function fromEnvironment(string $root): self
    {
        $file = self::dotEnv("{$root}/.env");
        $env = fn (string $name): ?string => ($v = getenv($name)) !== false && $v !== '' ? $v : ($file[$name] ?? null);
        $hosts = array_values(array_filter(array_map('trim', explode(',', $env('JEV_SVG_DEMO_HOSTS') ?? self::HOSTS))));

        return new self($root, $hosts, Closure::fromCallable([self::class, 'askJev']), $env);
    }

    public function handle(): void
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        [$status, $type, $body] = $this->respond(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $headers,
            (string) file_get_contents('php://input'),
        );
        http_response_code($status);
        header("Content-Type: {$type}");
        header('Cache-Control: no-store');
        echo $body;
    }

    /**
     * @param  array<string, string>  $headers  lower-case names
     * @return array{int, string, string} status, content type, body
     */
    public function respond(string $method, string $path, array $headers, string $body): array
    {
        $host = strtolower(trim((string) preg_replace('/:\d+$/', '', $headers['host'] ?? ''), '[]'));
        if (! in_array($host, $this->hosts, true)) {
            return self::json(403, ['error' => 'requests to '.($host ?: 'this host').' are refused; set JEV_SVG_DEMO_HOSTS to allow it']);
        }
        try {
            if ($method === 'GET' && isset(self::STATIC[$path])) {
                [$file, $type] = self::STATIC[$path];

                return [200, $type, (string) file_get_contents("{$this->root}/{$file}")];
            }
            if ($method === 'GET' && $path === '/api/catalog') {
                return self::json(200, $this->catalog());
            }
            if ($method === 'GET' && preg_match('#^/api/samples/([\w.-]+)$#', $path, $m)) {
                return $this->sample($m[1]);
            }
            if ($method === 'POST' && $path === '/api/prepare') {
                return self::json(200, $this->prepare(self::body($body)));
            }
            if ($method === 'POST' && $path === '/api/ask') {
                return $this->ask(self::body($body), trim($headers['x-jev-key'] ?? ''), $headers['x-jev-provider'] ?? 'typesafe');
            }

            return self::json(404, ['error' => 'not found']);
        } catch (BadRequest $e) {
            return self::json(400, ['error' => $e->getMessage()]);
        }
    }

    private function catalog(): array
    {
        $models = [];
        foreach (glob("{$this->root}/models/*.json") ?: [] as $file) {
            $models[basename($file, '.json')] = json_decode((string) file_get_contents($file), true);
        }
        $providers = [];
        foreach (self::PROVIDERS as $id => $p) {
            $providers[$id] = ['label' => $p['label'], 'server' => ($this->env)($p['env']) !== null];
        }

        return ['models' => $models, 'providers' => $providers, 'samples' => $this->samples()];
    }

    private function samples(): array
    {
        return json_decode((string) file_get_contents("{$this->root}/samples/index.json"), true);
    }

    private function sample(string $file): array
    {
        // only files the index lists, so no path reaches outside samples/
        if (! in_array($file, array_column($this->samples(), 'file'), true)) {
            return self::json(404, ['error' => 'no such sample']);
        }

        return self::json(200, ['svg' => (string) file_get_contents("{$this->root}/samples/{$file}")]);
    }

    private function prepare(array $body): array
    {
        $svg = $body['svg'] ?? null;
        $recipe = $body['recipe'] ?? null;
        if (! is_string($svg) || ! str_contains($svg, '<svg')) {
            throw new BadRequest('svg: the text of an SVG file');
        }
        if (! in_array($recipe, Svg::RECIPES, true)) {
            throw new BadRequest('recipe: one of '.implode(', ', Svg::RECIPES));
        }
        try {
            $prepared = Svg::prepare($svg, $recipe);
        } catch (InvalidArgumentException $e) {
            throw new BadRequest('could not prepare this SVG: '.$e->getMessage());
        }
        if (strlen($prepared) > self::MAX_SVG) {
            throw new BadRequest('the prepared SVG is '.number_format(strlen($prepared)).' characters; Jev is only asked about '.number_format(self::MAX_SVG).' or fewer');
        }
        $label = is_string($body['label'] ?? null) ? $body['label'] : '';

        return ['prepared' => $prepared, 'raw_chars' => strlen($svg), 'chars' => strlen($prepared), 'leaks' => $label !== '' ? Svg::leaks($prepared, $label) : []];
    }

    private function ask(array $body, string $key, string $provider): array
    {
        $svg = $body['prepared'] ?? null;
        $questions = $body['questions'] ?? null;
        if (! is_string($svg) || ! str_starts_with($svg, '<svg') || strlen($svg) > self::MAX_SVG) {
            throw new BadRequest('prepared: the text /api/prepare returned');
        }
        if (! is_array($questions) || ! array_is_list($questions) || $questions === [] || count($questions) > self::MAX_QUESTIONS
            || array_filter($questions, fn ($q) => ! is_string($q) || $q === '' || strlen($q) > 300) !== []) {
            throw new BadRequest('questions: 1 to '.self::MAX_QUESTIONS.' question texts');
        }
        if (! isset(self::PROVIDERS[$provider])) {
            throw new BadRequest('provider: '.implode(' or ', array_keys(self::PROVIDERS)));
        }
        $key = $key !== '' ? $key : (($this->env)(self::PROVIDERS[$provider]['env']) ?? '');
        if ($key === '') {
            throw new BadRequest('no API key: type one in, or set '.self::PROVIDERS[$provider]['env'].' for the server');
        }
        $started = hrtime(true);
        try {
            $out = ($this->jev)($key, $provider, $svg, $questions);
        } catch (ApiException $e) {
            return self::json(502, ['error' => "Jev answered {$e->getStatus()}", 'detail' => mb_substr((string) json_encode($e->getBody()), 0, 300)]);
        } catch (TypeSafeException $e) {
            return self::json(502, ['error' => 'could not reach Jev: '.$e->getMessage()]);
        }

        return self::json(200, $out + ['ms' => intdiv(hrtime(true) - $started, 1_000_000)]);
    }

    /** One request to Jev: each question a yes/no over state {svg}, in order. No retries, so the page shows what happened. */
    public static function askJev(string $key, string $provider, string $svg, array $questions): array
    {
        $client = Client::make($key);
        if ($provider === 'openrouter') {
            $client->openRouter();
        }
        $request = $client->systemOne()->state(['svg' => $svg])->timeout(60)->retry(RetryPolicy::none());
        foreach ($questions as $i => $q) {
            $request->noul(sprintf('q%02d', $i), $q);
        }
        $response = $request->send();

        return [
            'answers' => array_map(fn ($i) => $response->noul(sprintf('q%02d', $i))->noul(), array_keys($questions)),
            'model' => $response->model(),
            'usage' => ['input_tokens' => $response->usage()->inputTokens(), 'cost' => $response->usage()->cost()],
        ];
    }

    private static function body(string $body): array
    {
        if (strlen($body) > 2_000_000) {
            throw new BadRequest('the request is larger than 2 MB');
        }
        try {
            $data = json_decode($body === '' ? '{}' : $body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequest('the body is not JSON');
        }
        if (! is_array($data)) {
            throw new BadRequest('the body is not a JSON object');
        }

        return $data;
    }

    private static function json(int $status, array $data): array
    {
        return [$status, 'application/json', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /** A .env beside the server: KEY=value lines; the environment wins where both set a name. */
    private static function dotEnv(string $path): array
    {
        $out = [];
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (str_contains($line, '=') && ! str_starts_with(ltrim($line), '#')) {
                [$k, $v] = explode('=', $line, 2);
                $out[trim($k)] = trim(trim($v), '"');
            }
        }

        return $out;
    }
}
