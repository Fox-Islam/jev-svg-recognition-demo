<?php

declare(strict_types=1);

namespace Phox\JevSvgDemo;

use InvalidArgumentException;

/**
 * The SVG preparation of jev-svg-recognition's jevsvg/svg.py, ported line for line: the classifier's answers were
 * recorded against that text, and tests/Unit/SvgTest.php checks every sample prepares to the same sha1.
 */
final class Svg
{
    private const array STOP = ['make', 'svg', 'icon', 'for', 'with', 'the', 'and', 'of', 'a', 'an', 'in', 'on', 'at', 'to', 'from', 'its', 'it', 'is'];

    public const array RECIPES = ['compact', 'clean', 'noto', 'rapidata'];

    public static function prepare(string $raw, string $recipe): string
    {
        return match ($recipe) {
            'compact' => self::compact($raw),
            'clean' => self::clean($raw),
            'noto' => self::clean(self::sanitise($raw)),
            'rapidata' => self::rapidata($raw),
            default => throw new InvalidArgumentException("unknown recipe {$recipe}"),
        };
    }

    /** Whitespace collapsed, ids and xmlns dropped, numbers cut to one decimal. */
    public static function compact(string $svg): string
    {
        $svg = self::re('/\s+/', ' ', $svg);
        $svg = self::re('/ id="[^"]*"/', '', $svg);
        $svg = self::re('/(\d+\.\d)\d+/', '$1', $svg);
        $svg = str_replace('> <', '><', self::re('/ xmlns="[^"]*"/', '', $svg));

        return self::strip($svg);
    }

    /** compact(), after removing the XML declaration and the root's size, namespace and style attributes. */
    public static function clean(string $raw): string
    {
        $raw = self::re('/<\?xml[^>]*>/', '', $raw);
        if (! preg_match('/^\s*<svg\b[^>]*>/u', $raw, $m)) {
            throw new InvalidArgumentException('no <svg> element at the start');
        }
        $root = $m[0];
        $root2 = self::re('/\s(width|height|xmlns(:\w+)?|version|style|enable-background|xml:space)="[^"]*"/', '', $root);

        return self::compact($root2.substr($raw, strlen($root)));
    }

    /**
     * Comments, <title>, <desc>, <metadata> and <text> removed; ids and class names renamed to i0, c0 ... with every
     * url(#...), href and CSS selector updated, because generated SVGs name their parts in them.
     */
    public static function sanitise(string $svg): string
    {
        $s = self::re('/<\?xml.*?\?>|<!DOCTYPE[^>]*>|<!--.*?-->/s', '', $svg);
        $s = self::re('/<(title|desc|metadata|text|sodipodi:namedview)\b.*?<\/\1>/s', '', $s);
        $s = self::re('/<(title|desc|metadata|text|tspan|sodipodi:namedview)\b[^>]*\/>/', '', $s);
        $s = self::re('/\s(?:inkscape|sodipodi|xml|xmlns:\w+|data-[\w-]+|aria-[\w-]+|version|enable-background|xml:space)(?::[\w-]+)?="[^"]*"/', '', $s);

        preg_match_all('/\bid="([^"]+)"/u', $s, $m);
        $ids = array_values(array_unique($m[1]));
        preg_match_all('/\bclass="([^"]+)"/u', $s, $m);
        $classes = [];
        foreach ($m[1] as $value) {
            foreach (preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $classes[] = $c;
            }
        }
        $classes = array_values(array_unique($classes));
        preg_match_all('/<style[^>]*>(.*?)<\/style>/su', $s, $m);
        preg_match_all('/\.([A-Za-z_][\w-]*)\s*[{,]/u', implode(' ', $m[1]), $m);
        foreach ($m[1] as $c) {
            if (! in_array($c, $classes, true)) {
                $classes[] = $c;
            }
        }

        foreach (self::longestFirst($ids) as $id) {  // longest first, so one id that prefixes another is not hit early
            $e = preg_quote($id, '/');
            $new = 'i'.array_search($id, $ids, true);
            $s = self::re("/\\bid=\"{$e}\"/", "id=\"{$new}\"", $s);
            $s = self::re("/#{$e}(?![\\w-])/", "#{$new}", $s);
        }
        foreach (self::longestFirst($classes) as $c) {
            $e = preg_quote($c, '/');
            $new = 'c'.array_search($c, $classes, true);
            $s = preg_replace_callback('/\bclass="([^"]*)"/u', fn ($mm) => 'class="'.implode(' ', array_map(
                fn ($t) => $t === $c ? $new : $t,
                preg_split('/\s+/u', trim($mm[1]), -1, PREG_SPLIT_NO_EMPTY),
            )).'"', $s);
            $s = self::re("/\\.{$e}(?![\\w-])/", ".{$new}", $s);
        }
        $s = self::re('/\s+/', ' ', $s);
        $s = self::re('/(\d+\.\d)\d+/', '$1', $s);

        return self::strip(str_replace('> <', '><', $s));
    }

    /** A generated SVG as asked: sanitised, cut to start at its <svg> element. */
    public static function rapidata(string $raw): string
    {
        $s = self::sanitise($raw);
        if (! str_starts_with($s, '<svg')) {
            $at = strpos($s, '<svg');
            $s = $at === false ? '' : substr($s, $at);
        }

        return $s;
    }

    /** Words of the label, four letters or longer, that appear anywhere in the SVG text. */
    public static function leaks(string $svg, string $label): array
    {
        preg_match_all('/[a-z]{4,}/u', mb_strtolower($label), $m);
        $low = mb_strtolower($svg);

        return array_values(array_filter($m[0], fn ($w) => ! in_array($w, self::STOP, true) && str_contains($low, $w)));
    }

    /** Stable, by length descending: usort keeps ties in order since PHP 8.0, as Python's sort does. */
    private static function longestFirst(array $names): array
    {
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    /** preg_replace in UTF-8 mode, where \s, \w and \d match Unicode as Python's re does on str. */
    private static function re(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern.'u', $replacement, $subject);
        if ($out === null) {
            throw new InvalidArgumentException('the SVG is not valid UTF-8');
        }

        return $out;
    }

    /** Python's str.strip(): Unicode whitespace at both ends. */
    private static function strip(string $s): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $s) ?? $s;
    }
}
