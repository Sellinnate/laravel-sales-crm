<?php

use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * A user of the workbench app.
 *
 * @param  array<string, mixed>  $attributes
 */
function crmUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

/**
 * The default pipeline of the shared CRM (or of a team).
 */
function crmPipeline(?Team $team = null): Pipeline
{
    return app(DefaultPipeline::class)->ensureFor(\Sellinnate\SalesCrm\Workspaces\Workspace::of($team));
}

/**
 * A stage of a pipeline by its name.
 */
function crmStage(Pipeline $pipeline, string $name): Stage
{
    return $pipeline->stages()->where('name', $name)->firstOrFail();
}

/**
 * Switch the CRM to workspaces read from the teams of the workbench users.
 */
function useTeams(): void
{
    config([
        'sales-crm.workspaces.resolver' => \Sellinnate\SalesCrm\Workspaces\RelationWorkspaceResolver::class,
        'sales-crm.workspaces.model' => Team::class,
        'sales-crm.workspaces.user_relation' => 'teams',
        'sales-crm.workspaces.members_relation' => 'users',
    ]);

    app()->forgetScopedInstances();
}

/**
 * A team with members.
 */
function crmTeam(string $name, User ...$members): Team
{
    $team = Team::query()->create(['name' => $name]);
    $team->users()->attach(array_map(fn (User $user): int => $user->id, $members));

    return $team;
}
