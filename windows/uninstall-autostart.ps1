<#
.SYNOPSIS
    Removes the Digital Theater Posters scheduled tasks (and optionally closes the kiosk).

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\uninstall-autostart.ps1 -StopKiosk
#>
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    # Also close the kiosk Edge window and the watchdog.
    [switch] $StopKiosk
)

$ErrorActionPreference = 'Stop'
$TaskPath = '\DigitalTheaterPosters\'

$tasks = @(Get-ScheduledTask -TaskPath $TaskPath -ErrorAction SilentlyContinue)
foreach ($t in $tasks) {
    try { Stop-ScheduledTask -TaskPath $TaskPath -TaskName $t.TaskName -ErrorAction SilentlyContinue } catch { }
    Unregister-ScheduledTask -TaskPath $TaskPath -TaskName $t.TaskName -Confirm:$false
    Write-Host "Removed task: $($t.TaskName)"
}
if ($tasks.Count -eq 0) { Write-Host 'No tasks found under \DigitalTheaterPosters\.' }

# Remove the (now empty) Task Scheduler folder.
try {
    $svc = New-Object -ComObject 'Schedule.Service'
    $svc.Connect()
    $svc.GetFolder('\').DeleteFolder('DigitalTheaterPosters', 0)
} catch { }

if ($StopKiosk) {
    # Stop the watchdog first so it doesn't relaunch Edge.
    Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe' OR Name = 'pwsh.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like '*start-display.ps1*' -and $_.ProcessId -ne $PID } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

    & (Join-Path $PSScriptRoot 'start-display.ps1') -StopKiosk
}

Write-Host 'Done. The kiosk Edge profile is kept in %LOCALAPPDATA%\DigitalTheaterPosters (delete it if you like).'
