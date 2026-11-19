<?php

namespace App\Http\Controllers\Api\Sis;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Services\Library\Koha\KohaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What SIS may do in the library (its "library" connector): send students as readers, and ask which of them
 * still owe books or fines (براءة الذمة). A reader's card number is the student's university number.
 */
class SisConnectorController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'system' => 'filenest', 'name' => config('app.name'), 'version' => 1,
            'tools' => ['members_sync', 'clearance'],
            'fines_source' => KohaSettings::enabled() ? 'koha' : 'filenest',
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'members' => ['required', 'array', 'max:500'],
            'members.*.card_number' => ['required', 'string', 'max:64'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.stage' => ['nullable', 'string', 'max:50'],
            'members.*.phone' => ['nullable', 'string', 'max:30'],
            'members.*.email' => ['nullable', 'string', 'max:255'],
            'members.*.active' => ['required', 'boolean'],
        ]);
        $created = $updated = 0;
        DB::transaction(function () use ($data, &$created, &$updated) {
            $existing = LibraryMember::whereIn('card_number', collect($data['members'])->pluck('card_number'))->get()->keyBy('card_number');
            foreach ($data['members'] as $m) {
                $email = filter_var($m['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
                $fields = ['name' => $m['name'], 'stage' => $m['stage'] ?? null, 'phone' => $m['phone'] ?? null, 'active' => (bool) $m['active']];
                $member = $existing->get($m['card_number']);
                if ($member) {
                    // a reader the library edited keeps its own email when SIS has none
                    $member->fill($fields + ($email ? ['email' => $email] : []));
                    if ($member->isDirty()) {
                        $member->save();
                        $updated++;
                    }
                } elseif ($m['active']) {
                    LibraryMember::create($fields + ['card_number' => $m['card_number'], 'email' => $email, 'type' => 'student', 'notes' => 'من نظام الطلبة']);
                    $created++;
                }
            }
        });

        return response()->json(['created' => $created, 'updated' => $updated]);
    }

    public function clearance(Request $request): JsonResponse
    {
        $cards = $request->validate(['cards' => ['required', 'array', 'max:1000'], 'cards.*' => ['string', 'max:64']])['cards'];
        $today = now()->startOfDay();
        $members = LibraryMember::whereIn('card_number', $cards)
            ->withCount([
                'loans as active_loans' => fn ($q) => $q->whereNull('returned_at'),
                'loans as overdue_loans' => fn ($q) => $q->whereNull('returned_at')->where('due_at', '<', $today),
            ])
            ->withSum(['loans as closed_fines' => fn ($q) => $q->whereNotNull('returned_at')->whereNull('fine_paid_at')->where('fine_waived', false)], 'fine_amount')
            ->get();

        $out = [];
        foreach ($members as $m) {
            $open = $m->active_loans ? $m->activeLoans()->get()->sum(fn (Loan $l) => $l->currentFine()) : 0;
            $out[$m->card_number] = [
                'active_loans' => (int) $m->active_loans,
                'overdue_loans' => (int) $m->overdue_loans,
                'fines_due' => (int) $m->closed_fines + (int) $open,
            ];
        }

        return response()->json(['members' => (object) $out, 'missing' => array_values(array_diff($cards, array_keys($out)))]);
    }
}
