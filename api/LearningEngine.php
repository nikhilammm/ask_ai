<?php
declare(strict_types=1);

final class LearningEngine
{
    private const STOP = [
        'about','after','again','also','another','because','before','being','between',
        'could','does','each','following','from','have','into','like','most','other',
        'should','their','there','these','they','this','those','through','very','were',
        'that','only','many','make','made','using','used','ensure',
        'what','when','where','which','while','with','would','your','explain','describe','tell',
        'who','whom','whose','did','chapter','lesson',
        'why','how','must','say','said','me','my','we','our','ours','us','he','him','his','she','her','hers','it','its',
        'is','are','was','of','to','in','on','for','as','at','by','and','or','not','has','had','can','will','do',
        'meaning','means','matlab','arth','define','definition','word',
        'give','short','long','summary','summarise','summarize','note','notes','important',
        'point','points','main','idea','key','easy','simple','answer','book',
    ];

    public static function sentences(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $prose = preg_replace('/\s+/', ' ', trim($content));
        $parts = preg_split('/(?<=[.!?।])\s+/u', (string)$prose) ?: [];
        // Poems and early-reader PDFs often retain line breaks but lose full
        // stops. Build extra sentence-like chunks from those visual lines.
        $lineChunks = [];
        $buffer = '';
        foreach (preg_split('/\n+/u', $content) ?: [] as $line) {
            $line = trim((string)preg_replace('/\s+/u', ' ', $line));
            if ($line === '' || preg_match('/^(?:Page\s+)?\d{1,3}$/iu', $line)) {
                continue;
            }
            $buffer = trim($buffer . ' ' . $line);
            $wordCount = count(preg_split('/\s+/u', $buffer) ?: []);
            if (preg_match('/[.!?।”"\']$/u', $line) || $wordCount >= 16) {
                if (mb_strlen($buffer) >= 25 && $wordCount >= 5) {
                    $lineChunks[] = preg_match('/[.!?।”"\']$/u', $buffer) ? $buffer : $buffer . '.';
                }
                $buffer = '';
            }
        }
        if ($buffer !== '' && mb_strlen($buffer) >= 25 && count(preg_split('/\s+/u', $buffer) ?: []) >= 5) {
            $lineChunks[] = $buffer . '.';
        }
        // Prefer properly punctuated prose. Line chunks are fallbacks for
        // poems and other unpunctuated textbook layouts.
        $parts = array_merge($parts, $lineChunks);
        $result = [];
        foreach ($parts as $sentence) {
            $sentence = trim($sentence);
            $sentence = trim((string)preg_replace('/\b\d{1,3}\s+English\s+Reader\s*-?\s*\d*\b/iu', '', $sentence));
            $sentence = trim((string)preg_replace('/^Starter\s+/iu', '', $sentence));
            $sentence = trim((string)preg_replace('/^(?:D\s+)+(?=[A-Z])/u', '', $sentence));
            // Some extracted textbook exercises run directly into the next
            // prose statement ("a) ... d) ... D Statement"). Keep the prose
            // and discard the preceding MCQ fragment.
            if (preg_match('/\b[a-d]\).*\sD\s+(?=[A-Z])/i', $sentence)) {
                $sentence = trim((string)preg_replace('/^.*\sD\s+(?=[A-Z])/', '', $sentence));
            }
            if (mb_strlen($sentence) < 25 || !preg_match('/[.!?।”"\']$/u', $sentence)) {
                continue;
            }
            if (count(preg_split('/\s+/u', $sentence) ?: []) < 5 || self::isNoiseSentence($sentence)) {
                continue;
            }
            $key = mb_strtolower($sentence);
            $result[$key] = $sentence;
        }
        return array_values($result);
    }

