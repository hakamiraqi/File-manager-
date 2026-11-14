<?php

namespace App\Services\Library\Koha;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Services\Library\Marc\MarcMapper;
use App\Services\Library\Marc\MarcReader;
use App\Services\Library\Marc\MarcWriter;
use Illuminate\Validation\ValidationException;

/**
 * «الخيار ب»: lending, returns, renewals and holds are done in Koha, through its API.
 * FileNest asks Koha, and records the answer locally only once Koha has agreed.
 */
class KohaBridge
{
    /** What Koha's circulation checks mean, for the desk. */
    private const REASONS = [
        'DEBT' => 'على القارئ ديون في Koha',
        'DEBT_GUARANTEE' => 'على كفيل القارئ ديون في Koha',
        'GNA' => 'عنوان القارئ غير صحيح في Koha',
        'CARD_LOST' => 'بطاقة القارئ مسجلة مفقودة',
        'EXPIRED' => 'انتهت صلاحية اشتراك القارئ',
        'DEBARRED' => 'القارئ موقوف عن الإعارة',
        'USERBLOCKEDOVERDUE' => 'القارئ موقوف بسبب كتب متأخرة',
        'USERBLOCKEDNOENDDATE' => 'القارئ موقوف',
        'TOO_MANY' => 'وصل القارئ للحد الأقصى من الإعارات',
        'PATRON_CANT' => 'فئة القارئ لا يُسمح لها بإعارة هذا النوع',
        'NOT_FOR_LOAN' => 'هذه النسخة غير مخصصة للإعارة',
        'NOT_FOR_LOAN_FORCING' => 'هذه النسخة غير مخصصة للإعارة',
        'WTHDRAWN' => 'هذه النسخة مسحوبة من المجموعة',
        'ITEM_LOST' => 'هذه النسخة مسجلة مفقودة',
        'RESTRICTED' => 'هذه النسخة مقيدة',
        'AGE_RESTRICTION' => 'هذه النسخة مقيدة بعمر القارئ',
        'RESERVED' => 'هذا الكتاب محجوز لقارئ آخر',
        'RESERVE_WAITING' => 'هذه النسخة جاهزة لقارئ آخر حجزها',
        'TRANSFERRED' => 'هذه النسخة في طريقها لفرع آخر',
        'ISSUED_TO_ANOTHER' => 'هذه النسخة مُعارة لقارئ آخر',
        'BIBLIO_ALREADY_ISSUED' => 'لدى القارئ نسخة من هذا الكتاب',
        'RENEW_ISSUE' => 'هذه النسخة مُعارة لهذا القارئ أصلاً',
        'UNKNOWN_BARCODE' => 'الباركود غير معروف في Koha',
        'ADDITIONAL_MATERIALS' => 'للنسخة ملحقات يجب التأكد منها',
        'NO_MORE_RENEWALS' => 'وصلت للحد الأقصى لمرات التجديد',
        'too_many' => 'وصلت للحد الأقصى لمرات التجديد',
        'on_reserve' => 'هذا الكتاب محجوز لقارئ آخر، لا يمكن تجديده',
        'too_soon' => 'لم يحن موعد التجديد بعد',
        'overdue' => 'لا يمكن تجديد كتاب متأخر',
        'restriction' => 'القارئ موقوف',
        'debt_limit' => 'تجاوز القارئ حد الديون',
        'hold_limit' => 'وصل القارئ للحد الأقصى من الحجوزات',
        'expired' => 'انتهت صلاحية اشتراك القارئ',
        'card_lost' => 'بطاقة القارئ مسجلة مفقودة',
        'restricted' => 'القارئ موقوف',
        'bad_address' => 'عنوان القارئ غير صحيح في Koha',
    ];

    public function __construct(private KohaClient $koha) {}

    public function checkout(LibraryMember $member, BookCopy $copy): array
    {
        $patron = $this->patronId($member);
        $item = $this->itemId($copy);

        $check = $this->call(fn () => $this->koha->get('/checkouts/availability', ['patron_id' => $patron, 'item_id' => $item]));
        if ($reasons = array_keys(array_filter((array) ($check['blockers'] ?? [])))) {
            $this->fail('رفض Koha الإعارة: '.$this->explain($reasons));
        }
        if ($reasons = array_keys(array_filter((array) ($check['confirms'] ?? [])))) {
            $this->fail('Koha يطلب تأكيداً قبل الإعارة ('.$this->explain($reasons).'). أكمل هذه الإعارة من واجهة Koha.');
        }

        $body = ['patron_id' => $patron, 'item_id' => $item];
        if (KohaSettings::library() !== '') {
            $body['library_id'] = KohaSettings::library();
        }

        return $this->call(fn () => $this->koha->post('/checkouts', $body, array_filter(['confirmation' => $check['confirmation_token'] ?? null])));
    }

