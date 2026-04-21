param(
  [Parameter(Mandatory = $true)]
  [ValidateSet('apply', 'backup')]
  [string]$Mode,

  [Parameter(Mandatory = $true)]
  [string]$GameSlug,

  [string]$ProfileZip = '',
  [string]$OutZip = '',
  [string]$SteamRoot = "$env:ProgramFiles(x86)\Steam",
  [string]$SteamId = '',
  [string]$LogitechProfile = '',
  [string]$RazerProfile = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-GamePaths {
  param([string]$Slug, [string]$Steam, [string]$Sid)

  switch ($Slug.ToLowerInvariant()) {
    'cs2' {
      @(
        (Join-Path $Steam "userdata\$Sid\730\local\cfg"),
        (Join-Path $Steam "steamapps\common\Counter-Strike Global Offensive\game\csgo\cfg")
      )
    }
    'dota2' {
      @(
        (Join-Path $Steam "userdata\$Sid\570\remote\cfg"),
        (Join-Path $Steam "steamapps\common\dota 2 beta\game\dota\cfg")
      )
    }
    default { @() }
  }
}

function Try-ActivateMouseProfiles {
  param([string]$LProfile, [string]$RProfile)

  $lghub = "${env:ProgramFiles}\LGHUB\lghub.exe"
  if ((Test-Path $lghub) -and $LProfile) {
    # G HUB CLI yo'q, lekin appni uyg'otib profile switchga tayyor holatga olib kelamiz.
    Start-Process -FilePath $lghub -WindowStyle Hidden | Out-Null
  }

  $synapse = "${env:ProgramFiles(x86)}\Razer\Synapse3\WPFUI\Framework\Razer Synapse 3 Host\Razer Synapse 3.exe"
  if ((Test-Path $synapse) -and $RProfile) {
    Start-Process -FilePath $synapse -WindowStyle Hidden | Out-Null
  }
}

$targets = Get-GamePaths -Slug $GameSlug -Steam $SteamRoot -Sid $SteamId
if ($targets.Count -eq 0) {
  throw "Unsupported game slug: $GameSlug"
}

if ($Mode -eq 'apply') {
  if (-not (Test-Path $ProfileZip)) {
    throw "Profile zip not found: $ProfileZip"
  }

  $tmp = Join-Path $env:TEMP ("mycafe-profile-" + [guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $tmp | Out-Null
  Expand-Archive -Path $ProfileZip -DestinationPath $tmp -Force

  foreach ($target in $targets) {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    Copy-Item -Path (Join-Path $tmp '*') -Destination $target -Recurse -Force
  }

  Try-ActivateMouseProfiles -LProfile $LogitechProfile -RProfile $RazerProfile
  Write-Output "Applied profile for $GameSlug"
  exit 0
}

if ($Mode -eq 'backup') {
  if (-not $OutZip) {
    throw "OutZip is required for backup mode"
  }

  $tmp = Join-Path $env:TEMP ("mycafe-backup-" + [guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $tmp | Out-Null

  foreach ($target in $targets) {
    if (-not (Test-Path $target)) { continue }
    Copy-Item -Path (Join-Path $target '*') -Destination $tmp -Recurse -Force -ErrorAction SilentlyContinue
  }

  if (Test-Path $OutZip) { Remove-Item $OutZip -Force }
  Compress-Archive -Path (Join-Path $tmp '*') -DestinationPath $OutZip -Force
  Write-Output "Backed up profile for $GameSlug => $OutZip"
  exit 0
}

