<#
.SYNOPSIS
    Registers the Scheduled Tasks that make the theater PC run unattended.

.DESCRIPTION
    Creates tasks in the Task Scheduler folder \DigitalTheaterPosters\ :

      Display     At logon of -User: runs start-display.ps1 hidden (Edge kiosk + watchdog).
      Scheduler   Every minute: `php artisan schedule:run` (poster sync, display auto-restore).
                  Runs as -User with "S4U" logon (no stored password, no console window
                  popping up every minute).
      Nightly     Optional (-NightlyRestart): restart the kiosk Edge, or reboot the PC.

    Re-running the script updates the tasks in place. Run from an elevated
    PowerShell ("Run as administrator"):

      powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\install-autostart.ps1 `
          -Url http://digital-theater-posters.test

.PARAMETER Url
    The display URL Edge opens.

.PARAMETER AppPath
    The Laravel app folder (contains `artisan`). Default: the parent of this script's folder.

.PARAMETER PhpPath
    php.exe (or Herd's php.bat) used for the scheduler. Auto-detected from Herd when omitted.
    To find it yourself: open a terminal where `php -v` works and run `where php`
    (or `herd which-php`). Herd usually keeps PHP in %USERPROFILE%\.config\herd\bin\php8x\php.exe.

.PARAMETER User
    The Windows account that logs in automatically and shows the display. Default: current user.

.PARAMETER HighestPrivileges
    Run the display task with highest privileges (normally not needed).

.PARAMETER NightlyRestart
    Also register a nightly task (off by default).

.PARAMETER NightlyRestartMode
    Edge   = close the kiosk Edge; the watchdog relaunches it within ~30 s (default).
    Reboot = reboot the PC (pair with Windows auto-login).

.PARAMETER NightlyRestartAt
    Time of day for the nightly task, HH:mm. Default 04:00.

.PARAMETER SchedulerLogon
    S4U (default): no window, runs even when nobody is logged on. Needs the
    "Log on as a batch job" right, which administrators have by default.
    Interactive: use this if the S4U task fails for a standard (non-admin) user;
    a console window may flash briefly every minute.

.PARAMETER SkipScheduler
    Don't register the `schedule:run` task (e.g. if you run the scheduler some other way).
#>
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string] $Url = 'http://digital-theater-posters.test',
    [string] $AppPath = (Split-Path -Parent $PSScriptRoot),
    [string] $PhpPath = '',
    [string] $User = "$env:USERDOMAIN\$env:USERNAME",
    [switch] $HighestPrivileges,
    [switch] $NightlyRestart,
    [ValidateSet('Edge', 'Reboot')] [string] $NightlyRestartMode = 'Edge',
    [string] $NightlyRestartAt = '04:00',
    [ValidateSet('S4U', 'Interactive')] [string] $SchedulerLogon = 'S4U',
    [switch] $SkipScheduler
)

$ErrorActionPreference = 'Stop'
$TaskPath = '\DigitalTheaterPosters\'
$StartScript = Join-Path $PSScriptRoot 'start-display.ps1'
$PowerShellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

function Find-HerdPhp {
    # Herd for Windows: %USERPROFILE%\.config\herd\bin\php84\php.exe (one folder per version).
    $herdBin = Join-Path $env:USERPROFILE '.config\herd\bin'
    if (Test-Path $herdBin) {
        $exe = Get-ChildItem -Path $herdBin -Directory -Filter 'php*' -ErrorAction SilentlyContinue |
            Sort-Object { [int]('0' + ($_.Name -replace '\D', '')) } -Descending |
            ForEach-Object { Join-Path $_.FullName 'php.exe' } |
            Where-Object { Test-Path $_ } |
            Select-Object -First 1
        if ($exe) { return $exe }

        $shim = Join-Path $herdBin 'php.bat'
        if (Test-Path $shim) { return $shim }
    }

    foreach ($name in @('php.exe', 'php.bat', 'php')) {
        $cmd = Get-Command $name -ErrorAction SilentlyContinue
        if ($cmd) { return $cmd.Source }
    }

    return $null
}

if (-not (Test-Path (Join-Path $AppPath 'artisan'))) {
    throw "No artisan file in '$AppPath'. Pass -AppPath <folder of the Laravel app>."
}
if (-not (Test-Path $StartScript)) {
    throw "start-display.ps1 not found next to this script ($StartScript)."
}
$AppPath = (Resolve-Path $AppPath).Path

Write-Host "App:  $AppPath"
Write-Host "URL:  $Url"
Write-Host "User: $User"

# --- Display (at logon) -------------------------------------------------------------
$displayArgs = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$StartScript`" -Url `"$Url`""
$displayAction = New-ScheduledTaskAction -Execute $PowerShellExe -Argument $displayArgs -WorkingDirectory $PSScriptRoot
$displayTrigger = New-ScheduledTaskTrigger -AtLogOn -User $User
$displayTrigger.Delay = 'PT15S' # let the desktop settle
$runLevel = if ($HighestPrivileges) { 'Highest' } else { 'Limited' }
$displayPrincipal = New-ScheduledTaskPrincipal -UserId $User -LogonType Interactive -RunLevel $runLevel
$displaySettings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -MultipleInstances IgnoreNew `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskPath $TaskPath -TaskName 'Display' `
    -Description 'Digital Theater Posters: Edge kiosk + watchdog (windows\start-display.ps1).' `
    -Action $displayAction -Trigger $displayTrigger -Principal $displayPrincipal -Settings $displaySettings -Force | Out-Null