    /** The return. Koha versions without the check-in route are told to return in Koha. */
    public function checkin(BookCopy $copy): array
    {
        if (! KohaSettings::can('/checkins')) {
            $this->fail('إصدار Koha لديكم لا يسمح بالإرجاع عبر الواجهة البرمجية. أرجِع الكتاب من واجهة Koha، وسيظهر هنا خلال دقائق.');
        }
        $body = ['external_id' => $copy->barcode];
        if (KohaSettings::library() !== '') {
            $body['library_id'] = KohaSettings::library();
        }
        try {
            return $this->koha->post('/checkins', $body);
        } catch (KohaError $e) {
            // Accompanying materials: the librarian has the copy in hand, so it is confirmed.
            if ($e->status === 412 && ($token = $e->data['confirmation_token'] ?? null)) {
                return $this->call(fn () => $this->koha->post('/checkins', $body, ['confirmation' => $token]));
            }
            if ($e->status === 403) {
                $why = $this->explain(array_keys(array_filter((array) ($e->data['blockers'] ?? []))));
                $this->fail('رفض Koha الإرجاع: '.($why ?: $e->getMessage()));
            }
            $this->fail('Koha: '.$e->getMessage());
        }
    }

    public function renew(Loan $loan): array
    {
        $id = $loan->koha_checkout_id ?: $this->fail('هذه الإعارة غير مسجلة في Koha.');
        $check = $this->call(fn () => $this->koha->get("/checkouts/{$id}/allows_renewal"));
        if (isset($check['allows_renewal']) && ! $check['allows_renewal']) {
            $this->fail('رفض Koha التجديد: '.$this->explain([(string) ($check['error'] ?? '')]));
        }
        $this->call(fn () => $this->koha->post("/checkouts/{$id}/renewal"));

        return $this->call(fn () => $this->koha->get("/checkouts/{$id}"));
    }

    public function placeHold(LibraryMember $member, Book $book): array
    {
        $body = [
            'patron_id' => $this->patronId($member),
            'biblio_id' => $this->biblioId($book),
            'pickup_library_id' => KohaSettings::library() ?: $this->fail('حدد رمز المكتبة (الفرع) في إعدادات الربط مع Koha.'),
        ];

        return $this->call(fn () => $this->koha->post('/holds', $body));
    }

    public function cancelHold(Hold $hold): void
    {
        if ($hold->koha_hold_id) {
            try {
                $this->koha->delete("/holds/{$hold->koha_hold_id}");
            } catch (KohaError $e) {
                if ($e->status !== 404) { // already gone in Koha
                    $this->fail('Koha: '.$e->getMessage());
                }
            }
        }
    }

    /** The reader's Koha patron id, looked up once by card number (or e-mail), or added if allowed. */
    public function patronId(LibraryMember $member): int
    {
        if ($id = $this->findPatron($member)) {
            return $id;
        }
        $byEmail = KohaSettings::get('koha_match') === 'email';
        $value = $byEmail ? ($member->email ?: $member->user?->email) : $member->card_number;
        if (KohaSettings::get('koha_create_patrons') !== '1') {
            $this->fail("القارئ «{$member->name}» غير مسجل في Koha (".($byEmail ? 'البريد' : 'رقم البطاقة')." {$value}). سجّله في Koha أولاً.");
        }
        $patron = $this->call(fn () => $this->koha->post('/patrons', array_filter([
            'surname' => $member->name,
            'cardnumber' => $member->card_number,
            'email' => $member->email ?: $member->user?->email,
            'phone' => $member->phone,
            'library_id' => KohaSettings::library() ?: $this->fail('حدد رمز المكتبة (الفرع) في إعدادات الربط مع Koha.'),
            'category_id' => KohaSettings::categories()[$member->type] ?? 'ST',
        ])));
        $member->forceFill(['koha_patron_id' => $patron['patron_id']])->saveQuietly();

        return (int) $patron['patron_id'];
    }

