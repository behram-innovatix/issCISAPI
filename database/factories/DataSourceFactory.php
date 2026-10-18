<?php

namespace Database\Factories;

use App\Enums\SourceStatus;
use App\Models\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(3),
            'type' => 'json-files',
            'platform' => 'edu',
            'tenant' => 'default',
            'config' => ['path' => 'storage/framework/testing/json-files'],
            'schedule' => '0 * * * *',
            'is_active' => true,
            'status' => SourceStatus::New,
        ];
    }

    /**
     * A source on an ISS platform's cis-export API.
     */
    public function issExport(string $platform = 'edu', array $config = []): static
    {
        return $this->state(fn () => [
            'type' => 'iss-export',
            'platform' => $platform,
            'config' => $config + ['base_url' => 'https://edu.iss.test/api', 'token' => 'test-token'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
