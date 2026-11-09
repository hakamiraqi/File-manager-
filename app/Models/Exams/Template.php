<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** A teacher's saved exam settings: the questions and the title are never part of it. */
class Template extends Model
{
    /** The settings a template carries (and the editor fills in when it is applied). */
    public const KEYS = [
        'duration_minutes', 'max_attempts', 'grading_method', 'pass_mark', 'shuffle_questions', 'shuffle_choices',
        'show_results', 'show_answers', 'is_practice', 'navigation', 'calculator', 'resume_code_required', 'bind_computers',
        'protect_content', 'variants', 'anonymous_marking', 'appeals_open', 'double_marking', 'seb_required', 'allowed_ips',
    ];

    protected $table = 'exam_templates';

    protected $fillable = ['user_id', 'name', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
