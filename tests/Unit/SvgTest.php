<?php

declare(strict_types=1);

use Phox\JevSvgDemo\Svg;

it('prepares every sample to the text the classifier was trained on', function () {
    $root = dirname(__DIR__, 2);
    $samples = json_decode((string) file_get_contents("{$root}/samples/index.json"), true);
    expect($samples)->not->toBeEmpty();
    foreach ($samples as $s) {
        $prepared = Svg::prepare((string) file_get_contents("{$root}/samples/{$s['file']}"), $s['recipe']);
        expect(sha1($prepared))->toBe($s['sha1_colour'], $s['file']);
    }
});

it('removes every name and keeps references working', function () {
    $svg = '<?xml version="1.0"?><svg viewBox="0 0 10 10"><!-- Bottle cap --><title>water bottle</title>'
        .'<defs><linearGradient id="bottleGrad"><stop stop-color="#000"/></linearGradient></defs>'
        .'<style>.cap{fill:#00f}</style><rect class="cap" fill="url(#bottleGrad)"/><text>bottle</text><use href="#bottleGrad"/></svg>';
    $out = Svg::sanitise($svg);

    expect(Svg::leaks($out, 'a bottle of water'))->toBe([])
        ->and($out)->not->toContain('cap')->not->toContain('bottleGrad')
        ->and($out)->toContain('id="i0"')->toContain('url(#i0)')->toContain('href="#i0"')
        ->and($out)->toContain('.c0{')->toContain('class="c0"');
});

it('refuses text that is not valid UTF-8', function () {
    Svg::sanitise("<svg>\xff</svg>");
})->throws(InvalidArgumentException::class);
