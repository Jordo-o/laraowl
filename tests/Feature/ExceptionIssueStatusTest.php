<?php

use App\Models\Project;
use App\Models\User;
use App\Services\RecordService;
use Inertia\Testing\AssertableInertia;

test('resolving the issue updates exception details and preserves the original event', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->for($team)->create();
    $issue = $project->issues()->create([
        'hash' => 'exception-hash',
        'type' => 'exception',
        'title' => 'RuntimeException',
        'message' => 'Example exception',
        'status' => 'open',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $record = $project->records()->create([
        'issue_id' => $issue->id,
        'type' => 'exception',
        'fingerprint' => $issue->hash,
        'payload' => ['class' => 'RuntimeException', 'handled' => false],
        'created_at' => now(),
    ]);

    $parameters = ['current_team' => $team, 'project' => $project];
    $exceptionUrl = route('exceptions.show', [...$parameters, 'hash' => $issue->hash]);

    $this->withoutVite()->actingAs($user)->from($exceptionUrl)
        ->patch(route('issues.update', [...$parameters, 'issue' => $issue]), ['status' => 'resolved'])
        ->assertRedirect($exceptionUrl);

    $this->get($exceptionUrl)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/exceptions/show')
            ->where('issue.id', $issue->id)
            ->where('issue.status', 'resolved'));

    expect($issue->refresh()->resolved_at)->not->toBeNull()
        ->and($record->refresh()->payload['handled'])->toBeFalse();
});

test('legacy fingerprints resolve only to an unambiguous issue in their project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    $makeIssue = fn (Project $owner, string $hash, string $status) => $owner->issues()->create([
        'hash' => $hash,
        'type' => 'exception',
        'title' => 'RuntimeException',
        'message' => 'Example exception',
        'status' => $status,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $resolved = $makeIssue($project, 'resolved-at-location', 'resolved');
    $ignored = $makeIssue($project, 'ignored-at-location', 'ignored');
    $foreign = $makeIssue($otherProject, 'foreign', 'open');

    foreach ([
        ['unique-legacy', $resolved],
        ['unique-legacy', $resolved],
        ['ambiguous-legacy', $resolved],
        ['ambiguous-legacy', $ignored],
        ['foreign', $foreign],
    ] as [$fingerprint, $issue]) {
        $issue->project->records()->create([
            'issue_id' => $issue->id,
            'type' => 'exception',
            'fingerprint' => $fingerprint,
            'payload' => ['class' => 'RuntimeException'],
            'created_at' => now(),
        ]);
    }

    $issues = app(RecordService::class)->getExceptionIssues($project, [
        'unique-legacy',
        'ambiguous-legacy',
        'ignored-at-location',
        'foreign',
    ]);

    expect($issues->get('unique-legacy')?->status)->toBe('resolved')
        ->and($issues->get('ignored-at-location')?->status)->toBe('ignored')
        ->and($issues->has('ambiguous-legacy'))->toBeFalse()
        ->and($issues->has('foreign'))->toBeFalse();
});
