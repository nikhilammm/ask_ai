<?php
declare(strict_types=1);

final class ContentDetector
{
    public static function detect(string $text): array
    {
        $text = self::normalize($text);
        $tocHeadings = self::tableOfContentsHeadings($text);
        if (count($tocHeadings) >= 2) {
            return self::chaptersFromHeadings($text, $tocHeadings);
        }
        $pattern = '/^(?:(?:unit|chapter|lesson|पाठ|अध्याय|इकाई)\s*[0-9०-९IVXLCDM]+(?:\s*[-–—:.]\s*|\s+).+)$/imu';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        $headings = $matches[0] ?? [];
        if (!$headings) {
            $headings = self::numberedTitleHeadings($text);
        }
        if (!$headings) {
            $title = self::firstTitle($text);
            return [[
                'title' => $title,
                'content' => $text,
                'lessons' => [['title' => $title, 'content' => $text]],
            ]];
        }

        $chapters = [];
        foreach ($headings as $index => [$heading, $offset]) {
            $end = $headings[$index + 1][1] ?? strlen($text);
            $content = trim(substr($text, $offset, $end - $offset));
            if (mb_strlen($content) < 20) {
                continue;
            }
            $title = trim(preg_replace('/\s+/', ' ', $heading));
            $chapters[] = [
                'title' => mb_substr($title, 0, 255),
                'content' => $content,
                'lessons' => [['title' => mb_substr($title, 0, 255), 'content' => $content]],
            ];
        }

        return $chapters ?: [[
            'title' => self::firstTitle($text),
            'content' => $text,
            'lessons' => [['title' => self::firstTitle($text), 'content' => $text]],
        ]];
    }

    private static function chaptersFromHeadings(string $text, array $headings): array
    {
        $chapters = [];
        foreach ($headings as $index => [$heading, $offset]) {
            $end = $headings[$index + 1][1] ?? strlen($text);
            $content = trim(substr($text, $offset, $end - $offset));
            if (mb_strlen($content) < 20) {
                continue;
            }
            $title = trim(preg_replace('/\s+/', ' ', $heading));
            $chapters[] = [
                'title' => mb_substr($title, 0, 255),
                'content' => $content,
                'lessons' => [['title' => mb_substr($title, 0, 255), 'content' => $content]],
            ];
        }
        return $chapters;
    }

