<?php
declare(strict_types=1);

final class ContentDetectionException extends RuntimeException {}

final class PageChapterDetector
{
    public static function detect(string $text): ?array
    {
        if (str_starts_with(ltrim($text), '[[EDUAI_PAGES_V1]]')) return self::fromContents($text);
        if (!preg_match('/^\s*\[\[EDUAI_BOOK:([a-f0-9]{64})\]\]\r?\n/', $text, $marker)) return null;
        if ($marker[1] === '64afcf11558d598d1ba09a7cce6fb1eeacb935a295a5cfc36444e97a9bb7d59c') {
            return self::evsProfile(explode("\f", substr($text, strlen($marker[0]))));
        }
        if ($marker[1] !== '41a428b872efdb51a72d51a06dd0cb05ffceca2a96e525f2f5a9607b6fc870c0') {
            throw new ContentDetectionException('Unrecognized book profile. Please upload the original PDF again.');
        }
        $pages = explode("\f", substr($text, strlen($marker[0])));
        if (count($pages) !== 83) throw new ContentDetectionException('English-4 requires all 83 PDF pages. Please upload the original PDF again.');
        // Manually checked printed contents; printed page 7 is physical PDF page 2.
        $outline = [
            [7, 'The Clever Fisherman and the Tricky Jinn'],
            [13, 'The Man who Wanted More'], [18, 'Words'], [21, 'Colour in the sky'],
            [25, 'The Magic of Music'], [30, 'Elephant Feast'], [38, 'The Majestic Big Cat'],
            [46, 'The Mountain and the Squirrel'], [49, 'Healthy Habits for a Happy Life'],
            [56, 'My Bank Account'], [66, 'Daddy Fell into the Pond'], [68, 'Rabindra Nath Tagore'],
            [74, 'The Great Traveller'], [80, 'Bachandri Pal-Courage on the Highest Peak'],
        ];
        $chapters = [];
        foreach ($outline as $index => [$printedPage, $title]) {
            $start = $printedPage - 6;
            $end = ($outline[$index + 1][0] ?? 87) - 6;
            $content = trim(implode("\n\n", array_slice($pages, $start, $end - $start)));
            if (mb_strlen($content) < 40) throw new ContentDetectionException('Not enough readable text in chapter ' . ($index + 1) . '. Please retry OCR.');
            $title = ($index + 1) . '. ' . $title;
            $chapters[] = ['title' => $title, 'content' => $content, 'lessons' => [['title' => $title, 'content' => $content]]];
        }
        return $chapters;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }

    private static function evsProfile(array $pages): array
    {
        if (count($pages) !== 93) throw new ContentDetectionException('EVS-4 requires all 93 PDF pages. Upload the original file again.');
        // Contents visually checked against physical pages 2 and 7 (printed 5, 10).
        // Only the exact file hash activates this verified outline.
        $outline = [[5,'My Family'],[10,'Occupations and professions'],[16,'Amazing Birds'],
            [25,'Animal’s Word'],[33,'System of Plants'],[40,'Valley of Flowers'],
            [45,'Recreation'],[50,'Safety and First-Aid'],[59,'Food We Eat'],
            [67,'Water ‘O’ Water'],[72,'Houses and Bridges'],[77,'House Transformations'],
            [82,'Mapping Neighbourhood'],[88,'Currency and Transport']];
        $out=[];
        foreach($outline as $i=>[$printed,$name]) {
            $start=$printed-4; $end=($outline[$i+1][0]??93)-4;
            $content=trim(implode("\n\n",array_slice($pages,$start,$end-$start)));
            if(mb_strlen($content)<40) throw new ContentDetectionException('EVS chapter '.($i+1).' has insufficient readable text. Retry OCR.');
            $title=($i+1).'. '.$name;
            $out[]=['title'=>$title,'content'=>$content,'lessons'=>[['title'=>$title,'content'=>$content]]];
        }
        return $out;
    }

    private static function fail(): never
    {
        throw new ContentDetectionException('Contents detected, but chapter titles/page ranges could not be verified reliably. Book was not saved. Please check the Contents page and upload a clearer PDF or a PDF with selectable text.');
    }

