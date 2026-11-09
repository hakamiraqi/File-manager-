<?php

namespace App\Services\Exams;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A standby server for the hall: a second computer on the network takes, every minute, what
 * changed on the main exam server (accounts, exams, attempts, answers…). If the main one fails,
 * point the hall at the standby and the exam goes on from the last minute. Rows only: uploaded
 * files (photos, answer files, PDFs) are copied by the regular backups.
 */
class Mirror
{
    /** In the order they are applied, parents before children. */
    public const TABLES = [
        'users', 'exam_groups', 'exam_group_user', 'exam_computers', 'exam_bank_categories', 'exams', 'exam_exam_group',
        'exam_questions', 'exam_choices', 'exam_overrides', 'exam_markers', 'exam_attempts', 'exam_answers', 'exam_events',
        'exam_attendance', 'exam_answer_logs', 'exam_announcements', 'exam_marks', 'exam_appeals', 'exam_osce_stations', 'exam_osce_marks',
    ];

    /** Rows changed since a moment (every row of a table without timestamps). */
    public function changes(?Carbon $since): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $column = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : (Schema::hasColumn($table, 'created_at') ? 'created_at' : null);
            $q = DB::table($table);
            if ($since && $column) {
                $q->where($column, '>=', $since);
            }
            $out[$table] = $q->orderBy(Schema::hasColumn($table, 'id') ? 'id' : $column ?? DB::raw('1'))->limit(20000)->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    /** Puts the rows in, replacing those with the same id. */
    public function apply(array $tables): array
    {
        $counts = [];
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($tables, &$counts) {
                foreach (self::TABLES as $table) {
                    $rows = $tables[$table] ?? [];
                    if (! $rows || ! Schema::hasTable($table)) {
                        continue;
                    }
                    $columns = array_flip(Schema::getColumnListing($table));
                    $rows = array_map(fn ($r) => array_intersect_key($r, $columns), $rows);
                    foreach (array_chunk($rows, 200) as $chunk) {
                        isset($columns['id'])
                            ? DB::table($table)->upsert($chunk, ['id'], array_values(array_diff(array_keys($chunk[0]), ['id'])))
                            : DB::table($table)->insertOrIgnore($chunk);
                    }
                    $counts[$table] = count($rows);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $counts;
    }

    /** On the standby: take what changed on the main server since the last time. */
    public function pull(): array
    {
        $from = rtrim((string) Setting::get('mirror_from'), '/');
        $key = Setting::get('mirror_from_key') ? Crypt::decryptString((string) Setting::get('mirror_from_key')) : null;
        if ($from === '' || ! $key) {
            return ['skipped' => true];
        }
        $last = Setting::get('mirror_last_at');
        $started = now();
        try {
            $res = Http::timeout(50)->withHeaders(['X-Mirror-Key' => $key])->acceptJson()
                ->get($from.'/mirror/changes', ['since' => $last ? Carbon::parse($last)->subSeconds(10)->toIso8601String() : null]);
            if (! $res->successful()) {
                throw new \RuntimeException('HTTP '.$res->status());
            }
            $counts = $this->apply($res->json('tables') ?? []);
            Setting::setMany(['mirror_last_at' => $res->json('now') ?? $started->toIso8601String(), 'mirror_last_ok' => now()->toIso8601String(),
                'mirror_last_error' => '', 'mirror_last_rows' => (string) array_sum($counts)]);

            return ['rows' => array_sum($counts)];
        } catch (\Throwable $e) {
            Setting::set('mirror_last_error', now()->toIso8601String().' — '.mb_substr($e->getMessage(), 0, 300));

            return ['error' => $e->getMessage()];
        }
    }
}