    /** The reader's Koha patron, found (never added) by card number or e-mail, and remembered. */
    public function findPatron(LibraryMember $member): ?int
    {
        if ($member->koha_patron_id) {
            return $member->koha_patron_id;
        }
        $byEmail = KohaSettings::get('koha_match') === 'email';
        $value = $byEmail ? ($member->email ?: $member->user?->email) : $member->card_number;
        if (! $value || str_starts_with($value, 'tmp-')) {
            return null;
        }
        $found = $this->call(fn () => $this->koha->get('/patrons', ['q' => KohaClient::q([$byEmail ? 'email' : 'cardnumber' => $value])]));
        if (! $found) {
            return null;
        }
        $member->forceFill(['koha_patron_id' => $found[0]['patron_id']])->saveQuietly();

        return (int) $found[0]['patron_id'];
    }

    /** The copy's Koha item id, found by barcode; a copy Koha has not seen is sent to it first. */
    public function itemId(BookCopy $copy): int
    {
        if ($copy->koha_item_id) {
            return $copy->koha_item_id;
        }
        $found = $this->call(fn () => $this->koha->get('/items', ['q' => KohaClient::q(['external_id' => $copy->barcode])]));
        if ($found) {
            $copy->forceFill(['koha_item_id' => $found[0]['item_id']])->saveQuietly();
            if (! $copy->book->koha_biblio_id && ! empty($found[0]['biblio_id'])) {
                $copy->book->forceFill(['koha_biblio_id' => $found[0]['biblio_id']])->saveQuietly();
            }

            return (int) $found[0]['item_id'];
        }
        if (! KohaSettings::push()) {
            $this->fail("النسخة {$copy->barcode} غير موجودة في Koha.");
        }
        $this->pushBook($copy->book);

        return (int) ($copy->fresh()->koha_item_id ?: $this->fail("تعذّر إضافة النسخة {$copy->barcode} إلى Koha."));
    }

    public function biblioId(Book $book): int
    {
        if ($book->koha_biblio_id) {
            return $book->koha_biblio_id;
        }
        if (! KohaSettings::push()) {
            $this->fail("الكتاب «{$book->title}» غير موجود في Koha.");
        }
        $this->pushBook($book);

        return (int) $book->fresh()->koha_biblio_id;
    }

    /**
     * Sends a book catalogued in FileNest to Koha: the record (added, or replaced when it was
     * edited here) and every copy Koha does not have yet. Records Koha keeps in UNIMARC are
     * not replaced, so a MARC21 rewrite never lands in a UNIMARC catalogue.
     */
    public function pushBook(Book $book, bool $replace = false): void
    {
        $book->loadMissing('copies');
        $stored = $book->marc_xml ? (MarcReader::xmlString($book->marc_xml)[0] ?? null) : null;

        if (! $book->koha_biblio_id || ($replace && ! $stored?->isUnimarc())) {
            $record = MarcMapper::fromBook($book);
            $record->remove('952', '995');
            $xml = MarcWriter::xml($record, true);
            if (! $book->koha_biblio_id) {
                $res = $this->call(fn () => $this->koha->sendMarc('POST', '/biblios', $xml));
                $id = $res['id'] ?? $res['biblio_id'] ?? $this->fail('لم يُرجع Koha رقم السجل الجديد.');
                $book->forceFill(['koha_biblio_id' => $id])->saveQuietly();
            } else {
                $this->call(fn () => $this->koha->sendMarc('PUT', "/biblios/{$book->koha_biblio_id}", $xml));
            }
        }

        foreach ($book->copies->whereNull('koha_item_id') as $copy) {
            $found = $this->call(fn () => $this->koha->get('/items', ['q' => KohaClient::q(['external_id' => $copy->barcode])]));
            $item = $found[0] ?? $this->call(fn () => $this->koha->post("/biblios/{$book->koha_biblio_id}/items", array_filter([
                'external_id' => $copy->barcode,
                'home_library_id' => KohaSettings::library() ?: null,
                'holding_library_id' => KohaSettings::library() ?: null,
                'item_type_id' => KohaSettings::get('koha_item_type') ?: null,
                'callnumber' => $book->call_number ?: $copy->shelf,
            ])));
            $copy->forceFill(['koha_item_id' => $item['item_id']])->saveQuietly();
        }
    }

    /** Runs a Koha call; its refusal reaches the desk as a validation message. */
    private function call(callable $fn): array
    {
        try {
            return (array) $fn();
        } catch (KohaError $e) {
            $code = $e->errorCode();
            $this->fail('Koha: '.($code && isset(self::REASONS[$code]) ? self::REASONS[$code] : $e->getMessage()));
        }
    }

    private function explain(array $codes): string
    {
        return implode('، ', array_map(fn ($c) => self::REASONS[$c] ?? $c, array_filter($codes)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
