<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

test('moving a project retains assignments to destination team members', function () {
    $owner = User::factory()->create();
    $source = $owner->currentTeam;
    $destination = Team::factory()->create();
    $destination->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $sharedMember = User::factory()->create();
    $sourceOnlyMember = User::factory()->create();
    $source->members()->attach([$sharedMember->id, $sourceOnlyMember->id], ['role' => TeamRole::Member->value]);
    $destination->members()->attach($sharedMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($source)->create();

    $issues = collect([$sharedMember, $sourceOnlyMember])->map(fn (User $user) => $project->issues()->create([
        'hash' => md5((string) $user->id),
        'type' => 'exception',
        'title' => 'Example exception',
        'message' => 'Example exception',
        'status' => 'open',
        'assigned_to' => $user->id,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]));

    $this->actingAs($owner)
        ->patch(route('projects.transfer', ['current_team' => $source, 'project' => $project]), [
            'team_id' => $destination->id,
        ])
        ->assertRedirect(route('project.settings', ['current_team' => $destination, 'project' => $project]));

    expect((int) $project->refresh()->team_id)->toBe($destination->id)
        ->and((int) $issues[0]->refresh()->assigned_to)->toBe($sharedMember->id)
        ->and($issues[1]->refresh()->assigned_to)->toBeNull();
});
