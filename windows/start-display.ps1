<#
.SYNOPSIS
    Launches the poster display in Microsoft Edge kiosk mode and keeps it running.

.DESCRIPTION
    1. Waits until the app answers HTTP 200 (Herd and MySQL may start after login).
    2. Starts Edge full-screen in kiosk mode with a dedicated profile directory, so
       it never shares (or restores) the user's normal browsing session.
    3. Watchdog loop:
         - If the kiosk Edge process exits or crashes, it is relaunched.
         - If the health URL (/up) fails for -UnhealthyMinutes, Edge is restarted
           as soon as the app is healthy again. (Edge is deliberately NOT restarted
           while the app is down: the page keeps showing cached posters, whereas a
           fresh Edge would show an error page.)

    Works with Windows PowerShell 5.1 and PowerShell 7.

.PARAMETER Url
    The display URL, e.g. http://digital-theater-posters.test or https://... after `herd secure`.

.PARAMETER HealthPath
    Path checked for health, relative to the Url's origin. Default /up.

.PARAMETER EdgePath
    Full path to msedge.exe. Auto-detected when omitted.

.PARAMETER ProfileDir
    Dedicated Edge --user-data-dir for the kiosk.

.PARAMETER UnhealthyMinutes
    Restart Edge after the app has been unhealthy this long (and has recovered).

.PARAMETER CheckIntervalSeconds
    Watchdog polling interval.

.PARAMETER StopKiosk
    Close the kiosk Edge (only the one using ProfileDir) and exit. The watchdog,
    if running, relaunches it: this is how the nightly Edge restart works.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File .\start-display.ps1 -Url http://digital-theater-posters.test
#>
[CmdletBinding()]
param(
    [string] $Url = $(if ($env:POSTER_DISPLAY_URL) { $env:POSTER_DISPLAY_URL } else { 'http://digital-theater-posters.test' }),
    [string] $HealthPath = '/up',
    [string] $EdgePath = '',
    [string] $ProfileDir = (Join-Path $env:LOCALAPPDATA 'DigitalTheaterPosters\EdgeKiosk'),
    [int] $UnhealthyMinutes = 5,
    [int] $CheckIntervalSeconds = 30,
    [string] $LogFile = (Join-Path $env:LOCALAPPDATA 'DigitalTheaterPosters\start-display.log'),
    [switch] $StopKiosk
)

$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'

# Older Windows PowerShell defaults to TLS 1.0 for Invoke-WebRequest.
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }

function Write-Log([string] $Message) {
    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $Message
    Write-Host $line
    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        # Keep the log small: roll over at ~1 MB.
        if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt 1MB)) {
            Move-Item -Force $LogFile "$LogFile.old"
        }
        Add-Content -Path $LogFile -Value $line
    } catch { }
}

function Get-EdgePath {
    if ($EdgePath -and (Test-Path $EdgePath)) { return $EdgePath }

    $candidates = @(
        (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe'),
        (Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe'),
        (Join-Path $env:LOCALAPPDATA 'Microsoft\Edge\Application\msedge.exe')
    )
    foreach ($c in $candidates) {
        if ($c -and (Test-Path $c)) { return $c }
    }

    $cmd = Get-Command msedge.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    throw 'Microsoft Edge (msedge.exe) not found. Pass -EdgePath.'
}

function Get-HealthUrl {
    $uri = [Uri] $Url
    return $uri.GetLeftPart([UriPartial]::Authority) + $HealthPath
}

function Test-AppHealthy {
    try {
        $res = Invoke-WebRequest -Uri (Get-HealthUrl) -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        return ($res.StatusCode -eq 200)
    } catch {
        return $false
    }
}

# Kiosk Edge processes are identified by our dedicated --user-data-dir.
function Get-KioskProcesses {
    $needle = $ProfileDir.ToLowerInvariant()
    try {
        return @(Get-CimInstance Win32_Process -Filter "Name = 'msedge.exe'" -ErrorAction Stop |
            Where-Object { $_.CommandLine -and $_.CommandLine.ToLowerInvariant().Contains($needle) })
    } catch {
        return @()
    }
}

function Stop-Kiosk {
    $procs = Get-KioskProcesses
    foreach ($p in $procs) {
        try { Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop } catch { }
    }
    if ($procs.Count -gt 0) { Write-Log "Stopped kiosk Edge ($($procs.Count) processes)." }
    Start-Sleep -Seconds 2
}

function Start-Kiosk {
    $edge = Get-EdgePath
    if (-not (Test-Path $ProfileDir)) { New-Item -ItemType Directory -Path $ProfileDir -Force | Out-Null }

    $edgeArgs = @(
        "--kiosk `"$Url`"",
        '--edge-kiosk-type=fullscreen',
        "--user-data-dir=`"$ProfileDir`"",
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-features=Translate',
        '--autoplay-policy=no-user-gesture-required',
        '--hide-crash-restore-bubble',
        '--disable-session-crashed-bubble',
        '--disable-pinch',
        '--overscroll-history-navigation=0'
    )

    Write-Log "Launching Edge kiosk: $Url"
    Start-Process -FilePath $edge -ArgumentList ($edgeArgs -join ' ') | Out-Null
    Start-Sleep -Seconds 5
}

if ($StopKiosk) {
    Stop-Kiosk
    exit 0
}

# Only one watchdog per user session.
$mutex = New-Object System.Threading.Mutex($false, 'Local\DigitalTheaterPostersDisplayWatchdog')
if (-not $mutex.WaitOne(0)) {
    Write-Log 'Another start-display.ps1 watchdog is already running; exiting.'
    exit 0
}

try {
    Write-Log "Waiting for $(Get-HealthUrl) to return 200..."
    $lastNotice = Get-Date
    while (-not (Test-AppHealthy)) {
        if (((Get-Date) - $lastNotice).TotalMinutes -ge 5) {
            Write-Log 'Still waiting for the app (is Herd running? is MySQL up?)...'
            $lastNotice = Get-Date
        }
        Start-Sleep -Seconds 5
    }
    Write-Log 'App is up.'

    # Clean slate: close any leftover kiosk instance from a previous session.
    Stop-Kiosk
    Start-Kiosk

    $unhealthySince = $null
    $restartWhenHealthy = $false

    while ($true) {
        Start-Sleep -Seconds $CheckIntervalSeconds

        $healthy = Test-AppHealthy

        if ($healthy) {
            if ($restartWhenHealthy) {
                Write-Log 'App recovered after a long outage; restarting Edge for a fresh page.'
                Stop-Kiosk
                Start-Kiosk
                $restartWhenHealthy = $false
            }
            $unhealthySince = $null
        } else {
            if (-not $unhealthySince) {
                $unhealthySince = Get-Date
                Write-Log 'Health check failed.'
            } elseif (-not $restartWhenHealthy -and ((Get-Date) - $unhealthySince).TotalMinutes -ge $UnhealthyMinutes) {
                Write-Log "App unhealthy for $UnhealthyMinutes+ minutes; Edge will restart once it recovers."
                $restartWhenHealthy = $true
            }
        }

        if ((Get-KioskProcesses).Count -eq 0) {
            if ($healthy) {
                Write-Log 'Kiosk Edge is not running; relaunching.'
                Start-Kiosk
            } else {
                Write-Log 'Kiosk Edge is not running and the app is down; will relaunch when it is back.'
            }
        }
    }
} finally {
    $mutex.ReleaseMutex() | Out-Null
    $mutex.Dispose()
}
