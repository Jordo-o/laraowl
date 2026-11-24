<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\SecurityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ApplyCloudflareSecurityBlock implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private int $projectId,
        private int $recordId,
        private string $ip,
        private string $risk,
    ) {}

    public function handle(): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project || ! SecurityService::meetsThreshold(
            $this->risk,
            data_get($project->settings, 'security_response.cloudflare_block_level', 'off'),
        )) {
            return;
        }

        $record = $project->records()->ofType('request')->find($this->recordId);

        if (! $record || $record->created_at->lt(now()->subHour())
            || ! filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return;
        }

        $hostname = parse_url($project->url ?? '', PHP_URL_HOST);
        $token = data_get($project->settings, 'cloudflare.api_token');
        $zone = data_get($project->settings, 'cloudflare.zone_id');

        if (! is_string($hostname) || ! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! $token || ! is_string($zone) || ! preg_match('/\A[a-f0-9]{32}\z/i', $zone)) {
            $this->saveResult('failed');

            return;
        }

        $ip = inet_ntop(inet_pton($this->ip));
        $hostname = strtolower($hostname);
        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone}/rulesets";
        $client = Http::withToken($token)->acceptJson()->timeout(8);
        $prefix = "laraowl_auto_{$this->projectId}_";
        $reference = $prefix.substr(hash('sha256', "$hostname:$ip"), 0, 24);

        $expiresAt = Cache::lock("laraowl:cloudflare:ruleset:$zone", 120)->block(5, function () use ($client, $endpoint, $prefix, $reference, $hostname, $ip) {
            $phase = 'http_request_firewall_custom';
            $response = $client->get("$endpoint/phases/$phase/entrypoint");
            $ruleset = $response->notFound() ? null : $response->throw()->json('result');
            $rules = $ruleset['rules'] ?? [];
            $current = now()->timestamp;

            foreach ($rules as $rule) {
                if (! str_starts_with($rule['ref'] ?? '', $prefix)
                    || ! preg_match('/\ALaraOwl automatic block until ([0-9]+)\z/', $rule['description'] ?? '', $match)) {
                    continue;
                }

                $expiry = (int) $match[1];

                if ($expiry <= $current) {
                    $client->delete("$endpoint/{$ruleset['id']}/rules/{$rule['id']}")->throw();
                } elseif ($rule['ref'] === $reference && ($rule['enabled'] ?? true)) {
                    return $expiry;
                }
            }

            $expiry = now()->addHour()->timestamp;
            $rule = [
                'ref' => $reference,
                'action' => 'block',
                'description' => "LaraOwl automatic block until $expiry",
                'expression' => "(ip.src eq $ip and http.host eq \"$hostname\" and http.request.timestamp.sec lt $expiry)",
                'enabled' => true,
            ];

            $created = $ruleset
                ? $client->post("$endpoint/{$ruleset['id']}/rules", $rule)
                : $client->post($endpoint, [
                    'name' => 'Zone custom rules',
                    'kind' => 'zone',
                    'phase' => $phase,
                    'rules' => [$rule],
                ]);

            if ($created->throw()->json('success') !== true) {
                throw new RuntimeException('Cloudflare did not confirm creation of the automatic block.');
            }

            return $expiry;
        });

        $this->saveResult('active', now()->setTimestamp($expiresAt)->toIso8601String());
    }

    public function failed(Throwable $exception): void
    {
        $this->saveResult('failed');
    }

    private function saveResult(string $status, ?string $expiresAt = null): void
    {
        $record = Project::query()->find($this->projectId)?->records()->ofType('request')->find($this->recordId);

        if ($record) {
            $record->update(['payload' => [
                ...$record->payload,
                '_security_cloudflare_block' => ['status' => $status, 'expires_at' => $expiresAt],
            ]]);
        }
    }
}
