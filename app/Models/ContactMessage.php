<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kind', 'name', 'email', 'phone', 'subject', 'message', 'about_type', 'about_id', 'status', 'reply', 'replied_at', 'handled_by', 'ip_address'])]
class ContactMessage extends Model
{
    public const KINDS = [
        'inquiry' => 'استفسار',
        'book_request' => 'طلب كتاب',
        'thesis_request' => 'طلب رسالة أو أطروحة',
        'suggestion' => 'اقتراح',
        'complaint' => 'شكوى',
        'other' => 'أخرى',
    ];

    public const STATUSES = ['new' => 'جديد', 'in_progress' => 'قيد المتابعة', 'closed' => 'مغلق'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Title of the book or thesis the request is about. */
    public function aboutTitle(): ?string
    {
        return match ($this->about_type) {
            'book' => \App\Models\Library\Book::withTrashed()->find($this->about_id)?->title,
            'thesis' => Folder::find($this->about_id)?->thesis_title,
            default => null,
        };
    }
}
