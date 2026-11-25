<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use App\Services\CloudflareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function update(Request $request, Team $current_team, Project $project)
    {
        $validated = $request->validate([
            'security_issue_level' => ['sometimes', 'required', 'in:off,low,medium,high,critical'],
            'security_cloudflare_block_level' => ['sometimes', 'required', 'in:off,low,medium,high,critical'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:255'],
            'uptime_monitoring_enabled' => ['sometimes', 'boolean'],
            'uptime_check_interval' => ['nullable', 'integer', 'min:30'],
            'retention_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        // Keep the stored value when the field is omitted so a partial update
        // can't silently switch monitoring off.
        $monitoringEnabled = $request->missing('uptime_monitoring_enabled')
            ? $project->uptime_monitoring_enabled
            : $request->boolean('uptime_monitoring_enabled');

        $attributes = [
            'name' => $request->name,
            'url' => $request->url,
            'uptime_monitoring_enabled' => $monitoringEnabled,
            'uptime_check_interval' => $request->uptime_check_interval ?? 60,
            'retention_days' => $request->retention_days ?? 7,
        ];

        // Clear the stale status so a disabled project no longer reads as
        // "down" and re-enabling it can't fire a false recovery alert.
        if (! $monitoringEnabled && $project->uptime_monitoring_enabled) {
            $attributes['last_uptime_status'] = null;
            $attributes['last_uptime_check_at'] = null;
        }

        DB::transaction(function () use ($project, $attributes, $validated) {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            $settings = $current->settings ?? [];

            if (isset($validated['security_issue_level'])) {
                $settings['security_response']['issue_level'] = $validated['security_issue_level'];
            }

            if (isset($validated['security_cloudflare_block_level'])) {
                $level = $validated['security_cloudflare_block_level'];
                $hostname = parse_url($attributes['url'] ?? '', PHP_URL_HOST);

                if ($level !== 'off' && (! app(CloudflareService::class)->isConfigured($current)
                    || ! is_string($hostname) || ! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                    throw ValidationException::withMessages([
                        'security_cloudflare_block_level' => 'Configure Cloudflare and a valid project URL before enabling automatic blocking.',
                    ]);
                }

                $settings['security_response']['cloudflare_block_level'] = $level;
            }

            $current->update([...$attributes, 'settings' => $settings]);
        });

        if ($request->hasFile('logo')) {
            $project->clearMediaCollection('logo');
            $project->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return back()->with('success', 'Project updated successfully.');
    }

    public function transfer(Request $request, Team $current_team, Project $project)
    {
        $user = $request->user();
        abort_unless($user->ownsTeam($current_team), 403);

        $validated = $request->validate(['team_id' => ['required', 'integer']]);
        $destination = Team::query()->findOrFail($validated['team_id']);
        abort_unless($user->ownsTeam($destination), 403);

        if ($destination->id === $current_team->id) {
            throw ValidationException::withMessages(['team_id' => 'Choose a different team.']);
        }

        DB::transaction(function () use ($current_team, $project, $destination) {
            $lockedProject = $current_team->projects()->lockForUpdate()->findOrFail($project->id);
            $lockedProject->update(['team_id' => $destination->id]);
            $lockedProject->issues()
                ->whereNotNull('assigned_to')
                ->whereNotIn('assigned_to', $destination->members()->select('users.id')->getQuery())
                ->update(['assigned_to' => null]);
        });

        return redirect()->route('project.settings', [
            'current_team' => $destination,
            'project' => $project,
        ])->with('success', 'Project moved successfully.');
    }

    public function destroy(Team $current_team, Project $project)
    {
        $project->delete();

        return redirect()->route('dashboard', ['current_team' => $current_team->slug])
            ->with('success', 'Project deleted successfully.');
    }

    public function updateCloudflare(Request $request, Team $current_team, Project $project)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'api_token' => ['nullable', 'string'],
            'zone_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $input = $validator->validated();
        $token = ($input['api_token'] ?? null) ?: data_get($project->settings, 'cloudflare.api_token');
        $zoneId = $input['zone_id'];

        if (! $token) {
            return back()->withErrors(['api_token' => 'Enter a Cloudflare API token.']);
        }

        if (! app(CloudflareService::class)->verifyConnection($token, $zoneId)) {
            return back()->withErrors([
                'api_token' => 'Could not connect to Cloudflare. Check the token and Zone ID.',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $token, $zoneId) {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            $settings = $current->settings ?? [];
            $settings['cloudflare'] = ['api_token' => $token, 'zone_id' => $zoneId];
            $current->update(['settings' => $settings]);
        });

        return back()->with('success', 'Cloudflare settings updated and verified successfully.');
    }

    public function store(Request $request, Team $current_team, CreateProject $createProject)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $project = $createProject->handle($current_team, $request->name, $request->url, $request->user()->email);

        if ($request->hasFile('logo')) {
            $project->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        return redirect()->route('dashboard', [
            'current_team' => $current_team->slug,
            'project' => $project->slug,
        ]);
    }
}
