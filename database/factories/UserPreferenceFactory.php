<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
final class UserPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'key' => 'table_columns:'.$this->faker->word(),
            'value' => [],
        ];
    }

    /**
     * Preferencia global del usuario, sin acotar a un equipo.
     */
    public function global(): self
    {
        return $this->state(fn (array $attributes): array => ['team_id' => null]);
    }
}
