<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Setting;
use App\Services\Theses\ThesisCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * A small OAI-PMH 2.0 provider (oai_dc) for the public thesis folders, so repositories such
 * as the Iraqi Digital Repository or Google Scholar-style harvesters can collect their records.
 */
class OaiController extends Controller
{
    private const PAGE = 100;

    public function __construct(private ThesisCatalog $catalog) {}

    public function __invoke(Request $request): Response
    {
        if (Setting::get('oai_enabled', '1') !== '1') {
            abort(404);
        }

        $args = $request->query();
        $verb = $args['verb'] ?? '';
        $body = match ($verb) {
            'Identify' => $this->identify(),
            'ListMetadataFormats' => $this->formats($args),
            'ListSets' => $this->error('noSetHierarchy', 'This repository does not support sets.'),
            'ListIdentifiers' => $this->list($args, false),
            'ListRecords' => $this->list($args, true),
            'GetRecord' => $this->getRecord($args),
            default => $this->error('badVerb', 'Illegal OAI verb.'),
        };

        $attrs = collect($args)->only(['verb', 'identifier', 'metadataPrefix', 'from', 'until', 'set', 'resumptionToken'])
            ->filter(fn ($v) => is_string($v))
            ->map(fn ($v, $k) => $k.'="'.$this->e($v).'"')->implode(' ');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd">'
            .'<responseDate>'.now()->utc()->format('Y-m-d\TH:i:s\Z').'</responseDate>'
            .'<request'.($attrs && ! str_starts_with($body, '<error code="badVerb"') ? ' '.$attrs : '').'>'.$this->e($request->url()).'</request>'
            .$body.'</OAI-PMH>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function identify(): string
    {
        $earliest = Folder::whereIn('id', $this->catalog->visibleIds(null))->min('updated_at');

        return '<Identify>'
            .'<repositoryName>'.$this->e($this->repositoryName()).'</repositoryName>'
            .'<baseURL>'.$this->e(route('oai')).'</baseURL>'
            .'<protocolVersion>2.0</protocolVersion>'
            .'<adminEmail>'.$this->e(config('mail.from.address') ?: 'admin@example.com').'</adminEmail>'
            .'<earliestDatestamp>'.($earliest ? Carbon::parse($earliest)->utc()->format('Y-m-d\TH:i:s\Z') : '2000-01-01T00:00:00Z').'</earliestDatestamp>'
            .'<deletedRecord>no</deletedRecord>'
            .'<granularity>YYYY-MM-DDThh:mm:ssZ</granularity>'
            .'</Identify>';
    }

    private function formats(array $args): string
    {
        if (isset($args['identifier']) && ! $this->find($args['identifier'])) {
            return $this->error('idDoesNotExist', 'No such record.');
        }

        return '<ListMetadataFormats><metadataFormat><metadataPrefix>oai_dc</metadataPrefix>'
            .'<schema>http://www.openarchives.org/OAI/2.0/oai_dc.xsd</schema>'
            .'<metadataNamespace>http://www.openarchives.org/OAI/2.0/oai_dc/</metadataNamespace>'
            .'</metadataFormat></ListMetadataFormats>';
    }

    private function getRecord(array $args): string
    {
        if (! isset($args['identifier'], $args['metadataPrefix'])) {
            return $this->error('badArgument', 'identifier and metadataPrefix are required.');
        }
        if ($args['metadataPrefix'] !== 'oai_dc') {
            return $this->error('cannotDisseminateFormat', 'Only oai_dc is supported.');
        }
        $folder = $this->find($args['identifier']);

        return $folder ? '<GetRecord>'.$this->record($folder, true).'</GetRecord>' : $this->error('idDoesNotExist', 'No such record.');
    }

    private function list(array $args, bool $full): string
    {
        $offset = 0;
        if (isset($args['resumptionToken'])) {
            $token = json_decode(base64_decode(strtr((string) $args['resumptionToken'], '-_', '+/'), true) ?: '', true);
            if (! is_array($token) || count($args) > 2) {
                return $this->error('badResumptionToken', 'Invalid resumption token.');
            }
            $args = ['metadataPrefix' => 'oai_dc'] + array_intersect_key($token, array_flip(['from', 'until']));
            $offset = (int) ($token['offset'] ?? 0);
        }
        if (($args['metadataPrefix'] ?? null) === null) {
            return $this->error('badArgument', 'metadataPrefix is required.');
        }
        if ($args['metadataPrefix'] !== 'oai_dc') {
            return $this->error('cannotDisseminateFormat', 'Only oai_dc is supported.');
        }
        if (isset($args['set'])) {
            return $this->error('noSetHierarchy', 'This repository does not support sets.');
        }

        $q = Folder::whereIn('id', $this->catalog->visibleIds(null))->orderBy('id');
        foreach (['from' => '>=', 'until' => '<='] as $key => $op) {
            if (isset($args[$key])) {
                try {
                    $date = Carbon::parse($args[$key], 'UTC');
                } catch (\Throwable) {
                    return $this->error('badArgument', "Bad {$key} date.");
                }
                $q->where('updated_at', $op, strlen($args[$key]) === 10 ? ($op === '>=' ? $date->startOfDay() : $date->endOfDay()) : $date);
            }
        }

        $total = (clone $q)->count();
        $page = $q->skip($offset)->take(self::PAGE)->get();
        if ($page->isEmpty()) {
            return $this->error('noRecordsMatch', 'No records match.');
        }

        $tag = $full ? 'ListRecords' : 'ListIdentifiers';
        $xml = '<'.$tag.'>'.$page->map(fn ($f) => $full ? $this->record($f, true) : $this->header($f))->implode('');
        $next = $offset + self::PAGE;
        if ($next < $total || $offset > 0) {
            $token = $next < $total
                ? rtrim(strtr(base64_encode(json_encode(array_filter(['offset' => $next, 'from' => $args['from'] ?? null, 'until' => $args['until'] ?? null]))), '+/', '-_'), '=')
                : '';
            $xml .= '<resumptionToken completeListSize="'.$total.'" cursor="'.$offset.'">'.$token.'</resumptionToken>';
        }

        return $xml.'</'.$tag.'>';
    }

    private function record(Folder $f, bool $withMetadata): string
    {
        $dc = [];
        $add = function (string $el, ?string $value) use (&$dc) {
            if (filled($value)) {
                $dc[] = "<dc:{$el}>".$this->e($value)."</dc:{$el}>";
            }
        };
        $add('title', $f->thesis_title ?: $f->name);
        $add('creator', $f->researcher_name);
        $add('contributor', $f->supervisor_name ? $f->supervisor_name.' (مشرف)' : null);
        $add('subject', $f->subject);
        foreach (preg_split('/[,،]+/u', (string) $f->keywords) as $k) {
            $add('subject', trim($k));
        }
        $add('description', $f->abstract);
        $add('publisher', collect([$f->university, $f->college, $f->department])->filter()->implode(' - ') ?: $f->publisher);
        $add('date', $f->defense_date?->format('Y-m-d') ?: $f->publication_year);
        $add('type', 'Thesis');
        $add('type', ThesisCatalog::DEGREE_LABELS[$f->degree] ?? null);
        $add('format', 'application/pdf');
        $add('identifier', route('guest.show', $f->id));
        $add('language', $f->language && $f->language !== 'other' ? $f->language : null);

        return '<record>'.$this->header($f).($withMetadata
            ? '<metadata><oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/" '
                .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd">'
                .implode('', $dc).'</oai_dc:dc></metadata>'
            : '').'</record>';
    }

    private function header(Folder $f): string
    {
        return '<header><identifier>'.$this->e($this->identifier($f)).'</identifier>'
            .'<datestamp>'.$f->updated_at->copy()->utc()->format('Y-m-d\TH:i:s\Z').'</datestamp></header>';
    }

    private function identifier(Folder $f): string
    {
        return 'oai:'.request()->getHost().':thesis/'.$f->id;
    }

    private function find(string $identifier): ?Folder
    {
        if (! preg_match('/:thesis\/(\d+)$/', $identifier, $m)) {
            return null;
        }
        $id = (int) $m[1];

        return in_array($id, $this->catalog->visibleIds(null), true) ? Folder::find($id) : null;
    }

    private function repositoryName(): string
    {
        return collect([Setting::get('institution_college'), Setting::get('institution_university')])->filter()->implode(' - ')
            ?: (string) Setting::get('site_name', config('app.name'));
    }

    private function error(string $code, string $message): string
    {
        return '<error code="'.$code.'">'.$this->e($message).'</error>';
    }

    private function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
