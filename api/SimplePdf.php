<?php
declare(strict_types=1);

final class SimplePdf
{
    public static function exam(array $quiz, array $payload, bool $includeAnswers): string
    {
        $marksByType = is_array($payload['marks_by_type'] ?? null) ? $payload['marks_by_type'] : [];
        $questions = is_array($quiz['questions'] ?? null) ? $quiz['questions'] : [];
        $totalMarks = 0;
        foreach ($questions as $question) {
            $totalMarks += self::marksFor((string)($question['question_type'] ?? ''), $marksByType);
        }

        $items = [
            self::item(strtoupper((string)($payload['institution_name'] ?? 'EduAI School')), 16, true, 'center', 7),
            self::item((string)($payload['exam_title'] ?? 'Lesson Assessment'), 13, true, 'center', 11),
            [
                'type' => 'metaTable',
                'class' => (string)($payload['class_name'] ?? ''),
                'subject' => (string)($payload['subject'] ?? ''),
                'time' => (string)($payload['duration_minutes'] ?? 60) . ' Minutes',
                'marks' => (string)$totalMarks,
            ],
            ['type' => 'candidate'],
            [
                'type' => 'instructionBox',
                'text' => 'Attempt all questions. Read each question carefully. Marks are shown against every question.',
            ],
        ];

        $groups = [];
        foreach ($questions as $index => $question) {
            $type = self::normalType((string)($question['question_type'] ?? ''));
            $groups[$type][] = [$index, $question];
        }
        $sectionIndex = 0;
        foreach ($groups as $type => $group) {
            $items[] = [
                'type' => 'sectionBar',
                'text' => 'Section ' . chr(65 + $sectionIndex++) . ' - ' . self::sectionLabel($type),
            ];
            foreach ($group as [$index, $question]) {
                $marks = self::marksFor($type, $marksByType);
                $text = trim((string)($question['question_text'] ?? $question['question'] ?? ''));
                $items[] = [
                    'type' => 'question',
                    'text' => 'Q' . ($index + 1) . '. ' . $text,
                    'marks' => $marks,
                ];
                $options = array_values((array)($question['options'] ?? []));
                for ($optionIndex = 0; $optionIndex < count($options); $optionIndex += 2) {
                    $left = chr(65 + $optionIndex) . '. ' . (string)$options[$optionIndex];
                    $right = isset($options[$optionIndex + 1])
                        ? chr(66 + $optionIndex) . '. ' . (string)$options[$optionIndex + 1]
                        : '';
                    $items[] = ['type' => 'optionRow', 'left' => $left, 'right' => $right];
                }
                if ($type === 'fill_in_the_blank') {
                    $items[] = self::item('Answer: ______________________________', 10, false, 'left', 8, 18);
                } elseif ($type === 'short_answer') {
                    $items[] = self::item('________________________________________________________________________', 10, false, 'left', 4, 0);
                    $items[] = self::item('________________________________________________________________________', 10, false, 'left', 8, 0);
                } else {
                    $items[] = self::item('', 8, false, 'left', 4);
                }
            }
        }

        if ($includeAnswers) {
            $items[] = ['type' => 'pageBreak'];
            $items[] = self::item('ANSWER KEY', 15, true, 'center', 12);
            foreach ($questions as $index => $question) {
                $answer = trim((string)($question['correct_answer'] ?? ''));
                $items[] = self::item(($index + 1) . '. ' . ($answer !== '' ? $answer : 'Not available'), 10, false, 'left', 5);
            }
        }

        return self::build($items);
    }

    private static function item(
        string $text,
        int $size = 10,
        bool $bold = false,
        string $align = 'left',
        int $spaceAfter = 3,
        int $indent = 0,
        bool $keepWithNext = false
    ): array {
        return compact('text', 'size', 'bold', 'align', 'spaceAfter', 'indent', 'keepWithNext') + ['type' => 'text'];
    }