    public static function answer(string $content, string $query, string $title): string
    {
        $originalLength = strlen($content);
        $content = self::focusContentForQuery($content, $query);
        $chapterFocused = strlen($content) < $originalLength;
        $meaning = (bool)preg_match('/meaning|means?|matlab|arth|define|definition/iu', $query);
        // Accept common summary requests even when users type "keypoints"
        // without a space (or use a hyphen), as well as similar phrasing.
        $summary = (bool)preg_match('/summary|summarise|summarize|notes?|important[\s-]*points?|main[\s-]*points?|key[\s-]*points?|highlights?|simple|easy|asaan|bhasha|samjhao|main idea/iu', $query);
        if ($meaning) {
            $definition = self::definitionFromContent($content, $query);
            if ($definition !== null) {
                return $definition;
            }
        }
        // Textbooks normally place exercises immediately after the lesson.
        // Summaries must describe the lesson itself, not those questions or
        // classroom activities.
        if ($summary || $chapterFocused) {
            $content = self::coreLessonContent($content);
        }
        $sentences = self::sentences($content);
        $sentences = array_values(array_filter(
            $sentences,
            static fn(string $sentence): bool =>
                !str_contains($sentence, '?') &&
                !self::isNoiseSentence($sentence) &&
                (!$summary || !preg_match('/\b(?:read\s+and\s+enjoy|read\s+the\s+(?:poem|story)|teacher\s+will|learning\s+objective|starter)\b/i', $sentence))
        ));
        if (!$sentences) {
            return 'The selected lesson does not contain enough complete text to prepare a reliable answer.';
        }
        $terms = self::tokens($query);
        $longAnswer = (bool)preg_match('/long answer|detail(?:ed)?|in detail|vistaar|explain fully/iu', $query);
        $shortAnswer = (bool)preg_match('/short answer|brief|one line|do line|sankshep/iu', $query);
        $scored = [];
        foreach ($sentences as $index => $sentence) {
            if (!$summary && str_contains($sentence, '?')) {
                continue;
            }
            $words = self::tokens($sentence);
            $overlap = count(array_intersect($terms, $words));
            $coverage = $terms ? $overlap / count($terms) : 0.0;
            // One meaningful matching word is enough to retrieve a grounded
            // sentence. The previous two-word/50% rule rejected most natural
            // questions after common question words were removed as stopwords.
            $minimumOverlap = 1;
            $reasonMatch = preg_match('/why|how|kyun|kaise/iu', $query) && $overlap >= 1;
            if ($summary || (!$summary && $overlap >= $minimumOverlap && ($meaning || $coverage >= 0.2 || $reasonMatch))) {
                $reasonBonus = preg_match('/why|how|kyun|kaise/iu', $query)
                    && preg_match('/because|therefore|so that|due to|helps?|allows?|enables?|is used|can /iu', $sentence) ? 2 : 0;
                $definitionBonus = $meaning
                    && preg_match('/\b(?:is|are|means?|refers? to|called|known as)\b/iu', $sentence) ? 3 : 0;
                $scored[] = [
                    'score' => $overlap * 3 + $reasonBonus + $definitionBonus + ($summary ? 1 : 0),
                    'index' => $index,
                    'text' => $sentence,
                ];
            }
        }
        // Never answer from general knowledge. A normal question must match
        // meaningful words in the currently selected lesson.
        $isReasonQuestion = (bool)preg_match('/why|how|kyun|kaise/iu', $query);
        if (!$summary && !$scored && $isReasonQuestion && $chapterFocused) {
            $scored = array_map(
                static fn(string $text, int $index): array => ['score' => 1, 'index' => $index, 'text' => $text],
                array_slice($sentences, 0, 2),
                array_keys(array_slice($sentences, 0, 2))
            );
        }
        if (!$summary && !$scored) {
            return 'Is chapter mein is question ki information available nahi hai.';
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index']);
        if ($isReasonQuestion && preg_match('/why|kyun/iu', $query) && $scored) {
            $best = $scored[0];
            $bestText = trim((string)$best['text']);
            if (!preg_match('/\b(?:because|therefore|due to|so that|since)\b/iu', $bestText)) {
                $context = '';
                for ($i = (int)$best['index'] - 1; $i >= 0; $i--) {
                    if (preg_match('/\b(?:never|without|because|therefore|so that|if)\b/iu', $sentences[$i])) {
                        $context = trim($sentences[$i]);
                        break;
                    }
                }
                if ($context !== '') {
                    return 'The chapter explains this because ' . lcfirst(rtrim($context, ". \t\n\r\0\x0B")) . '. ' . $bestText;
                }
            }
        }
        $selected = array_slice(array_column($scored ?: array_map(
            static fn(string $text, int $index): array => ['score' => 1, 'index' => $index, 'text' => $text],
            $sentences,
            array_keys($sentences)
        ), 'text'), 0, $summary || $longAnswer ? 5 : ($shortAnswer || $isReasonQuestion ? 1 : 2));
        if ($summary) {
            $points = implode("\n", array_map(
                static fn(string $sentence, int $index): string => ($index + 1) . '. ' . $sentence,
                array_slice($selected, 1),
                array_keys(array_slice($selected, 1))
            ));
            return "Easy explanation of {$title}:\n\nMain idea: {$selected[0]}\n\nImportant points:\n{$points}\n\nThis explanation uses only the selected source content.";
        }
        if ($meaning) {
            $term = $terms ? end($terms) : '';
            return $term !== ''
                ? "Meaning from the selected book: “{$term}” is used in this context: {$selected[0]}"
                : "The selected book does not provide a clear meaning for that word.";
        }
        $limit = preg_match('/how many|types?|kinds?|list|name|kitne|kitna|prakar/i', $query)
            ? 5
            : (($isReasonQuestion || preg_match('/^\s*(?:who|what|where|when|which)\b/iu', $query)) ? 1 : 2);
        $direct = [];
        $seen = [];
        foreach ($selected as $sentence) {
            $clean = trim((string)preg_replace('/^(?:D\s+)+/u', '', $sentence));
            $key = mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $clean));
            if ($clean === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $direct[] = $clean;
            if (count($direct) >= $limit) {
                break;
            }
        }
        return implode(' ', $direct);
        $points = implode("\n", array_map(
            static fn(string $sentence, int $i): string => ($i + 1) . '. ' . $sentence,
            $selected,
            array_keys($selected)
        ));
        return "Easy explanation: “{$title}”\n\nKey points:\n{$points}\n\nIn simple words:\n{$selected[0]}\n\nSource: selected lesson only.";
    }