    private static function tableOfContentsHeadings(string $text): array
    {
        $contentsPosition = stripos($text, 'CONTENTS');
        if ($contentsPosition === false || $contentsPosition > 3000) {
            return [];
        }
        $contentsWindow = substr($text, $contentsPosition, 6000);
        preg_match_all('/^\s*([0-9]{1,2})[.)]?\s+(.{3,90}?)\s+([0-9]{1,3})\s*$/mu', $contentsWindow, $matches, PREG_OFFSET_CAPTURE);
        $titles = [];
        $expectedNumber = 1;
        $contentsEnd = $contentsPosition;
        foreach ($matches[0] ?? [] as $index => [$line, $relativeOffset]) {
            $number = (int)($matches[1][$index][0] ?? 0);
            if ($number !== $expectedNumber) {
                if ($titles) {
                    break;
                }
                continue;
            }
            $title = trim((string)($matches[2][$index][0] ?? ''));
            if (!self::looksLikeBookTitle($title)) {
                break;
            }
            $titles[] = $title;
            $expectedNumber++;
            $contentsEnd = $contentsPosition + (int)$relativeOffset + strlen((string)$line);
        }
        if (count($titles) < 2) {
            return [];
        }
        $headings = [];
        foreach ($titles as $index => $title) {
            $pattern = '/^(?:[0-9]{1,3}[.)]?\s+)?' . preg_quote($title, '/') . '(?:\s+[0-9]{1,3})?\s*$/imu';
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $contentsEnd)) {
                $headings[] = [($index + 1) . ' ' . $title, (int)$match[0][1]];
            }
        }
        return $headings;
    }

    private static function looksLikeBookTitle(string $title): bool
    {
        if ($title === '' || mb_strlen($title) > 90
            || preg_match('/^(?:model test|listening text|answer|exercise|activity|reading is fun|word building)\b/iu', $title)) {
            return false;
        }
        $words = preg_split('/\s+/u', $title) ?: [];
        return count($words) >= 2 && count($words) <= 12;
    }

    private static function normalize(string $text): string
    {
        $text = str_replace("\r", "\n", $text);
        // Some embedded school-book fonts expose every visible glyph three or
        // more times ("RRRiiiaaa" instead of "Ria"). Repair those runs before
        // heading detection so real titles win over exercise labels such as
        // "Reading is Fun" and "Word Building".
        $text = preg_replace('/(.)\1{2,}/u', '$1', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim((string)$text);
    }

    private static function firstTitle(string $text): string
    {
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if (mb_strlen($line) >= 3 && mb_strlen($line) <= 120) {
                return mb_substr($line, 0, 255);
            }
        }
        return 'Uploaded Book Content';
    }

    /**
     * School readers commonly print headings as "2 I Have Fun" or
     * "7 Flying-Man" instead of "Chapter 2 - I Have Fun". Keep this
     * fallback conservative so numbered questions do not become chapters.
     */
    private static function numberedTitleHeadings(string $text): array
    {
        preg_match_all('/^(?:[0-9]{1,3})[.)]?\s+([^\r\n]{3,100})$/mu', $text, $matches, PREG_OFFSET_CAPTURE);
        $numbered = [];
        foreach ($matches[0] ?? [] as $index => [$line, $offset]) {
            $title = trim((string)($matches[1][$index][0] ?? ''));
            if (preg_match('/^scan\s*me$/iu', $title)) {
                $nearbyTitle = self::previousHeadingCandidate($text, (int)$offset);
                if ($nearbyTitle !== null) {
                    $numbered[] = [$nearbyTitle['title'], $nearbyTitle['offset']];
                }
                continue;
            }
            if ($title === '' || preg_match('/[?!:.\d]$/u', $title) || str_contains($title, ')')) {
                continue;
            }
            $words = preg_split('/\s+/u', $title) ?: [];
            if (count($words) < 2 || count($words) > 12) {
                continue;
            }
            if (preg_match('/^(?:answer|write|tick|match|read|exercise|activity|model test|page|reading is fun|word building|word meaning|grammar corner|let.?s write|scan me)\b/iu', $title)) {
                continue;
            }
            preg_match('/^([0-9]{1,3})/', trim($line), $numberMatch);
            $number = (int)($numberMatch[1] ?? 0);
            if ($number < 1 || $number > 99) {
                continue;
            }
            $letterWords = array_filter($words, static fn(string $word): bool => (bool)preg_match('/\p{L}/u', $word));
            $titleWords = array_filter($letterWords, static fn(string $word): bool => (bool)preg_match('/^\p{Lu}/u', $word));
            if (!$letterWords || count($titleWords) < (int)ceil(count($letterWords) / 2)) {
                continue;
            }
            // Short numbered exercise options often look like headings
            // (for example "1. Fish fly"). A genuine two-word English
            // chapter title uses title case for both words.
            if (count($letterWords) === 2 && count($titleWords) < 2) {
                continue;
            }
            $numbered[] = [$line, $offset];
        }
        // A second common layout prints the title first and a decorative
        // repeated chapter number on the following line. Glyph normalization
        // turns "222" into "2", making this safe to recognize here.
        $headings = [];
        preg_match_all('/^([^\r\n]{3,100})\R([0-9]{1,2})$/mu', $text, $stacked, PREG_OFFSET_CAPTURE);
        foreach ($stacked[0] ?? [] as $index => [$block, $offset]) {
            $title = trim((string)($stacked[1][$index][0] ?? ''));
            if ($title === '' || preg_match('/[?!:.\d]$/u', $title) || str_contains($title, ')')) {
                continue;
            }
            $words = preg_split('/\s+/u', $title) ?: [];
            if (count($words) < 2 || count($words) > 12) {
                continue;
            }
            $letterWords = array_filter($words, static fn(string $word): bool => (bool)preg_match('/\p{L}/u', $word));
            $titleWords = array_filter($letterWords, static fn(string $word): bool => (bool)preg_match('/^\p{Lu}/u', $word));
            if (!$letterWords || count($titleWords) < min(2, count($letterWords))) {
                continue;
            }
            $headings[] = [$title, $offset];
        }
        usort($headings, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $headings = array_values(array_reduce($headings, static function (array $carry, array $heading): array {
            $carry[(string)$heading[1]] = $heading;
            return $carry;
        }, []));
        if (count($headings) >= 3) {
            return $headings;
        }
        return count($numbered) >= 2 ? $numbered : [];
    }

    private static function previousHeadingCandidate(string $text, int $offset): ?array
    {
        $start = max(0, $offset - 500);
        $window = substr($text, $start, $offset - $start);
        $lines = preg_split('/\R/u', $window, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            [$candidate, $relativeOffset] = $lines[$index];
            $candidate = trim((string)$candidate);
            if ($candidate === '' || mb_strlen($candidate) < 3 || mb_strlen($candidate) > 100) {
                continue;
            }
            if (preg_match('/[?!:.,;\d]$/u', $candidate)
                || preg_match('/^(?:scan me|word meaning|reading is fun|word building|grammar corner|let.?s write|exercise|activity|english reader)\b/iu', $candidate)) {
                continue;
            }
            $words = preg_split('/\s+/u', $candidate) ?: [];
            if (count($words) < 2 || count($words) > 10) {
                continue;
            }
            $letterWords = array_filter($words, static fn(string $word): bool => (bool)preg_match('/\p{L}/u', $word));
            $titleWords = array_filter($letterWords, static fn(string $word): bool => (bool)preg_match('/^\p{Lu}/u', $word));
            if ($letterWords && count($titleWords) >= (int)ceil(count($letterWords) / 2)) {
                return ['title' => $candidate, 'offset' => $start + (int)$relativeOffset];
            }
        }
        return null;
    }
}
