<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class WindowsAgentDownloads
{
    private const FILES = [
        'installer' => 'PanelDeploySetup.exe',
        'agent' => 'PanelDeployAgent.exe',
        'powershell' => 'PanelDeployPowerShell.zip',
    ];

    public function file(string $package): ?string
    {
        $name = self::FILES[$package] ?? null;

        if ($name === null) {
            return null;
        }

        $path = Storage::disk('local')->path('agent/win-x64/'.$name);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * @return array{version: string|null, installer: string|null, agent: string|null, powershell: string|null}
     */
    public function links(): array
    {
        return [
            'version' => config('services.windows_agent.version'),
            'installer' => $this->link('installer'),
            'agent' => $this->link('agent'),
            'powershell' => $this->link('powershell'),
        ];
    }

    private function link(string $package): ?string
    {
        return $this->file($package) === null
            ? null
            : route('servers.agent.download', ['package' => $package]);
    }
}
