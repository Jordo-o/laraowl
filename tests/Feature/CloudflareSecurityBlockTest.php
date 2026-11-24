<?php

use App\Jobs\ApplyCloudflareSecurityBlock;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

test('a qualifying security detection creates a temporary hostname-scoped Cloudflare rule', function () {
    $zone = str_repeat('a', 32);
    $project = Project::factory()->create([
        'url' => 'https://app.example.com',
        'settings' => [
            'security_response' => ['cloudflare_block_level' => 'high'],
            'cloudflare' => ['api_token' => 'fake-token', 'zone_id' => $zone],
        ],
    ]);
    $record = $project->records()->create(['type' => 'request', 'payload' => [], 'created_at' => now()]);
    $endpoint = "https://api.cloudflare.com/client/v4/zones/$zone/rulesets";
    Http::preventStrayRequests();
    Http::fake([
        "$endpoint/phases/http_request_firewall_custom/entrypoint" => Http::response([
            'success' => true,
            'result' => ['id' => 'example-ruleset', 'rules' => []],
        ]),
        "$endpoint/example-ruleset/rules" => Http::response(['success' => true], 201),
    ]);

    (new ApplyCloudflareSecurityBlock($project->id, $record->id, '1.1.1.1', 'medium'))->handle();
    Http::assertNothingSent();

    (new ApplyCloudflareSecurityBlock($project->id, $record->id, '1.1.1.1', 'high'))->handle();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['action'] === 'block'
        && str_contains($request['expression'], 'ip.src eq 1.1.1.1')
        && str_contains($request['expression'], 'http.host eq "app.example.com"')
        && str_contains($request['expression'], 'http.request.timestamp.sec lt '));

    expect($record->refresh()->payload['_security_cloudflare_block']['status'])->toBe('active');
});
