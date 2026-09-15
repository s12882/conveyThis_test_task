<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$extension, $mimeType] = $this->faker->randomElement([
            ['pdf', 'application/pdf'],
            ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ]);

        return [
            'original_name' => $this->faker->slug().'.'.$extension,
            'stored_path' => 'uploads/'.Str::random(40).'.'.$extension,
            'mime_type' => $mimeType,
            'size_bytes' => $this->faker->numberBetween(1024, 10 * 1024 * 1024),
            'expires_at' => now()->addHours(24),
            'deletion_reason' => null,
            'scan_status' => 'pending',
            'scanned_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function clean(): static
    {
        return $this->state(fn () => [
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);
    }

    public function infected(): static
    {
        return $this->state(fn () => [
            'scan_status' => 'infected',
            'scanned_at' => now(),
        ]);
    }
}
