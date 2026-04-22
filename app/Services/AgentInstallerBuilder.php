<?php

namespace App\Services;

class AgentInstallerBuilder
{
    public function __construct(
        private readonly TenantSettingService $settings,
        private readonly SettingRegistry $registry,
    ) {
    }

    public function buildAgentSettings(int $tenantId): array
    {
        return [
            'deploy_agent_download_url' => $this->stringSetting($tenantId, 'deploy_agent_download_url'),
            'deploy_agent_sha256' => $this->stringSetting($tenantId, 'deploy_agent_sha256'),
            'deploy_shell_download_url' => $this->stringSetting($tenantId, 'deploy_shell_download_url'),
            'deploy_shell_sha256' => $this->stringSetting($tenantId, 'deploy_shell_sha256'),
            'deploy_agent_install_args' => $this->stringSetting($tenantId, 'deploy_agent_install_args'),
            'deploy_shell_install_args' => $this->stringSetting($tenantId, 'deploy_shell_install_args'),
            'deploy_client_download_url' => $this->stringSetting($tenantId, 'deploy_client_download_url'),
            'deploy_client_install_args' => $this->stringSetting($tenantId, 'deploy_client_install_args'),
            'poll_interval_sec' => (int) config('domain.agent.poll_interval_seconds', 3),
        ];
    }

    public function buildInstallerConfig(int $tenantId, string $apiBase, string $pairCode): array
    {
        $agentDownloadUrl = $this->stringSetting($tenantId, 'deploy_agent_download_url');
        $agentInstallArgs = $this->stringSetting($tenantId, 'deploy_agent_install_args');
        $clientDownloadUrl = $this->stringSetting($tenantId, 'deploy_client_download_url');
        $clientInstallArgs = $this->stringSetting($tenantId, 'deploy_client_install_args');
        $shellDownloadUrl = $this->stringSetting($tenantId, 'deploy_shell_download_url');
        $shellInstallArgs = $this->stringSetting($tenantId, 'deploy_shell_install_args');
        $shellAutoStartEnabled = $this->boolSetting($tenantId, 'shell_autostart_enabled');
        $shellAutoStartPath = $this->stringSetting($tenantId, 'shell_autostart_path');
        $shellAutoStartArgs = $this->stringSetting($tenantId, 'shell_autostart_args');
        $shellAutoStartScope = $this->stringSetting($tenantId, 'shell_autostart_scope');
        $shellReplaceEnabled = $this->boolSetting($tenantId, 'shell_replace_explorer_enabled');
        $shellReplacePath = $this->stringSetting($tenantId, 'shell_replace_explorer_path');
        $shellReplaceArgs = $this->stringSetting($tenantId, 'shell_replace_explorer_args');

        return [
            'api_base' => $apiBase,
            'pair_code' => $pairCode,
            'agent_download_url' => $agentDownloadUrl,
            'agent_install_args' => $this->replacePlaceholders($agentInstallArgs, [
                '{SERVER}' => $apiBase,
                '{PAIR_CODE}' => $pairCode,
            ]),
            'client_download_url' => $clientDownloadUrl,
            'client_install_args' => $this->replacePlaceholders($clientInstallArgs, [
                '{SERVER}' => $apiBase,
                '{PAIR_CODE}' => $pairCode,
                '{AGENT_URL}' => $agentDownloadUrl,
                '{SHELL_URL}' => $shellDownloadUrl,
                '{AUTOSTART}' => $shellAutoStartEnabled ? '1' : '0',
                '{AUTOSTART_PATH}' => $shellAutoStartPath,
                '{AUTOSTART_ARGS}' => $shellAutoStartArgs,
                '{AUTOSTART_SCOPE}' => $shellAutoStartScope,
                '{REPLACE_SHELL}' => $shellReplaceEnabled ? '1' : '0',
                '{REPLACE_SHELL_PATH}' => $shellReplacePath,
                '{REPLACE_SHELL_ARGS}' => $shellReplaceArgs,
            ]),
            'shell_download_url' => $shellDownloadUrl,
            'shell_install_args' => $this->replacePlaceholders($shellInstallArgs, [
                '{SERVER}' => $apiBase,
                '{PAIR_CODE}' => $pairCode,
            ]),
            'shell_autostart_enabled' => $shellAutoStartEnabled,
            'shell_autostart_path' => $shellAutoStartPath,
            'shell_autostart_args' => $shellAutoStartArgs,
            'shell_autostart_scope' => $shellAutoStartScope,
            'shell_replace_explorer_enabled' => $shellReplaceEnabled,
            'shell_replace_explorer_path' => $shellReplacePath,
            'shell_replace_explorer_args' => $shellReplaceArgs,
            'poll_interval_sec' => (int) config('domain.agent.poll_interval_seconds', 3),
        ];
    }

