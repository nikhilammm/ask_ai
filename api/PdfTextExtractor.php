<?php
declare(strict_types=1);

final class PdfTextExtractor
{
    public static function extract(string $path): string
    {
        $pdf = file_get_contents($path);
        if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('The PDF could not be read.');
        }

        $parts = [];
        $textStreams = [];
        $mapStreams = [];
        $streamCount = 0;
        $decodedCount = 0;
        // Avoid one giant PCRE over the complete binary PDF. On shared PHP
        // hosting it can silently hit the PCRE/JIT limit on otherwise valid
        // 15-100 MB files and report that no editable text exists.
        foreach (self::streams($pdf) as $stream) {
            $streamCount++;
            $decoded = self::decodeStream($stream);
            if ($decoded !== $stream) {
                $decodedCount++;
            }
            if (str_contains($decoded, 'beginbfchar') || str_contains($decoded, 'beginbfrange')) {
                $mapStreams[] = $decoded;
            } elseif (str_contains($decoded, 'BT') && str_contains($decoded, 'ET')) {
                $textStreams[] = $decoded;
            }
        }
        $unicodeMaps = self::unicodeMaps($mapStreams);
        $namedMaps = self::namedUnicodeMaps($pdf);
        foreach ($textStreams as $decoded) {
            $text = self::textOperators($decoded, $unicodeMaps, $namedMaps);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $text = self::clean(implode("\n", $parts));
        if (mb_strlen($text) < 20) {
            throw new RuntimeException(
                'No editable text was found in this PDF. Diagnostic: streams=' . $streamCount .
                ', decoded=' . $decodedCount . ', maps=' . count($unicodeMaps) .
                ', text_streams=' . count($textStreams) . ', text_parts=' . count($parts) . '.'
            );
        }
        return $text;
    }

    private static function streams(string $pdf): iterable
    {
        $offset = 0;
        $length = strlen($pdf);
        while ($offset < $length && ($marker = strpos($pdf, 'stream', $offset)) !== false) {
            $after = $marker + 6;
            $first = $pdf[$after] ?? '';
            if ($first !== "\r" && $first !== "\n") {
                $offset = $after;
                continue;
            }
            if ($first === "\r" && ($pdf[$after + 1] ?? '') === "\n") {
                $after += 2;
            } else {
                $after += 1;
            }
            $end = strpos($pdf, 'endstream', $after);
            if ($end === false) {
                break;
            }
            $stream = substr($pdf, $after, $end - $after);
            yield rtrim($stream, "\r\n");
            $offset = $end + 9;
        }
    }

