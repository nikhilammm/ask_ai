<?php
declare(strict_types=1);

/** mPDF shapes Indic scripts and embeds fonts; the old ASCII writer lost Hindi. */
final class UnicodeExamPdf
{
    public static function exam(array $quiz, array $payload, bool $includeAnswers): string
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $temp = dirname(__DIR__, 2) . '/uploads/pdf-private';
        if (!is_dir($temp) && !@mkdir($temp, 0750, true) && !is_dir($temp)) throw new RuntimeException('PDF temporary directory is not writable.');
        if (!is_file($temp . '/.htaccess') && @file_put_contents($temp . '/.htaccess', "Require all denied\n") === false) throw new RuntimeException('Cannot protect PDF temporary directory.');
        $pdf = new \Mpdf\Mpdf(['mode'=>'utf-8', 'format'=>'A4', 'tempDir'=>$temp,
            'default_font'=>'freeserif', 'default_font_size'=>12,
            'fontdata'=>['freeserif'=>['R'=>'FreeSerif.ttf','B'=>'FreeSerifBold.ttf','I'=>'FreeSerifItalic.ttf','BI'=>'FreeSerifBoldItalic.ttf','useOTL'=>0xFF]],
            'sans_fonts'=>['freeserif'],'serif_fonts'=>['freeserif'],'mono_fonts'=>['freeserif'],
            'backupSubsFont'=>['freeserif'],
            'margin_left'=>17,'margin_right'=>17,'margin_top'=>16,'margin_bottom'=>17]);
        $pdf->SetTitle((string)($payload['exam_title'] ?? 'Lesson Assessment'));
        $pdf->SetAuthor('EduAI');
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt">{PAGENO}</div>');
        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $questions = (array)($quiz['questions'] ?? []);
        $marksByType = (array)($payload['marks_by_type'] ?? []);
        $marks = static fn($q) => max(1, min(100, (int)($marksByType[$q['question_type']] ?? ($q['question_type']==='short_answer' ? 2 : 1))));
        $total = array_sum(array_map($marks, $questions));
        $html = '<style>body{font-family:freeserif;line-height:1.45}h1{font-size:19pt;text-align:center;margin:0}h2{font-size:15pt;text-align:center;margin:6pt}h3{font-size:12pt;background:#eee;padding:6pt}table{width:100%;border-collapse:collapse}td{padding:5pt}.question{margin:12pt 0 5pt}.answer-line{border-bottom:1px solid #bbb;height:22pt}.mark{float:right;font-size:10pt}</style>';
        $html .= '<h1>'.$esc($payload['institution_name'] ?? 'EduAI School').'</h1><h2>'.$esc($payload['exam_title'] ?? 'Lesson Assessment').'</h2>';
        $html .= '<table><tr><td>Class: '.$esc($payload['class_name'] ?? '').'</td><td>Subject: '.$esc($payload['subject'] ?? '').'</td></tr><tr><td>Time: '.$esc($payload['duration_minutes'] ?? 60).' minutes</td><td>Maximum marks: '.$total.'</td></tr></table>';
        $html .= '<p>Name: __________________________ &nbsp; Roll no.: __________</p><p style="font-size:10pt">Attempt all questions. Marks are shown against each question.</p>';
        $labels=['mcq'=>'Multiple Choice Questions','fill_in_the_blank'=>'Fill in the Blanks','true_false'=>'True / False','short_answer'=>'Short Answer Questions'];
        $previous = '';
        foreach ($questions as $i => $q) {
            $type = (string)($q['question_type'] ?? 'short_answer');
            if ($type !== $previous) $html .= '<h3>'.$esc($labels[$type] ?? $type).'</h3>';
            $previous = $type;
            $html .= '<div class="question"><b>'.($i+1).'.</b> '.$esc($q['question_text'] ?? '').' <span class="mark">['.$marks($q).']</span></div>';
            foreach ((array)($q['options'] ?? []) as $j=>$option) $html .= '<div style="margin-left:14pt">'.chr(65+$j).'. '.$esc($option).'</div>';
            if ($type === 'short_answer') $html .= '<div class="answer-line"></div><div class="answer-line"></div>';
            if ($type === 'fill_in_the_blank') $html .= '<div class="answer-line"></div>';
        }
        if ($includeAnswers) {
            $html .= '<pagebreak/><h2>Answer Key / उत्तरमाला</h2>';
            foreach ($questions as $i=>$q) $html .= '<p><b>'.($i+1).'.</b> '.$esc($q['correct_answer'] ?? '').'</p>';
        }
        $pdf->WriteHTML($html);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
