<?php

namespace App\Support;

/** Small text helpers for matching headlines about the same story. */
final class Text
{
    private const STOP = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'at', 'by', 'with', 'from', 'as', 'is', 'are', 'was', 'were',
        'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'after', 'before', 'over', 'into', 'about', 'than', 'but', 'not', 'no', 'new',
        'says', 'said', 'how', 'why', 'what', 'who', 'when', 'where', 'will', 'can', 'has', 'have', 'had', 'his', 'her', 'their', 'our', 'your', 'you',
        'we', 'they', 'he', 'she', 'just', 'more', 'most', 'up', 'out', 'vs', 'v', 'live', 'news', 'update', 'updates', 'today', 'watch', 'video'];

    /** @return array<int, string> distinct significant word stems */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true)) {
                continue;
            }
            // Crude stemming so "floods", "flooding" and "flood" match.
            $w = preg_replace('/(ing|ed|es|s)$/u', '', $w) ?: $w;
            $out[$w] = true;
        }

        return array_keys($out);
    }

    /**
     * How alike two token sets are, 0 to 1. A short search term fully contained in a headline
     * ("california flooding" in "Flooding hits California") counts as a match.
     */
    public static function similarity(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }
        $common = count(array_intersect($a, $b));
        $jaccard = $common / count(array_unique([...$a, ...$b]));
        $short = min(count($a), count($b));
        $containment = $short >= 2 ? $common / $short : 0.0;

        return max($jaccard, $containment >= 1.0 ? 0.9 : $containment * 0.6);
    }

    public static function fingerprint(array $tokens): string
    {
        $t = $tokens;
        sort($t);

        return mb_substr(implode('-', array_slice($t, 0, 8)), 0, 180);
    }
}