    private static function objects(string $pdf): iterable
    {
        $offset = 0;
        $length = strlen($pdf);
        while ($offset < $length && preg_match('/(\d+)\s+\d+\s+obj\b/', $pdf, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $id = (int)$match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $end = strpos($pdf, 'endobj', $start);
            if ($end === false) {
                break;
            }
            yield [$id, substr($pdf, $start, $end - $start)];
            $offset = $end + 6;
        }
    }

    private static function streamFromObject(string $body): ?string
    {
        $marker = strpos($body, 'stream');
        if ($marker === false) {
            return null;
        }
        $start = $marker + 6;
        if (($body[$start] ?? '') === "\r" && ($body[$start + 1] ?? '') === "\n") {
            $start += 2;
        } elseif (($body[$start] ?? '') === "\r" || ($body[$start] ?? '') === "\n") {
            $start++;
        } else {
            return null;
        }
        $end = strpos($body, 'endstream', $start);
        return $end === false ? null : rtrim(substr($body, $start, $end - $start), "\r\n");
    }

    private static function namedUnicodeMaps(string $pdf): array
    {
        $fontToCmap = [];
        $mapsByObject = [];
        foreach (self::objects($pdf) as [$id, $body]) {
            if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $match)) {
                $fontToCmap[$id] = (int)$match[1];
            }
        }
        $cmapObjects = array_fill_keys(array_values($fontToCmap), true);
        foreach (self::objects($pdf) as [$id, $body]) {
            if (!isset($cmapObjects[$id]) || !str_contains($body, 'stream')) {
                continue;
            }
            $stream = self::streamFromObject($body);
            if ($stream === null) {
                continue;
            }
            $decoded = self::decodeStream($stream);
            if (!str_contains($decoded, 'beginbfchar') && !str_contains($decoded, 'beginbfrange')) {
                continue;
            }
            $parsed = self::unicodeMaps([$decoded]);
            if ($parsed) {
                $mapsByObject[$id] = $parsed[0];
            }
        }
        $named = [];
        foreach (self::objects($pdf) as [, $body]) {
            if (!preg_match_all('/\/([A-Za-z][A-Za-z0-9]*)\s+(\d+)\s+\d+\s+R/', $body, $refs, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($refs as $ref) {
                $fontObject = (int)$ref[2];
                $cmapObject = $fontToCmap[$fontObject] ?? 0;
                if (!$cmapObject || !isset($mapsByObject[$cmapObject])) {
                    continue;
                }
                $name = $ref[1];
                $fingerprint = md5(serialize($mapsByObject[$cmapObject]));
                $named[$name][$fingerprint] = $mapsByObject[$cmapObject];
            }
        }
        return array_map('array_values', $named);
    }

    private static function decodeStream(string $stream): string
    {
        $candidates = [$stream];
        $trimmed = ltrim($stream, "\r\n");
        foreach ([$trimmed, substr($trimmed, 0, -1), substr($trimmed, 0, -2)] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $decoded = @gzuncompress($candidate);
            if ($decoded !== false) {
                $candidates[] = $decoded;
            }
            $decoded = @gzinflate($candidate);
            if ($decoded !== false) {
                $candidates[] = $decoded;
            }
            if (function_exists('zlib_decode')) {
                $decoded = @zlib_decode($candidate);
                if ($decoded !== false) {
                    $candidates[] = $decoded;
                }
            }
        }
        usort($candidates, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $candidates[0];
    }

    private static function textOperators(string $stream, array $unicodeMaps, array $namedMaps = []): string
    {
        $output = [];
        $candidates = [];
        if (preg_match_all('/\/([A-Za-z][A-Za-z0-9]*)\s+[-0-9.]+\s+Tf\b/', $stream, $fontNames)) {
            foreach (array_unique($fontNames[1]) as $fontName) {
                foreach ($namedMaps[$fontName] ?? [] as $map) {
                    $candidates[] = $map;
                }
            }
        }
        $unicodeMaps = self::rankMapsForStream($stream, $candidates ?: $unicodeMaps);
        if (preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]+>/', $block, $tokens)) {
                    $line = [];
                    foreach ($tokens[0] as $token) {
                        $decoded = self::decodeToken($token, $unicodeMaps);
                        if ($decoded !== '') {
                            $line[] = $decoded;
                        }
                    }
                    if ($line) {
                        $output[] = implode(' ', $line);
                    }
                }
            }
        }
        return implode("\n", $output);
    }

    private static function rankMapsForStream(string $stream, array $maps): array
    {
        if (count($maps) <= 4 || !preg_match_all('/<[0-9A-Fa-f\s]+>/', $stream, $matches)) {
            return $maps;
        }
        $tokens = array_slice($matches[0], 0, 80);
        $scores = [];
        foreach ($maps as $index => $map) {
            $score = 0;
            foreach ($tokens as $token) {
                $hex = strtoupper((string)preg_replace('/\s+/', '', substr($token, 1, -1)));
                if ($hex === '') {
                    continue;
                }
                $mapped = self::mapGlyphs($hex, $map);
                if ($mapped !== '' && preg_match_all('/[\p{L}\p{N}]/u', $mapped, $letters)) {
                    $score += count($letters[0]);
                }
            }
            if ($score > 0) {
                $scores[$index] = $score;
            }
        }
        if (!$scores) {
            return $maps;
        }
        arsort($scores);
        $ranked = [];
        foreach (array_slice(array_keys($scores), 0, 4) as $index) {
            $ranked[] = $maps[$index];
        }
        return $ranked;
    }

    private static function decodeToken(string $token, array $unicodeMaps = []): string
    {
        if ($token[0] === '(') {
            $value = substr($token, 1, -1);
            $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn(array $m): string => chr(octdec($m[1])), $value);
            return strtr($value, [
                '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
                '\\b' => "\b", '\\f' => "\f", '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
            ]);
        }

        $hex = preg_replace('/\s+/', '', substr($token, 1, -1));
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $binary = @hex2bin($hex);
        if ($binary === false) {
            return '';
        }
        if (str_starts_with($binary, "\xFE\xFF")) {
            return mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
        }
        $nonLetterFallback = '';
        foreach ($unicodeMaps as $map) {
            $mapped = self::mapGlyphs(strtoupper($hex), $map);
            if ($mapped !== '') {
                // Subset fonts frequently reuse glyph codes. The first map may
                // technically cover a token but translate it only to spaces or
                // control glyphs. Prefer the first complete mapping that yields
                // actual letters/numbers, and retain punctuation as fallback.
                if (preg_match('/[\p{L}\p{N}]/u', $mapped)) {
                    return $mapped;
                }
                if ($nonLetterFallback === '' && trim($mapped) !== '') {
                    $nonLetterFallback = $mapped;
                }
            }
        }
        $partiallyMapped = self::bestEffortGlyphs(strtoupper($hex), $unicodeMaps);
        if ($partiallyMapped !== '') {
            return $partiallyMapped;
        }
        if ($nonLetterFallback !== '') {
            return $nonLetterFallback;
        }
        return mb_check_encoding($binary, 'UTF-8') ? $binary : mb_convert_encoding($binary, 'UTF-8', 'Windows-1252');
    }

    private static function unicodeMaps(array $streams): array
    {
        $maps = [];
        foreach ($streams as $decoded) {
            if (!str_contains($decoded, 'beginbfchar') && !str_contains($decoded, 'beginbfrange')) {
                continue;
            }
            $map = [];
            if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $decoded, $sections)) {
                foreach ($sections[1] as $section) {
                    if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $section, $pairs, PREG_SET_ORDER)) {
                        continue;
                    }
                    foreach ($pairs as $pair) {
                        $map[strtoupper($pair[1])] = self::utf16Hex($pair[2]);
                    }
                }
            }
            if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $decoded, $sections)) {
                foreach ($sections[1] as $section) {
                    if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $section, $ranges, PREG_SET_ORDER)) {
                        continue;
                    }
                    foreach ($ranges as $range) {
                        $start = hexdec($range[1]);
                        $end = hexdec($range[2]);
                        $target = hexdec($range[3]);
                        $width = strlen($range[1]);
                        $targetWidth = strlen($range[3]);
                        if ($end - $start > 512) {
                            continue;
                        }
                        for ($code = $start; $code <= $end; $code++, $target++) {
                            $sourceHex = strtoupper(str_pad(dechex($code), $width, '0', STR_PAD_LEFT));
                            $targetHex = str_pad(dechex($target), $targetWidth, '0', STR_PAD_LEFT);
                            $map[$sourceHex] = self::utf16Hex($targetHex);
                        }
                    }
                }
            }
            if ($map) {
                $maps[] = $map;
            }
        }
        if (!$maps) {
            return [];
        }
        // Keep the real per-font maps. Subset fonts often reuse the same glyph
        // codes for different letters, so merging them into one consensus map
        // can turn a valid selectable-text PDF into empty or unusable text.
        // Streams are still decoded only once, which removes the original
        // shared-hosting timeout without sacrificing font accuracy.
        return $maps;
    }

    private static function mapGlyphs(string $hex, array $map): string
    {
        foreach ([4, 2] as $width) {
            if (strlen($hex) % $width !== 0) {
                continue;
            }
            $output = '';
            for ($offset = 0; $offset < strlen($hex); $offset += $width) {
                $code = substr($hex, $offset, $width);
                if (!isset($map[$code])) {
                    $output = '';
                    break;
                }
                $output .= $map[$code];
            }
            if ($output !== '') {
                return $output;
            }
        }
        return '';
    }

    private static function bestEffortGlyphs(string $hex, array $maps): string
    {
        $bestText = '';
        $bestCoverage = 0.0;
        foreach ($maps as $map) {
            foreach ([4, 2] as $width) {
                if (strlen($hex) % $width !== 0) {
                    continue;
                }
                $total = intdiv(strlen($hex), $width);
                $matched = 0;
                $output = '';
                for ($offset = 0; $offset < strlen($hex); $offset += $width) {
                    $code = substr($hex, $offset, $width);
                    if (isset($map[$code])) {
                        $output .= $map[$code];
                        $matched++;
                    }
                }
                $coverage = $total > 0 ? $matched / $total : 0.0;
                if ($coverage > $bestCoverage) {
                    $bestCoverage = $coverage;
                    $bestText = $output;
                }
            }
        }
        // Do not turn random binary/image data into lesson text. At least half
        // of a token must be backed by one real ToUnicode font map.
        return $bestCoverage >= 0.5 ? $bestText : '';
    }

    private static function utf16Hex(string $hex): string
    {
        $binary = @hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
        if ($binary === false) {
            return '';
        }
        return mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
    }

    private static function clean(string $text): string
    {
        $text = str_replace(["\0", "\r"], ['', "\n"], $text);
        // A single malformed byte from a PDF literal string must not make a
        // Unicode preg_replace return null and erase all otherwise valid text.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = function_exists('mb_scrub')
                ? mb_scrub($text, 'UTF-8')
                : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        // Some editable school PDFs contain the same visible glyph several
        // times in overlapping font layers (for example CCCOOONNNTTTEEENNNTTTSSS).
        // Collapse only runs of 3+ identical characters; normal double letters
        // such as "book" and "school" remain untouched.
        $text = preg_replace('/([^\s])\1{2,}/u', '$1', $text);
        $text = str_replace("\u{FFFD}", '', (string)$text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n[ \t]+/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim((string)$text);
    }
}
