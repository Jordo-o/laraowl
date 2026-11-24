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

    public function destroy(Team $current_team, Project $project)
    {
        $project->delete();

        return redirect()->route('dashboard', ['current_team' => $current_team->slug])
            ->with('success', 'Project deleted successfully.');
    }

    public function updateCloudflare(Request $request, Team $current_team, Project $project)
    {
        $request->validate([
            'api_token' => ['required', 'string'],
            'zone_id' => ['required', 'string'],
        ]);

        try {
            $isValid = app(CloudflareService::class)->verifyConnection($request->api_token, $request->zone_id);

            if (! $isValid) {
                return back()->withErrors([
                    'api_token' => 'Could not connect to Cloudflare. Please check your Token and Zone ID.',
                    'zone_id' => 'Verification failed',
                ])->withInput();
            }

            // Force update the settings array
            $settings = $project->settings ?? [];
            $settings['cloudflare'] = [
                'api_token' => $request->api_token,
                'zone_id' => $request->zone_id,
            ];

            $project->settings = $settings;
            $project->save();

            return back()->with('success', 'Cloudflare settings updated and verified successfully.');
        } catch (\Exception $e) {
            \Log::error('Cloudflare Save Error: '.$e->getMessage());

            return back()->withErrors(['api_token' => 'An unexpected error occurred while saving.'])->withInput();
        }
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
