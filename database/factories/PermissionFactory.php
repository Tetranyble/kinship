<?php

namespace Tetranyble\Kinship\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tetranyble\Kinship\Models\Permission;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    /** @var class-string<Permission> */
    protected $model = Permission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $resource = $this->faker->unique()->slug(2);

        return [
            'name' => $resource.'.'.$this->faker->randomElement(['index', 'view', 'create', 'update', 'delete']),
            'label' => $this->faker->words(3, true),
            'group' => $this->faker->word(),
        ];
    }
}
