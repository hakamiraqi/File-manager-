<?php

namespace App\Services\Exams;

use App\Http\Controllers\Exams\ManageController;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * QTI 3.0, the standard other exam systems read: a zip of one XML file per question and a
 * manifest. Choice (one or several), true/false, short, numeric and essay questions go out and
 * come in; the other kinds have no common QTI form and are left out of the export.
 */
class QtiPackage
{
    private const NS = 'http://www.imsglobal.org/xsd/imsqtiasi_v3p0';

    private const RP = 'https://purl.imsglobal.org/spec/qti/v3p0/rptemplates/';

    public function export(Exam $exam): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qti').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $lang = $exam->languages[0] ?? 'ar';
        $resources = '';
        foreach ($exam->questions()->with('choices')->get() as $i => $q) {
            // Pictures (2026-11-09): the question's and its choices' go in «media/», named in the item.
            $this->media = [];
            $xml = $this->item($q, 'q'.($i + 1), $lang, $exam->languages ?? []);
            if ($xml === null) {
                continue;
            }
            $href = 'items/q'.($i + 1).'.xml';
            $zip->addFromString($href, $xml);
            $files = '<file href="'.$href.'"/>';
            foreach ($this->media as $stored => $file) {
                $zip->addFromString($file, Storage::disk('local')->get(ManageController::IMAGES.'/'.$stored));
                $files .= '<file href="'.$file.'"/>';
            }
            $resources .= '<resource identifier="q'.($i + 1).'" type="imsqti_item_xmlv3p0" href="'.$href.'">'.$files.'</resource>';
        }
        $title = $this->e($exam->text('title'));
        $zip->addFromString('imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest xmlns="http://www.imsglobal.org/xsd/qti/qtiv3p0/imscp_v1p1" identifier="examnest-'.$exam->id.'">'
            .'<metadata><schema>QTI Package</schema><schemaversion>3.0.0</schemaversion><title>'.$title.'</title></metadata>'
            .'<organizations/><resources>'.$resources.'</resources></manifest>');
        $zip->close();