    public static function generate(string $content, string $type, int $count): array
    {
        $sentences = self::lessonSentences($content);
        $sentences = array_values(array_filter(
            $sentences,
            static fn(string $sentence): bool =>
                !str_contains($sentence, '?') &&
                !preg_match('/^(?:RAPID\s*TASK|ACTIVITY|EXERCISE|QUESTIONS?)\b/i', trim($sentence))
        ));
        if (!$sentences) {
            throw new RuntimeException('This lesson does not contain enough readable content for a test.');
        }
        $type = strtolower(str_replace(['-', ' '], '_', trim($type ?: 'mcq')));
        $count = max(1, min(50, $count));
        // A fresh order on every request prevents quizzes and test papers from
        // repeatedly returning the first statements from a chapter.
        shuffle($sentences);
        if (in_array($type, ['mixed', 'all', 'all_types'], true)) {
            $types = ['mcq', 'fill_in_the_blank', 'true_false', 'short_answer'];
            $all = [];
            for ($i = 0; $i < $count; $i++) {
                $generated = self::generate($content, $types[$i % 4], 1);
                if ($generated) {
                    $all[] = $generated[0];
                }
            }
            return $all;
        }

        $terms = self::terms($sentences);
        shuffle($terms);
        $questions = [];
        foreach ($sentences as $sentenceIndex => $sentence) {
            preg_match_all('/[\p{L}][\p{L}\p{M}\'-]{3,}/u', $sentence, $matches, PREG_OFFSET_CAPTURE);
            shuffle($matches[0]);
            foreach ($matches[0] as [$answer, $offset]) {
                if (in_array(mb_strtolower($answer), self::STOP, true)) {
                    continue;
                }
                $blank = substr_replace($sentence, '_____', $offset, strlen($answer));
                if (in_array($type, ['fill_in_the_blank', 'fill_in_the_blanks', 'fill'], true)) {
                    $questions[] = self::question('fill_in_the_blank', $blank, null, $answer);
                } elseif (in_array($type, ['true_false', 'true/false', 'truefalse'], true)) {
                    $distractors = array_values(array_filter(
                        $terms,
                        static fn(string $term): bool => mb_strtolower($term) !== mb_strtolower($answer)
                    ));
                    $makeFalse = $distractors && random_int(0, 1) === 1;
                    $statement = $makeFalse
                        ? substr_replace($sentence, $distractors[array_rand($distractors)], $offset, strlen($answer))
                        : $sentence;
                    $questions[] = self::question(
                        'true_false',
                        $statement,
                        ['True', 'False'],
                        $makeFalse ? 'False' : 'True'
                    );
                } elseif (in_array($type, ['short_answer', 'question_answers'], true)) {
                    $questions[] = self::question(
                        'short_answer',
                        "From the selected lesson, write the complete sentence that contains the term “{$answer}”.",
                        null,
                        $sentence
                    );
                } else {
                    $distractors = array_values(array_filter($terms, static fn(string $term): bool => mb_strtolower($term) !== mb_strtolower($answer)));
                    if (count($distractors) < 3) {
                        continue;
                    }
                    $offsetTerms = $sentenceIndex % count($distractors);
                    $distractors = array_merge(array_slice($distractors, $offsetTerms), array_slice($distractors, 0, $offsetTerms));
                    $options = array_slice(array_values(array_unique(array_merge([$answer], $distractors))), 0, 4);
                    if (count($options) < 4) {
                        continue;
                    }
                    shuffle($options);
                    $questions[] = self::question('mcq', $blank, $options, $answer);
                }
                if (count($questions) >= $count) {
                    return $questions;
                }
                // Use a different source statement for the next question so a
                // paper contains varied questions, not several blanks from one line.
                break;
            }
        }
        if (!$questions) {
            throw new RuntimeException('The lesson does not contain enough complete statements for this test type.');
        }
        return array_slice($questions, 0, $count);
    }

