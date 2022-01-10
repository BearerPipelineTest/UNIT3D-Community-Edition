<?php

/* @var \Illuminate\Database\Eloquent\Factory $factory */

namespace Database\Factories;

use App\Models\Option;
use App\Models\Poll;
use Illuminate\Database\Eloquent\Factories\Factory;

class OptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Option::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'poll_id' => fn () => Poll::factory()->create()->id,
            'name'    => $this->faker->name(),
            'votes'   => $this->faker->randomNumber(),
        ];
    }
}