        return $path;
    }

    public function import(string $path, User $owner): Exam
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('ليس ملف zip صالحاً.');
        }
        $title = 'امتحان مستورد (QTI)';
        if (($manifest = $zip->getFromName('imsmanifest.xml')) && preg_match('#<title>(.*?)</title>#s', $manifest, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]))) ?: $title;
        }
        $items = [];
        $this->zip = $zip;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.xml') && basename($name) !== 'imsmanifest.xml') {
                $xml = (string) $zip->getFromIndex($i);
                if (str_contains($xml, 'qti-assessment-item')) {
                    $items[$name] = $xml;
                }
            }
        }
        ksort($items, SORT_NATURAL);
        $parsed = collect($items)->map(fn ($xml, $name) => $this->parse($xml, $name))->filter()->values();
        $zip->close();
        $this->zip = null;
        if ($parsed->isEmpty()) {
            throw new InvalidArgumentException('لم أجد في الملف أسئلة QTI 3.0 مدعومة.');
        }
        $lang = $parsed->first()['lang'];

        return DB::transaction(function () use ($parsed, $owner, $title, $lang) {
            $exam = Exam::create(['user_id' => $owner->id, 'title' => [$lang => $title], 'languages' => [$lang], 'is_published' => false]);
            foreach ($parsed as $i => $q) {
                $question = Question::create(['exam_id' => $exam->id, 'type' => $q['type'], 'body' => [$lang => $q['body']], 'points' => $q['points'], 'settings' => $q['settings'] ?: null, 'position' => $i, 'image' => $q['image'] ?? null]);
                foreach ($q['choices'] as $j => [$text, $right]) {
                    Choice::create(['exam_question_id' => $question->id, 'body' => $text === '' ? [] : [$lang => $text], 'is_correct' => $right, 'position' => $j, 'image' => $q['choice_images'][$j] ?? null]);
                }
            }

            return $exam;
        });
    }

    /** Pictures of the item being written: stored name → path in the package. */
    private array $media = [];

    /** The package being read, for the pictures its items name. */
    private ?ZipArchive $zip = null;

    private function img(?string $stored): string
    {
        if (! $stored || ! ManageController::picture($stored)) {
            return '';
        }
        $this->media[$stored] ??= 'media/'.$stored;

        return '<img src="../'.$this->media[$stored].'" alt=""/>';
    }

    /** A picture an item names (relative to the item), stored here; null when it is not in the package. */
    private function readImg(?SimpleXMLElement $at, string $itemPath): ?string
    {
        if (! $at || ! $this->zip) {
            return null;
        }
        $img = $at->xpath('.//*[local-name()="img"]')[0] ?? null;
        $src = $img ? (string) $img['src'] : '';
        if ($src === '' || str_contains($src, '://')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', (dirname($itemPath) === '.' ? '' : dirname($itemPath).'/').$src) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $bytes = $this->zip->getFromName(implode('/', $parts));

        return $bytes === false ? null : ManageController::storePicture($bytes, $src);
    }

    private function item(Question $q, string $id, string $lang, array $langs): ?string
    {
        $text = fn ($v) => Question::pick($v, $lang, $langs);
        $body = ($q->format === 'html' ? HtmlCleaner::clean($text($q->body)) : '<p>'.nl2br($this->e($text($q->body))).'</p>').($q->image ? '<p>'.$this->img($q->image).'</p>' : '');
        $choices = $q->choices->values();
        $cid = fn ($k) => 'c'.($k + 1);
        [$declaration, $interaction, $rp] = match ($q->type) {
            Question::SINGLE, Question::TRUE_FALSE, Question::MULTIPLE => [
                '<qti-response-declaration identifier="RESPONSE" cardinality="'.($q->type === Question::MULTIPLE ? 'multiple' : 'single').'" base-type="identifier"><qti-correct-response>'
                    .$choices->map(fn ($c, $k) => $c->is_correct ? '<qti-value>'.$cid($k).'</qti-value>' : '')->implode('').'</qti-correct-response></qti-response-declaration>',
                '<qti-choice-interaction response-identifier="RESPONSE" max-choices="'.($q->type === Question::MULTIPLE ? 0 : 1).'">'
                    .$choices->map(fn ($c, $k) => '<qti-simple-choice identifier="'.$cid($k).'">'.$this->img($c->image).$this->e($text($c->body)).'</qti-simple-choice>')->implode('').'</qti-choice-interaction>',
                'match_correct',
            ],
            Question::SHORT => [
                '<qti-response-declaration identifier="RESPONSE" cardinality="single" base-type="string"><qti-correct-response><qti-value>'.$this->e((string) ($q->settings['answers'][0] ?? '')).'</qti-value></qti-correct-response>'
                    .'<qti-mapping default-value="0">'.collect($q->settings['answers'] ?? [])->map(fn ($a) => '<qti-map-entry map-key="'.$this->e((string) $a).'" mapped-value="'.$q->points.'" case-sensitive="false"/>')->implode('').'</qti-mapping></qti-response-declaration>',
                '<p><qti-text-entry-interaction response-identifier="RESPONSE"/></p>',
                'map_response',
            ],
            Question::NUMERIC => [
                '<qti-response-declaration identifier="RESPONSE" cardinality="single" base-type="float"><qti-correct-response><qti-value>'.$this->e((string) ($q->settings['answer'] ?? '')).'</qti-value></qti-correct-response></qti-response-declaration>',
                '<p><qti-text-entry-interaction response-identifier="RESPONSE"/></p>',
                'match_correct',
            ],
            Question::ESSAY => [
                '<qti-response-declaration identifier="RESPONSE" cardinality="single" base-type="string"/>',
                '<qti-extended-text-interaction response-identifier="RESPONSE"/>',
                null,
            ],
            default => [null, null, null],
        };
        if ($declaration === null) {
            return null;
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<qti-assessment-item xmlns="'.self::NS.'" identifier="'.$id.'" title="'.$this->e(mb_substr(strip_tags($text($q->body)), 0, 80)).'" adaptive="false" time-dependent="false" xml:lang="'.$lang.'">'
            .$declaration
            .'<qti-outcome-declaration identifier="SCORE" cardinality="single" base-type="float"><qti-default-value><qti-value>0</qti-value></qti-default-value></qti-outcome-declaration>'
            .'<qti-outcome-declaration identifier="MAXSCORE" cardinality="single" base-type="float"><qti-default-value><qti-value>'.$q->points.'</qti-value></qti-default-value></qti-outcome-declaration>'
            .'<qti-item-body><div>'.$body.'</div>'.$interaction.'</qti-item-body>'
            .($rp ? '<qti-response-processing template="'.self::RP.$rp.'.xml"/>' : '')
            .'</qti-assessment-item>';
    }

    /** One item: its kind, text, choices and answers; null for kinds this system has no match for. */
    private function parse(string $xml, string $itemPath = ''): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (! $doc) {
            return null;
        }
        $find = fn (SimpleXMLElement $at, string $name) => $at->xpath('.//*[local-name()="'.$name.'"]') ?: [];
        $lang = in_array(substr((string) ($doc->attributes('xml', true)['lang'] ?? 'ar'), 0, 2), Exam::LANGUAGES, true) ? substr((string) $doc->attributes('xml', true)['lang'], 0, 2) : 'ar';
        $correctResponse = $find($doc, 'qti-correct-response')[0] ?? null;
        $correct = $correctResponse ? array_map('strval', $find($correctResponse, 'qti-value')) : [];
        $max = collect($find($doc, 'qti-outcome-declaration'))->first(fn ($o) => (string) $o['identifier'] === 'MAXSCORE');
        $points = $max ? (float) (string) ($find($max, 'qti-value')[0] ?? '1') : 1.0;
        $points = $points > 0 ? $points : 1.0;
        $body = $find($doc, 'qti-item-body')[0] ?? null;
        if (! $body) {
            return null;
        }
        $bodyXml = $body->asXML();
        // The question's picture: the first one in its text, outside the choices.
        $bodyOnly = simplexml_load_string(preg_replace('#<qti-choice-interaction\b.*?</qti-choice-interaction>#s', '', $bodyXml) ?: '<x/>', SimpleXMLElement::class, LIBXML_NONET) ?: null;
        $image = $this->readImg($bodyOnly, $itemPath);
        $bodyText = trim(QuestionImporter::plain(preg_replace('#<qti-(choice|text-entry|extended-text)-interaction\b.*?(/>|</qti-\1-interaction>)#s', '', $bodyXml)));

        if ($choice = $find($doc, 'qti-choice-interaction')[0] ?? null) {
            $simple = $find($choice, 'qti-simple-choice');
            $choices = array_map(fn ($c) => [trim(QuestionImporter::plain($c->asXML())), in_array((string) $c['identifier'], $correct, true)], $simple);
            $multiple = (int) ($choice['max-choices'] ?? 1) !== 1;

            return ['type' => $multiple ? 'multiple' : 'single', 'body' => $bodyText, 'choices' => $choices, 'settings' => [], 'points' => $points, 'lang' => $lang,
                'image' => $image, 'choice_images' => array_map(fn ($c) => $this->readImg($c, $itemPath), $simple)];
        }
        if ($find($doc, 'qti-text-entry-interaction')) {
            $declaration = $find($doc, 'qti-response-declaration')[0] ?? null;
            if (in_array((string) ($declaration['base-type'] ?? ''), ['float', 'integer'], true)) {
                return ['image' => $image, 'type' => 'numeric', 'body' => $bodyText, 'choices' => [], 'settings' => ['answer' => $correct[0] ?? '', 'tolerance' => '0'], 'points' => $points, 'lang' => $lang];
            }
            $answers = collect($correct)->merge(collect($find($doc, 'qti-map-entry'))->filter(fn ($e) => (float) $e['mapped-value'] > 0)->map(fn ($e) => (string) $e['map-key']))->filter()->unique()->values()->all();

            return ['image' => $image, 'type' => 'short', 'body' => $bodyText, 'choices' => [], 'settings' => ['answers' => $answers, 'case_sensitive' => false], 'points' => $points, 'lang' => $lang];
        }
        if ($find($doc, 'qti-extended-text-interaction')) {
            return ['image' => $image, 'type' => 'essay', 'body' => $bodyText, 'choices' => [], 'settings' => [], 'points' => $points, 'lang' => $lang];
        }

        return null;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