    public static function lessonSentences(string $content): array
    {
        return self::sentences(self::coreLessonContent($content));
    }

    private static function question(string $type, string $text, ?array $options, string $answer): array
    {
        return [
            'question_type' => $type,
            'question_text' => $text,
            'options' => $options,
            'correct_answer' => $answer,
        ];
    }

    private static function focusContentForQuery(string $content, string $query): string
    {
        preg_match_all('/^Chapter:\s*(.+)$/mi', $content, $matches, PREG_OFFSET_CAPTURE);
        $headings = $matches[0] ?? [];
        foreach ($headings as $index => [$headingLine, $offset]) {
            $chapterTitle = trim((string)($matches[1][$index][0] ?? ''));
            // Ignore numbering such as "1" in "1 I Have Fun". Users naturally
            // ask by the words in the chapter title and omit its sequence number.
            $titleTerms = array_values(array_filter(
                self::tokens($chapterTitle),
                static fn(string $term): bool => (bool)preg_match('/\p{L}/u', $term)
            ));
            $queryTerms = self::tokens($query);
            if (!$titleTerms || count(array_intersect($titleTerms, $queryTerms)) < count($titleTerms)) {
                continue;
            }
            $end = $headings[$index + 1][1] ?? strlen($content);
            return trim(substr($content, (int)$offset, (int)$end - (int)$offset));
        }
        // If the user does not mention the chapter title, locate the chapter
        // containing the strongest combination of meaningful query terms.
        // Requiring at least two terms prevents unrelated questions from
        // selecting a chapter based on a common word.
        $queryTerms = self::tokens($query);
        $bestScore = 0;
        $bestChapter = null;
        foreach ($headings as $index => [$headingLine, $offset]) {
            $end = $headings[$index + 1][1] ?? strlen($content);
            $chapter = trim(substr($content, (int)$offset, (int)$end - (int)$offset));
            $score = count(array_intersect($queryTerms, self::tokens($chapter)));
            if ($score >= 2 && $score > $bestScore) {
                $bestScore = $score;
                $bestChapter = $chapter;
            }
        }
        if ($bestChapter !== null) {
            return $bestChapter;
        }
        return $content;
    }

