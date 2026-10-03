<?php

namespace Tetranyble\Kinship\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tetranyble\Kinship\Contracts\Workspace;
use Tetranyble\Kinship\Models\Group;
use Tetranyble\Kinship\Support\WorkspaceConfiguration;

/** @extends Factory<Group> */
class GroupFactory extends Factory
{
    /** @var class-string<Group> */
    protected $model = Group::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(3),
            'label' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
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
                $configuration->groupForeignKey() => $configuration->scopeValue($identifier, true),
            ];
        });
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