    private static function build(array $items): string
    {
        $pages = [];
        $commands = [];
        $y = 798.0;
        $pageNumber = 1;
        $startPage = static function () use (&$commands, &$y): void {
            $commands = [];
            $y = 798.0;
        };
        $finishPage = static function () use (&$pages, &$commands, &$pageNumber): void {
            $footer = "BT /F1 8 Tf 0.35 g 280 24 Td (Page {$pageNumber}) Tj ET";
            $pages[] = implode("\n", $commands) . "\n" . $footer;
            $pageNumber++;
        };

        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'pageBreak') {
                if ($commands) {
                    $finishPage();
                }
                $startPage();
                continue;
            }
            if (($item['type'] ?? '') === 'rule') {
                if ($y < 55) {
                    $finishPage();
                    $startPage();
                }
                $commands[] = sprintf('0.55 w 48 %.2F m 547 %.2F l S', $y, $y);
                $y -= (float)($item['spaceAfter'] ?? 7);
                continue;
            }
            if (($item['type'] ?? '') === 'metaTable') {
                if ($y < 110) {
                    $finishPage();
                    $startPage();
                }
                $top = $y;
                $row = 25;
                $left = 48;
                $middle = 297.5;
                $right = 547;
                $bottom = $top - ($row * 2);
                $commands[] = sprintf('0.55 w %.2F %.2F %.2F %.2F re S', $left, $bottom, $right - $left, $top - $bottom);
                $commands[] = sprintf('%.2F %.2F m %.2F %.2F l S', $middle, $bottom, $middle, $top);
                $commands[] = sprintf('%.2F %.2F m %.2F %.2F l S', $left, $top - $row, $right, $top - $row);
                $commands[] = self::textCommand('F2', 9, $left + 10, $top - 16, 'Class:');
                $commands[] = self::textCommand('F1', 9, $left + 43, $top - 16, self::ascii((string)$item['class']));
                $commands[] = self::textCommand('F2', 9, $middle + 10, $top - 16, 'Subject:');
                $commands[] = self::textCommand('F1', 9, $middle + 52, $top - 16, self::ascii((string)$item['subject']));
                $commands[] = self::textCommand('F2', 9, $left + 10, $top - $row - 16, 'Time:');
                $commands[] = self::textCommand('F1', 9, $left + 38, $top - $row - 16, self::ascii((string)$item['time']));
                $commands[] = self::textCommand('F2', 9, $middle + 10, $top - $row - 16, 'Maximum Marks:');
                $commands[] = self::textCommand('F1', 9, $middle + 92, $top - $row - 16, self::ascii((string)$item['marks']));
                // Keep the student identity row clearly separated from the table.
                $y = $bottom - 26;
                continue;
            }
            if (($item['type'] ?? '') === 'candidate') {
                if ($y < 65) {
                    $finishPage();
                    $startPage();
                }
                $commands[] = self::textCommand('F1', 10, 48, $y, 'Name: __________________________________________');
                $commands[] = self::textCommand('F1', 10, 385, $y, 'Roll No.: __________________');
                // Leave a comfortable handwritten area before the divider.
                $y -= 34;
                $commands[] = sprintf('0.35 w 48 %.2F m 547 %.2F l S', $y, $y);
                $y -= 14;
                continue;
            }
            if (($item['type'] ?? '') === 'instructionBox') {
                if ($y < 65) {
                    $finishPage();
                    $startPage();
                }
                $height = 48;
                $commands[] = sprintf('0.95 g 48 %.2F 499 %.2F re f', $y - $height, $height);
                $commands[] = sprintf('0.25 0.31 0.39 rg 48 %.2F 4 %.2F re f', $y - $height, $height);
                $commands[] = self::textCommand('F2', 9, 64, $y - 17, 'General Instructions');
                $commands[] = self::textCommand('F1', 8, 64, $y - 33, self::ascii((string)$item['text']));
                $y -= $height + 15;
                continue;
            }
            if (($item['type'] ?? '') === 'sectionBar') {
                if ($y < 70) {
                    $finishPage();
                    $startPage();
                }
                $commands[] = sprintf('0.20 0.24 0.30 rg 48 %.2F 499 25 re f', $y - 19);
                $commands[] = sprintf('BT /F2 10 Tf 1 g 58 %.2F Td (%s) Tj ET', $y - 12, self::pdfText(self::ascii((string)$item['text'])));
                $y -= 36;
                continue;
            }
            if (($item['type'] ?? '') === 'optionRow') {
                if ($y < 55) {
                    $finishPage();
                    $startPage();
                }
                $commands[] = self::textCommand('F1', 9, 76, $y, self::ascii((string)$item['left']));
                if ((string)$item['right'] !== '') {
                    $commands[] = self::textCommand('F1', 9, 318, $y, self::ascii((string)$item['right']));
                }
                $y -= 17;
                continue;
            }
            if (($item['type'] ?? '') === 'question') {
                $lines = self::wrap((string)($item['text'] ?? ''), 76);
                $required = count($lines) * 15 + 7;
                if ($y - $required < 45 && $commands) {
                    $finishPage();
                    $startPage();
                }
                foreach ($lines as $lineIndex => $line) {
                    $commands[] = self::textCommand('F2', 10, 48, $y, self::ascii($line));
                    if ($lineIndex === 0) {
                        $commands[] = self::textCommand('F2', 10, 521, $y, '[' . (int)($item['marks'] ?? 1) . ']');
                    }
                    $y -= 15;
                }
                $y -= 7;
                continue;
            }

