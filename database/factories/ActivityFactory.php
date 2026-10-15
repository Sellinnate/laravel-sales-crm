<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Activity;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'type' => 'call',
            'title' => 'Call back',
            'due_at' => CarbonImmutable::tomorrow()->setTime(10, 0),
            'has_time' => true,
        ];
    }
}
