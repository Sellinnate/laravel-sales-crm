<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Note;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return ['body' => fake()->sentence()];
    }
}
