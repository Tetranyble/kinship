<?php

namespace Tetranyble\Kinship\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tetranyble\Kinship\Contracts\Workspace;
use Tetranyble\Kinship\Models\Role;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    /** @var class-string<Role> */
    protected $model = Role::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'order' => $this->faker->numberBetween(1, 100),
            'is_system' => false,
        ];
    }

    public function forWorkspace(Workspace|int|string $workspace): static
    {
        $identifier = $workspace instanceof Workspace
            ? $workspace->getWorkspaceIdentifier()
            : $workspace;

        return $this->state(function () use ($identifier): array {
            $configuration = app(WorkspaceConfiguration::class);

            return [
                $configuration->roleForeignKey() => $configuration->scopeValue($identifier, true),
            ];
        });
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
