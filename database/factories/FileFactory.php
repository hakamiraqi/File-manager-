<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'folder_id' => null,
            'name' => $this->faker->word().'.txt',
            'disk' => config('filenest.disk'),
            'path' => Str::uuid().'.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => $this->faker->numberBetween(100, 10000),
        ];
    }
}