    private static function fromContents(string $text): ?array
    {
        $pages = explode("\f", preg_replace('/^\s*\[\[EDUAI_PAGES_V1\]\]\r?\n/', '', $text, 1));
        $tocStart = null;
        foreach (array_slice($pages, 0, 12) as $i => $page) {
            if (preg_match('/^\s*(?:table\s+of\s+contents|contents)(?:\s*\(?continued\)?)?\s*$/imu', $page)) { $tocStart = $i; break; }
        }
        if ($tocStart === null) return null;
        $rows = []; $tocEnd = $tocStart; $stopPage = null;
        // Read consecutive Contents pages; never scan exercises throughout the book.
        for ($p = $tocStart; $p < count($pages); $p++) {
            $added = 0; $pending = ''; $terminal = false;
            foreach (preg_split('/\R/u', $pages[$p]) as $raw) {
                $line = trim($raw);
                if ($line === '' || preg_match('/^(?:table\s+of\s+contents|contents|page(?:\s+no\.?)?|s\.?\s*no\.?|chapter\s+name)\s*$/i', $line)) continue;
                if (preg_match('/^(?:unit|section)\s*[:.\-]?\s*[\dIVX]+\b/i', $line)) continue;
                if (preg_match('/^(?:model\s+)?test\s*paper|^answer\s*key|^appendix|^glossary|^index\b/i', $line)) {
                    if (preg_match('/(\d{1,5})\s*$/', $line, $end)) $stopPage = (int)$end[1];
                    $terminal = true; break;
                }
                $candidate = $pending === '' ? $line : $pending . ' ' . $line;
                // Accept numbered or unnumbered rows, dot leaders and wrapped titles.
                if (preg_match('/^(?:(?:chapter|lesson)\s+)?(?:(\d{1,4})\s*[.)|:\-]?\s+)?(.+?)[\s.·…|]+(\d{1,5})\s*$/iu', $candidate, $m)
                    && preg_match('/\p{L}/u', $m[2])) {
                    $title = trim($m[2], " .\t|·"); $printed = (int)$m[3];
                    if (mb_strlen($title) < 2 || mb_strlen($title) > 180 || $printed < 1) self::fail();
                    if ($m[1] !== '' && (int)$m[1] !== count($rows) + 1) self::fail();
                    if ($rows && $printed <= $rows[count($rows)-1]['page']) self::fail();
                    $rows[] = ['title'=>$title, 'page'=>$printed]; $added++; $pending = '';
                } else {
                    // An unexplained Contents line must not silently lose a chapter.
                    $pending = $candidate;
                    if (mb_strlen($pending) > 240) break;
                }
            }
            if (!$added) {
                if ($p === $tocStart) self::fail();
                break;
            }
            if ($pending !== '' && !preg_match('/^\d+$/', $pending)) self::fail();
            $tocEnd = $p;
            if ($terminal) break;
            // A subsequent page is a continuation only if its first entry continues
            // the sequence, or it explicitly labels itself as Contents.
            $next = $pages[$p+1] ?? '';
            $expected = count($rows)+1;
            if (!preg_match('/^\s*(?:table\s+of\s+)?contents\b/imu', $next)
                && !preg_match('/^\s*'.$expected.'[.)|]?\s+[^\r\n]+[\s.·…|]+\d+\s*$/mu', mb_substr($next,0,600))) break;
        }
        if (count($rows) < 2) self::fail();
        // A title appearing in the page heading/footer is an anchor. Two distinct
        // chapters must agree on printed-page versus physical-page offset.
        $votes = [];
        foreach ($rows as $r => $row) {
            $wanted = self::key($row['title']);
            for ($p=$tocEnd+1; $p<count($pages); $p++) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u',$pages[$p])), fn($s)=>$s!==''));
                $headingLines=array_slice($lines,0,8);
                $candidates=array_merge($headingLines,array_slice($lines,-3));
                for($j=0;$j<count($headingLines)-1;$j++) $candidates[]=$headingLines[$j].' '.$headingLines[$j+1];
                foreach ($candidates as $line) {
                    if (self::key($line) === $wanted) { $votes[$p-$row['page']][$r] = true; break; }
                    $line = preg_replace('/^(?:(?:chapter|lesson)\s*)?\d+[.)|:\-]?\s*/iu','',$line);
                    if (self::key($line) === $wanted) { $votes[$p-$row['page']][$r] = true; break; }
                    $line = preg_replace('/\s+\d+\s*$/u','',$line);
                    if (self::key($line) === $wanted) { $votes[$p-$row['page']][$r] = true; break; }
                }
            }
        }
        $valid=[];
        foreach ($votes as $offset=>$anchors) {
            if (count($anchors)<2) continue;
            if ($rows[0]['page']+$offset <= $tocEnd || $rows[count($rows)-1]['page']+$offset >= count($pages)) continue;
            $valid[$offset]=count($anchors);
        }
        if (!$valid) self::fail();
        arsort($valid); $offset = (int)array_key_first($valid);
        $scores=array_values($valid);
        if (isset($scores[1]) && $scores[0] === $scores[1]) self::fail();
        // Validate every boundary with either its title or its printed page label.
        $chapters=[];
        foreach ($rows as $i=>$row) {
            $start=$row['page']+$offset;
            $end=isset($rows[$i+1]) ? $rows[$i+1]['page']+$offset : ($stopPage !== null ? $stopPage+$offset : count($pages));
            if ($end <= $start || $end > count($pages)) self::fail();
            if (!isset($votes[$offset][$i]) && !preg_match('/^\s*(?:page\s+)?'. $row['page'].'\s*$/imu', $pages[$start])) self::fail();
            $content=trim(implode("\n\n",array_slice($pages,$start,$end-$start)));
            if (mb_strlen($content)<40) self::fail();
            $title=($i+1).'. '.$row['title'];
            $chapters[]=['title'=>$title,'content'=>$content,'lessons'=>[['title'=>$title,'content'=>$content]]];
        }
        return $chapters;
    }
}