            $size = max(8, min(18, (int)($item['size'] ?? 10)));
            $indent = max(0, (int)($item['indent'] ?? 0));
            $usableWidth = 499 - $indent;
            $maxChars = max(25, (int)floor($usableWidth / ($size * 0.52)));
            $lines = self::wrap((string)($item['text'] ?? ''), $maxChars);
            $lineHeight = $size + 4;
            $required = count($lines) * $lineHeight + (int)($item['spaceAfter'] ?? 3);
            if (!empty($item['keepWithNext'])) {
                $required += 18;
            }
            if ($y - $required < 45 && $commands) {
                $finishPage();
                $startPage();
            }
            foreach ($lines as $line) {
                $safe = self::pdfText(self::ascii($line));
                $font = !empty($item['bold']) ? 'F2' : 'F1';
                $x = 48 + $indent;
                if (($item['align'] ?? 'left') === 'center') {
                    $estimatedWidth = strlen($line) * $size * 0.52;
                    $x = max(48, (595 - $estimatedWidth) / 2);
                }
                $commands[] = sprintf('BT /%s %d Tf 0 g %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $y, $safe);
                $y -= $lineHeight;
            }
            $y -= (int)($item['spaceAfter'] ?? 3);
        }
        if ($commands || !$pages) {
            $finishPage();
        }

        return self::assemble($pages);
    }

    private static function assemble(array $streams): string
    {
        $objects = [];
        $pageIds = [];
        $nextId = 5;
        foreach ($streams as $stream) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = "<< /Length " . strlen($stream) . ">>\nstream\n{$stream}\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => "{$id} 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private static function wrap(string $text, int $maxChars): array
    {
        if ($text === '') {
            return [''];
        }
        $wrapped = wordwrap(preg_replace('/\s+/', ' ', trim($text)) ?? trim($text), $maxChars, "\n", true);
        return explode("\n", $wrapped);
    }

    private static function normalType(string $type): string
    {
        $type = strtolower(trim($type));
        if (str_contains($type, 'fill')) {
            return 'fill_in_the_blank';
        }
        if (str_contains($type, 'true')) {
            return 'true_false';
        }
        if (str_contains($type, 'short') || str_contains($type, 'question answer')) {
            return 'short_answer';
        }
        return 'mcq';
    }

    private static function sectionLabel(string $type): string
    {
        return match ($type) {
            'fill_in_the_blank' => 'Fill in the Blanks',
            'true_false' => 'True or False',
            'short_answer' => 'Short Answer Questions',
            default => 'Multiple Choice Questions',
        };
    }

    private static function marksFor(string $type, array $marksByType): int
    {
        $normal = self::normalType($type);
        return max(1, (int)($marksByType[$normal] ?? 1));
    }

    private static function pdfText(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private static function textCommand(string $font, int $size, float $x, float $y, string $text): string
    {
        return sprintf('BT /%s %d Tf 0 g %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $y, self::pdfText($text));
    }

    private static function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted === false ? (string)preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }
}