    public function buildInstallerScript(array $config): string
    {
        return <<<PS1
\$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

\$server = {$this->psString($config['api_base'])}
\$pairCode = {$this->psString($config['pair_code'])}
\$agentUrl = {$this->psString($config['agent_download_url'])}
\$installerArgs = {$this->psString($config['agent_install_args'])}
\$shellUrl = {$this->psString($config['shell_download_url'])}
\$shellArgs = {$this->psString($config['shell_install_args'])}
\$clientUrl = {$this->psString($config['client_download_url'])}
\$clientArgs = {$this->psString($config['client_install_args'])}
\$autoStartEnabled = {$this->psString($config['shell_autostart_enabled'] ? '1' : '0')}
\$autoStartPath = {$this->psString($config['shell_autostart_path'])}
\$autoStartArgs = {$this->psString($config['shell_autostart_args'])}
\$autoStartScope = {$this->psString($config['shell_autostart_scope'])}
\$replaceShellEnabled = {$this->psString($config['shell_replace_explorer_enabled'] ? '1' : '0')}
\$replaceShellPath = {$this->psString($config['shell_replace_explorer_path'])}
\$replaceShellArgs = {$this->psString($config['shell_replace_explorer_args'])}

Write-Host "MyCafeCloud quick install started..." -ForegroundColor Cyan
Write-Host "Server: \$server"
Write-Host "Pair code: \$pairCode"

if (\$clientUrl -ne "") {
    \$tmpc = Join-Path \$env:TEMP "mycafecloud-client-setup.exe"
    Write-Host "Downloading client from \$clientUrl ..." -ForegroundColor Yellow
    Invoke-WebRequest -Uri \$clientUrl -OutFile \$tmpc -UseBasicParsing
    Write-Host "Running client installer..." -ForegroundColor Yellow
    Start-Process -FilePath \$tmpc -ArgumentList \$clientArgs -Wait
} else {
    if (\$agentUrl -ne "") {
        \$tmp = Join-Path \$env:TEMP "mycafecloud-agent-setup.exe"
        Write-Host "Downloading agent from \$agentUrl ..." -ForegroundColor Yellow
        Invoke-WebRequest -Uri \$agentUrl -OutFile \$tmp -UseBasicParsing
        Write-Host "Running installer..." -ForegroundColor Yellow
        Start-Process -FilePath \$tmp -ArgumentList \$installerArgs -Wait
    }

    if (\$shellUrl -ne "") {
        \$tmp2 = Join-Path \$env:TEMP "mycafecloud-shell-setup.exe"
        Write-Host "Downloading shell from \$shellUrl ..." -ForegroundColor Yellow
        Invoke-WebRequest -Uri \$shellUrl -OutFile \$tmp2 -UseBasicParsing
        Write-Host "Running shell installer..." -ForegroundColor Yellow
        Start-Process -FilePath \$tmp2 -ArgumentList \$shellArgs -Wait
    }
}

if (\$autoStartEnabled -eq "1" -and \$autoStartPath -ne "") {
    try {
        \$cmd = ('"' + \$autoStartPath + '" ' + \$autoStartArgs).Trim()
        if (\$autoStartScope -eq "machine") {
            New-Item -Path "HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Force | Out-Null
            Set-ItemProperty -Path "HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Name "MyCafeCloudShell" -Value \$cmd
        } else {
            New-Item -Path "HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Force | Out-Null
            Set-ItemProperty -Path "HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" -Name "MyCafeCloudShell" -Value \$cmd
        }
        Write-Host "Shell autostart configured." -ForegroundColor Green
    } catch {
        Write-Host "Shell autostart failed: \$((\$_).Exception.Message)" -ForegroundColor Red
    }
}

if (\$replaceShellEnabled -eq "1" -and \$replaceShellPath -ne "") {
    try {
        \$shellCmd = ('"' + \$replaceShellPath + '" ' + \$replaceShellArgs).Trim()
        New-Item -Path "HKLM:\\SOFTWARE\\MyCafeCloud" -Force | Out-Null
        \$prev = (Get-ItemProperty -Path "HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon" -Name "Shell" -ErrorAction SilentlyContinue).Shell
        if (\$prev) { Set-ItemProperty -Path "HKLM:\\SOFTWARE\\MyCafeCloud" -Name "PrevShell" -Value \$prev }
        Set-ItemProperty -Path "HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon" -Name "Shell" -Value \$shellCmd
        Write-Host "Windows shell replaced." -ForegroundColor Yellow
    } catch {
        Write-Host "Shell replace failed: \$((\$_).Exception.Message)" -ForegroundColor Red
    }
}

\$payload = @{
    pair_code = \$pairCode
    pc_name   = \$env:COMPUTERNAME
} | ConvertTo-Json

try {
    \$resp = Invoke-RestMethod -Method Post -Uri "\$server/agent/pair" -ContentType "application/json" -Body \$payload
    Write-Host "Pair success: \$((\$resp.pc.code))" -ForegroundColor Green
} catch {
    Write-Host "Pair failed: \$((\$_).Exception.Message)" -ForegroundColor Red
    throw
}
PS1;
    }

