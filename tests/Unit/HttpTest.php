<?php

declare(strict_types=1);

use Phox\JevSvgDemo\Http;

function http(?Closure $jev = null, array $env = []): Http
{
    return new Http(
        dirname(__DIR__, 2),
        ['127.0.0.1', 'localhost'],
        $jev ?? fn () => throw new RuntimeException('Jev was called'),
        fn (string $name) => $env[$name] ?? null,
    );
}

function call(Http $http, string $method, string $path, array $body = [], array $headers = []): array
{
    [$status, , $out] = $http->respond($method, $path, $headers + ['host' => '127.0.0.1:8767'], json_encode($body));

    return [$status, json_decode($out, true)];
}

const SVG = '<svg viewBox="0 0 10 10"><!-- a lion --><circle cx="5" cy="5" r="4" fill="#f80"/></svg>';

it('refuses a request addressed to a host outside the list', function () {
    [$status] = call(http(), 'GET', '/api/catalog', headers: ['host' => 'example.com']);

    expect($status)->toBe(403);
});

it('lists the models, the samples and which providers the server holds a key for', function () {
    [$status, $c] = call(http(env: ['OPENROUTER_API_KEY' => 'k']), 'GET', '/api/catalog');

    expect($status)->toBe(200)
        ->and(array_keys($c['models']))->toBe(['emoji', 'objects'])
        ->and($c['models']['objects']['questions'])->toHaveCount(100)
        ->and($c['providers']['openrouter']['server'])->toBeTrue()
        ->and($c['providers']['typesafe']['server'])->toBeFalse()
        ->and($c['samples'])->not->toBeEmpty();
});

it('serves only the sample files the index lists', function () {
    expect(call(http(), 'GET', '/api/samples/objects-00.svg')[0])->toBe(200)
        ->and(call(http(), 'GET', '/api/samples/..%2Fcomposer.json')[0])->toBe(404)
        ->and(call(http(), 'GET', '/api/samples/index.json')[0])->toBe(404);
});

it('sanitises an SVG and reports label words left in it', function () {
    [$status, $out] = call(http(), 'POST', '/api/prepare', ['svg' => SVG, 'recipe' => 'rapidata', 'label' => 'lion']);

    expect($status)->toBe(200)
        ->and($out['prepared'])->not->toContain('lion')
        ->and($out['leaks'])->toBe([]);
});

it('refuses text that is not an SVG, and an unknown recipe', function () {
    expect(call(http(), 'POST', '/api/prepare', ['svg' => 'hello', 'recipe' => 'rapidata'])[0])->toBe(400)
        ->and(call(http(), 'POST', '/api/prepare', ['svg' => SVG, 'recipe' => 'other'])[0])->toBe(400);
});

it('refuses more questions than one call holds', function () {
    [$status, $out] = call(http(), 'POST', '/api/ask', ['prepared' => SVG, 'questions' => array_fill(0, 26, 'Is it red?')], ['x-jev-key' => 'k']);

    expect($status)->toBe(400)->and($out['error'])->toContain('1 to 25');
});

it('asks for a key when neither the page nor the server has one', function () {
    [$status, $out] = call(http(), 'POST', '/api/ask', ['prepared' => SVG, 'questions' => ['Is it red?']]);

    expect($status)->toBe(400)->and($out['error'])->toContain('TYPESAFE_API_KEY');
});

it('passes the questions to Jev with the key and provider the page sent, and returns the answers in order', function () {
    $seen = null;
    $jev = function (string $key, string $provider, string $svg, array $questions) use (&$seen) {
        $seen = compact('key', 'provider', 'svg', 'questions');

        return ['answers' => [0.9, 0.1], 'model' => 'jev-test', 'usage' => ['input_tokens' => 10, 'cost' => null]];
    };
    [$status, $out] = call(http($jev), 'POST', '/api/ask', ['prepared' => SVG, 'questions' => ['Is it red?', 'Is it round?']],
        ['x-jev-key' => 'mine', 'x-jev-provider' => 'openrouter']);

    expect($status)->toBe(200)
        ->and($out['answers'])->toBe([0.9, 0.1])
        ->and($out)->toHaveKey('ms')
        ->and($seen)->toBe(['key' => 'mine', 'provider' => 'openrouter', 'svg' => SVG, 'questions' => ['Is it red?', 'Is it round?']]);
});

it('falls back to the server key for the provider named', function () {
    $used = null;
    $jev = function (string $key) use (&$used) {
        $used = $key;

        return ['answers' => [0.5], 'model' => 'jev-test', 'usage' => []];
    };
    call(http($jev, ['OPENROUTER_API_KEY' => 'server-key']), 'POST', '/api/ask', ['prepared' => SVG, 'questions' => ['Is it red?']], ['x-jev-provider' => 'openrouter']);

    expect($used)->toBe('server-key');
});