    private static function coreLessonContent(string $content): string
    {
        $sections = preg_split('/(?=^Chapter:\s*)/mi', $content) ?: [$content];
        $clean = [];
        foreach ($sections as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }
            $parts = preg_split(
                '/(?:\R|\s)+(?:New\s+Words|Word\s+Meanings?|Comprehension|Words\s+Wonder|Spellings?|Communication\s+Skills?|Reading\s+is\s+Fun|Word\s+Building|Grammar\s+Corner|Let.?s\s+Write|Fun\s+.{0,12}Learn|Exercises?)\b/iu',
                $section,
                2
            );
            $lesson = trim((string)($parts[0] ?? ''));
            // Remove a textbook's instructional lead-in while preserving the
            // poem/story that follows it. This is common in primary readers:
            // "Read and enjoy the poem ..." followed by unpunctuated verse.
            $lesson = trim((string)preg_replace(
                '/^.*?\bread\s+and\s+enjoy(?:\s+the)?\s+(?:poem|story)[^.।!?]*[.।!]\s*/isu',
                '',
                $lesson,
                1
            ));
            $clean[] = mb_strlen($lesson) >= 80 ? $lesson : $section;
        }
        return trim(implode("\n\n", $clean));
    }

    private static function definitionFromContent(string $content, string $query): ?string
    {
        if (!preg_match('/(?:meaning\s+of|meaning|define|definition\s+of|matlab|arth)\s+["“”\']?([\p{L}\p{M}-]{2,})/iu', $query, $match)) {
            return null;
        }
        $term = trim((string)$match[1]);
        $pattern = '/\b' . preg_quote($term, '/') . '\s*:\s*(.+?)(?=\s+[\p{L}\p{M}-]{2,}\s*:|\R|$)/iu';
        if (!preg_match($pattern, $content, $definition)) {
            return null;
        }
        $meaning = trim((string)$definition[1], " \t\n\r\0\x0B.;,");
        if ($meaning === '') {
            return null;
        }
        return "Meaning from the selected book: {$term} means {$meaning}.";
    }

    private static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);
        $normal = array_map(static function (string $word): string {
            return match ($word) {
                'met' => 'meet',
                'went', 'gone' => 'go',
                'children' => 'child',
                default => $word,
            };
        }, $matches[0]);
        return array_values(array_unique(array_filter($normal, static fn(string $word): bool => !in_array($word, self::STOP, true))));
    }

    private static function isNoiseSentence(string $sentence): bool
    {
        $sentence = trim($sentence);
        return str_contains($sentence, '_____')
            || str_contains($sentence, '?')
            || (bool)preg_match('/^(?:Chapter\s*:|New\s+Words|Word\s+Meanings?|Comprehension|Words\s+Wonder|Spellings?|Communication\s+Skills?|Write-a-While|Listen-a-While|Activity|Exercise|Questions?|Rapid\s*Task)\b/iu', $sentence)
            || (bool)preg_match('/\b(?:read\s+and\s+enjoy|fill\s+in|match\s+the|choose\s+the|tick\s*(?:\([^)]*\)\s*the|the)|true\s+or\s+false|write\s+[‘\'"T]|listen\s+carefully|fold\s+the|look\s+at\s+the|complete\s+the|circle\s+the)\b/iu', $sentence);
    }

    private static function terms(array $sentences): array
    {
        $terms = [];
        foreach ($sentences as $sentence) {
            foreach (self::tokens($sentence) as $term) {
                if (mb_strlen($term) >= 4) {
                    $terms[$term] = $term;
                }
            }
        }
        return array_values($terms);
    }
}
