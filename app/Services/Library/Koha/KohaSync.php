<?php

namespace App\Services\Library\Koha;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\User;
use App\Services\Library\LibraryNotifier;
use App\Services\Library\Marc\MarcMapper;
use App\Services\Library\Marc\MarcReader;
use App\Services\Library\Marc\MarcRecord;
use App\Services\Library\Marc\MarcWriter;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Brings Koha's state into FileNest: the catalogue and its copies, then the open loans and
 * holds, whoever made them (this desk, Koha's own staff pages or its OPAC). Local records are
 * mirrors: Koha decides, FileNest shows it in its pages, its app and its map.
 */
class KohaSync
{
    /** Set while pulling, so the records written here are not pushed back to Koha. */
    public static bool $pulling = false;

    private array $counts = ['books' => 0, 'copies' => 0, 'loans' => 0, 'returned' => 0, 'holds' => 0, 'readers' => 0];

    public function __construct(private KohaClient $koha, private LibraryNotifier $notifier) {}

    /**
     * A quick refresh while a page waits: when Koha is on, run it; if Koha is slow or down the
     * page still opens with what is already mirrored here.
     */
    public static function quietly(callable $fn): void
    {
        if (! KohaSettings::enabled()) {
            return;
        }
        $was = KohaClient::$timeout;
        KohaClient::$timeout = 6;
        try {
            $fn(app(self::class));
        } catch (Throwable $e) {
            report($e);
        } finally {
            KohaClient::$timeout = $was;
        }
    }

    public function run(bool $full = false): array
    {
        $last = KohaSettings::state('koha_last_sync');
        $since = (! $full && ! empty($last['ok_at'])) ? Carbon::parse($last['ok_at'])->subDay()->format('Y-m-d H:i:s') : null;
        $started = now();
        self::$pulling = true;
        try {
            $this->catalogue($since);
            $this->items($since);
            $this->deletions($since);
            $this->loans();
            $this->holds();
            KohaSettings::setState('koha_last_sync', ['at' => $started->toIso8601String(), 'ok_at' => $started->toIso8601String(), 'full' => $since === null, 'counts' => $this->counts, 'error' => null]);
        } catch (Throwable $e) {
            KohaSettings::setState('koha_last_sync', ['at' => $started->toIso8601String(), 'ok_at' => $last['ok_at'] ?? null, 'counts' => $this->counts, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        } finally {
            self::$pulling = false;
        }

        return $this->counts;
    }

    /** One reader's loans and holds, fresh from Koha (opening «استعاراتي», picking a reader at the desk). */
    public function member(LibraryMember $member): void
    {
        if (! $member->koha_patron_id && ! app(KohaBridge::class)->findPatron($member)) {
            return;
        }
        self::$pulling = true;
        try {
            $this->loans($member);
            $this->holds($member);
        } finally {
            self::$pulling = false;
        }
    }

    /** One copy's loan and its book's holds, fresh from Koha (a scan at the desk, a return). */
    public function copy(BookCopy $copy): void
    {
        if (! $copy->koha_item_id) {
            return;
        }
        self::$pulling = true;
        try {
            $this->loans(null, $copy);
            if ($copy->book) {
                $this->holds(null, $copy->book);
            }
        } finally {
            self::$pulling = false;
        }
    }

    /** Records changed since the last run, a page of MARCXML at a time (Koha 23.05+ lists records). */
    private function catalogue(?string $since): void
    {
        if (! KohaSettings::can('/biblios')) {
            return; // older Koha: records are fetched one by one as their copies arrive
        }
        $query = $since ? ['q' => KohaClient::q(['timestamp' => ['>=' => $since]])] : [];
        for ($page = 1; ; $page++) {
            $xml = $this->koha->marc('/biblios', $query + ['_page' => $page, '_per_page' => 100]);
            $records = MarcReader::xmlString($xml);
            foreach ($records as $r) {
                $this->biblio($r);
            }
            if (count($records) < 100) {
                return;
            }
        }
    }

    private function items(?string $since): void
    {
        $query = $since ? ['q' => KohaClient::q(['timestamp' => ['>=' => $since]])] : [];
        foreach ($this->koha->each('/items', $query) as $item) {
            $this->item($item);
        }
    }

    private function deletions(?string $since): void
    {
        if (! $since) {
            return;
        }
        $q = ['q' => KohaClient::q(['deleted_on' => ['>=' => $since]])];
        if (KohaSettings::can('/deleted/items')) {
            foreach ($this->koha->each('/deleted/items', $q) as $item) {
                BookCopy::where('koha_item_id', $item['item_id'])->whereDoesntHave('openLoan')->delete();
            }
        }
        if (KohaSettings::can('/deleted/biblios')) {
            foreach ($this->koha->each('/deleted/biblios', $q) as $biblio) {
                Book::where('koha_biblio_id', $biblio['biblio_id'])->whereDoesntHave('copies')->delete();
            }
        }
    }

    /** Koha's open loans become the local ones; local mirrors Koha no longer has were returned. */
    private function loans(?LibraryMember $only = null, ?BookCopy $item = null): void
    {
        $seen = [];
        $query = $only ? ['patron_id' => $only->koha_patron_id] : ($item ? ['q' => KohaClient::q(['item_id' => $item->koha_item_id])] : []);
        foreach ($this->koha->each('/checkouts', $query) as $co) {
            $copy = $this->copyFor((int) $co['item_id']);
            $member = $only ?? $this->memberFor((int) $co['patron_id']);
            if (! $copy || ! $member) {
                continue;
            }
            $loan = Loan::where('koha_checkout_id', $co['checkout_id'])->first()
                ?? Loan::where('copy_id', $copy->id)->whereNull('returned_at')->whereNull('koha_checkout_id')->first()
                ?? new Loan;
            $loan->fill([
                'copy_id' => $copy->id,
                'member_id' => $member->id,
                'issued_at' => $loan->issued_at ?? $this->date($co['checkout_date'] ?? null) ?? now(),
                'due_at' => $this->date($co['due_date'] ?? null) ?? now(),
                'renewals' => (int) ($co['renewals_count'] ?? $co['renewals'] ?? 0),
                'koha_checkout_id' => $co['checkout_id'],
            ]);
            $this->counts['loans'] += $loan->isDirty() ? 1 : 0;
            $loan->save();
            $seen[] = $loan->id;
            if ($copy->status !== BookCopy::LOANED) {
                $copy->update(['status' => BookCopy::LOANED]);
            }
        }

        Loan::whereNull('returned_at')->whereNotNull('koha_checkout_id')->whereNotIn('id', $seen)
            ->when($only, fn ($q) => $q->where('member_id', $only->id))
            ->when($item, fn ($q) => $q->where('copy_id', $item->id))
            ->with('copy')->each(function (Loan $loan) {
                $loan->update(['returned_at' => now(), 'fine_amount' => 0]);
                $this->counts['returned']++;
                if ($loan->copy?->status === BookCopy::LOANED) {
                    $loan->copy->update(['status' => BookCopy::AVAILABLE]);
                }
            });

        if (! $only && ! $item) {
            BookCopy::where('status', BookCopy::LOANED)->whereNotNull('koha_item_id')->whereDoesntHave('openLoan')
                ->update(['status' => BookCopy::AVAILABLE]);
        }
    }

    /** Koha's holds: «W» (waiting at the desk) is a copy set aside for the reader. */
    private function holds(?LibraryMember $only = null, ?Book $title = null): void
    {
        $seen = [];
        $query = $only ? ['patron_id' => $only->koha_patron_id] : ($title?->koha_biblio_id ? ['biblio_id' => $title->koha_biblio_id] : []);
        if ($title && ! $title->koha_biblio_id) {
            return;
        }
        foreach ($this->koha->each('/holds', $query) as $h) {
            $book = $this->bookFor($h['biblio_id'] ?? null);
            $member = $only ?? $this->memberFor((int) $h['patron_id']);
            if (! $book || ! $member) {
                continue;
            }
            $ready = ($h['status'] ?? null) === 'W';
            $copy = $ready && ! empty($h['item_id']) ? $this->copyFor((int) $h['item_id']) : null;
            $hold = Hold::where('koha_hold_id', $h['hold_id'])->first()
                ?? Hold::open()->where('book_id', $book->id)->where('member_id', $member->id)->whereNull('koha_hold_id')->first()
                ?? new Hold;
            $wasReady = $hold->exists && $hold->status === Hold::READY;
            $hold->fill([
                'book_id' => $book->id,
                'member_id' => $member->id,
                'copy_id' => $copy?->id,
                'status' => $ready ? Hold::READY : Hold::WAITING,
                'ready_at' => $ready ? ($this->date($h['waiting_date'] ?? null) ?? now()) : null,
                'expires_at' => $ready ? $this->date($h['expiration_date'] ?? null) : null,
                'koha_hold_id' => $h['hold_id'],
            ]);
            $this->counts['holds'] += $hold->isDirty() ? 1 : 0;
            $hold->save();
            $seen[] = $hold->id;
            if ($copy && $copy->status === BookCopy::AVAILABLE) {
                $copy->update(['status' => BookCopy::ON_HOLD]);
            }
            if ($ready && ! $wasReady) {
                $this->notifier->notify($member, 'hold_ready', 'كتابك المحجوز جاهز',
                    "كتاب \"{$book->title}\" جاهز للاستلام من المكتبة".($copy ? " (النسخة {$copy->barcode})" : '').'.');
            }
        }

        Hold::open()->whereNotNull('koha_hold_id')->whereNotIn('id', $seen)
            ->when($only, fn ($q) => $q->where('member_id', $only->id))
            ->when($title, fn ($q) => $q->where('book_id', $title->id))
            ->with('copy')->each(function (Hold $hold) {
                $taken = Loan::where('member_id', $hold->member_id)->where('issued_at', '>=', now()->subDays(7))
                    ->whereHas('copy', fn ($c) => $c->where('book_id', $hold->book_id))->exists();
                $hold->update(['status' => $taken ? Hold::FULFILLED : Hold::CANCELLED]);
                if ($hold->copy?->status === BookCopy::ON_HOLD && ! Hold::where('copy_id', $hold->copy_id)->where('status', Hold::READY)->exists()) {
                    $hold->copy->update(['status' => BookCopy::AVAILABLE]);
                }
            });
    }

    /** A Koha record into the catalogue. Books FileNest sent to Koha (001 «FN…») are matched back. */
    private function biblio(MarcRecord $r, ?int $id = null): ?Book
    {
        $id ??= (int) ($r->sub('999', 'c') ?: ($r->isUnimarc() ? $r->sub('090', 'a') : 0));
        if (! $id) {
            return null;
        }
        $fields = array_filter(MarcMapper::toBook($r)['book'], fn ($v) => $v !== null && $v !== '');
        $control = (string) $r->control('001');

        $book = Book::where('koha_biblio_id', $id)->first();
        if (! $book && preg_match('/^FN(\d+)$/', $control, $m)) {
            $book = Book::whereNull('koha_biblio_id')->find((int) $m[1]);
        }
        if (! $book && $control !== '') {
            $book = Book::whereNull('koha_biblio_id')->where('marc_control', $control)->first();
        }
        if (! $book && ! empty($fields['isbn'])) {
            $book = Book::whereNull('koha_biblio_id')->where('isbn', $fields['isbn'])->first();
        }
        $book ??= new Book;

        $r->remove('952', '995');
        $book->fill($fields + ['title' => $book->title ?: 'بلا عنوان']);
        $book->fill(['koha_biblio_id' => $id, 'marc_xml' => MarcWriter::xml($r, true)]);
        if (preg_match('/^FN\d+$/', (string) $book->marc_control)) {
            $book->marc_control = $book->getOriginal('marc_control');
        }
        $this->counts['books'] += $book->isDirty(array_keys($fields)) ? 1 : 0;
        $book->save();

        return $book;
    }

    private function bookFor($biblioId): ?Book
    {
        if (! $biblioId) {
            return null;
        }

        return Book::where('koha_biblio_id', $biblioId)->first() ?? $this->fetchBiblio((int) $biblioId);
    }

    private function fetchBiblio(int $id): ?Book
    {
        try {
            $r = MarcReader::xmlString($this->koha->marc("/biblios/{$id}"))[0] ?? null;
        } catch (KohaError $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }

        return $r ? $this->biblio($r, $id) : null;
    }

    private function item(array $it): ?BookCopy
    {
        $book = $this->bookFor($it['biblio_id'] ?? null);
        if (! $book) {
            return null;
        }
        $barcode = trim((string) ($it['external_id'] ?? '')) ?: 'KOHA'.$it['item_id'];
        $copy = BookCopy::where('koha_item_id', $it['item_id'])->first()
            ?? BookCopy::where('barcode', $barcode)->whereNull('koha_item_id')->first()
            ?? new BookCopy(['status' => BookCopy::AVAILABLE]);
        if (! $copy->exists && BookCopy::where('barcode', $barcode)->exists()) {
            $barcode = 'KOHA'.$it['item_id']; // the barcode belongs to another Koha item here
        }

        $status = $copy->status;
        if (! empty($it['lost_status'])) {
            $status = BookCopy::LOST;
        } elseif (! empty($it['withdrawn']) || ! empty($it['damaged_status'])) {
            $status = BookCopy::DAMAGED;
        } elseif (in_array($status, [BookCopy::LOST, BookCopy::DAMAGED], true)) {
            $status = BookCopy::AVAILABLE;
        }

        $copy->fill([
            'book_id' => $book->id,
            'barcode' => $barcode,
            'shelf' => ($it['location'] ?? null) ?: ($it['callnumber'] ?? null) ?: $copy->shelf,
            'status' => $status,
            'koha_item_id' => $it['item_id'],
        ]);
        $this->counts['copies'] += $copy->isDirty() ? 1 : 0;
        $copy->save();
        if (! $book->call_number && ! empty($it['callnumber'])) {
            $book->update(['call_number' => $it['callnumber']]);
        }

        return $copy;
    }

    private function copyFor(int $itemId): ?BookCopy
    {
        if ($copy = BookCopy::where('koha_item_id', $itemId)->first()) {
            return $copy;
        }
        try {
            return $this->item($this->koha->get("/items/{$itemId}"));
        } catch (KohaError $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /** A Koha patron as a FileNest reader: matched by card number or e-mail, or added. */
    private function memberFor(int $patronId): ?LibraryMember
    {
        if ($m = LibraryMember::where('koha_patron_id', $patronId)->first()) {
            return $m;
        }
        try {
            $p = $this->koha->get("/patrons/{$patronId}");
        } catch (KohaError $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        $card = trim((string) ($p['cardnumber'] ?? ''));
        $email = trim((string) ($p['email'] ?? ''));
        $m = ($card !== '' ? LibraryMember::whereNull('koha_patron_id')->where('card_number', $card)->first() : null)
            ?? ($email !== '' ? LibraryMember::whereNull('koha_patron_id')->where('email', $email)->first() : null);
        if (! $m) {
            $type = array_search($p['category_id'] ?? null, KohaSettings::categories(), true) ?: 'student';
            $user = $email !== '' ? User::where('email', $email)->whereDoesntHave('libraryMember')->first() : null;
            $m = LibraryMember::create([
                'user_id' => $user?->id,
                'name' => trim(($p['firstname'] ?? '').' '.($p['surname'] ?? '')) ?: 'قارئ Koha '.$patronId,
                'card_number' => ($card !== '' && ! LibraryMember::where('card_number', $card)->exists()) ? $card : 'K'.$patronId,
                'email' => $email ?: null,
                'phone' => $p['mobile'] ?? $p['phone'] ?? null,
                'type' => $type,
            ]);
            $this->counts['readers']++;
        }
        $m->forceFill(['koha_patron_id' => $patronId])->saveQuietly();

        return $m;
    }

    private function date(?string $v): ?Carbon
    {
        return KohaClient::date($v);
    }
}