    public function buildInstallOneLiner(string $scriptUrl): string
    {
        return 'powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing -Uri \'' . $scriptUrl . '\' | iex"';
    }

    public function buildGpoScript(string $scriptUrl): string
    {
        return <<<PS1
\$ErrorActionPreference = "Stop"
\$logDir = "C:\\ProgramData\\MyCafeCloud"
\$logFile = Join-Path \$logDir "gpo_install.log"
if (!(Test-Path \$logDir)) { New-Item -ItemType Directory -Path \$logDir | Out-Null }
function Log(\$msg) {
    \$line = ("[" + (Get-Date).ToString("s") + "] " + \$msg)
    Add-Content -Path \$logFile -Value \$line
}

try {
    \$svc = Get-Service -Name "MyCafeCloudAgent" -ErrorAction SilentlyContinue
    if (\$svc) {
        Log "Agent already installed. Exit."
        exit 0
    }
} catch {}

\$url = {$this->psString($scriptUrl)}
for (\$i = 1; \$i -le 3; \$i++) {
    try {
        Log "Running quick install attempt \$i..."
        iwr -UseBasicParsing -Uri \$url | iex
        Log "Quick install completed."
        exit 0
    } catch {
        Log "Attempt \$i failed: \$((\$_).Exception.Message)"
        Start-Sleep -Seconds 5
    }
}

throw "GPO install failed after 3 attempts."
PS1;
    }

    private function stringSetting(int $tenantId, string $key): string
    {
        return trim((string) $this->settings->get(
            $tenantId,
            $key,
            $this->registry->defaultValue($key, '')
        ));
    }

    private function boolSetting(int $tenantId, string $key): bool
    {
        return $this->registry->asBool(
            $this->settings->get($tenantId, $key, $this->registry->defaultValue($key, false))
        );
    }

    private function replacePlaceholders(string $template, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function psString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