Write-Host 'Registered task: Display (at logon)'

# --- Laravel scheduler (every minute) -----------------------------------------------
if (-not $SkipScheduler) {
    if (-not $PhpPath) { $PhpPath = Find-HerdPhp }
    if (-not $PhpPath -or -not (Test-Path $PhpPath)) {
        throw 'Could not find PHP. Pass -PhpPath (run `where php` in a terminal where php works).'
    }
    Write-Host "PHP:  $PhpPath"

    Push-Location $AppPath
    # PS 5.1 turns any stderr line from a native exe into a terminating error under 'Stop'.
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $version = & $PhpPath artisan --version 2>&1
        Write-Host "      $version"
    } finally {
        $ErrorActionPreference = $prevEap
        Pop-Location
    }

    if ($SchedulerLogon -eq 'S4U') {
        $schedAction = New-ScheduledTaskAction -Execute $PhpPath -Argument 'artisan schedule:run' -WorkingDirectory $AppPath
    } else {
        $schedAction = New-ScheduledTaskAction -Execute $PowerShellExe -WorkingDirectory $AppPath `
            -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -Command `"& '$PhpPath' artisan schedule:run`""
    }
    # Repeats every minute indefinitely (also after reboots).
    $schedTrigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
    # S4U: runs as the user whether or not they are logged on, without a visible window.
    $schedPrincipal = New-ScheduledTaskPrincipal -UserId $User -LogonType $SchedulerLogon -RunLevel Limited
    $schedSettings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
        -MultipleInstances Parallel # a long sync must not block the next minute's run

    Register-ScheduledTask -TaskPath $TaskPath -TaskName 'Scheduler' `
        -Description 'Digital Theater Posters: php artisan schedule:run every minute.' `
        -Action $schedAction -Trigger $schedTrigger -Principal $schedPrincipal -Settings $schedSettings -Force | Out-Null
    Write-Host 'Registered task: Scheduler (every minute)'
}

# --- Optional nightly restart -----------------------------------------------------------
if ($NightlyRestart) {
    $at = [DateTime]::ParseExact($NightlyRestartAt, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture)
    $nightlyTrigger = New-ScheduledTaskTrigger -Daily -At $at
    $nightlySettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

    if ($NightlyRestartMode -eq 'Reboot') {
        $nightlyAction = New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\shutdown.exe') `
            -Argument '/r /f /t 60 /c "Digital Theater Posters nightly reboot"'
        $nightlyPrincipal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    } else {
        $nightlyAction = New-ScheduledTaskAction -Execute $PowerShellExe `
            -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$StartScript`" -StopKiosk"
        $nightlyPrincipal = New-ScheduledTaskPrincipal -UserId $User -LogonType Interactive -RunLevel Limited
    }

    Register-ScheduledTask -TaskPath $TaskPath -TaskName 'Nightly' `
        -Description "Digital Theater Posters: nightly $NightlyRestartMode restart." `
        -Action $nightlyAction -Trigger $nightlyTrigger -Principal $nightlyPrincipal -Settings $nightlySettings -Force | Out-Null
    Write-Host "Registered task: Nightly ($NightlyRestartMode at $NightlyRestartAt)"
} elseif (Get-ScheduledTask -TaskPath $TaskPath -TaskName 'Nightly' -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskPath $TaskPath -TaskName 'Nightly' -Confirm:$false
    Write-Host 'Removed task: Nightly (not requested)'
}

Write-Host ''
Write-Host 'Done. Start the display now with:'
Write-Host "  Start-ScheduledTask -TaskPath '$TaskPath' -TaskName 'Display'"
Write-Host 'Tasks are visible in Task Scheduler under \DigitalTheaterPosters\.'
