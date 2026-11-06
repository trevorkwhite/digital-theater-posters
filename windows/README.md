# Windows kiosk setup

These scripts turn a Windows 10/11 home-theater PC into an unattended poster screen:
it logs in by itself, waits for Herd, opens the display full-screen in Edge, and keeps it running.

| Script | What it does |
|---|---|
| `start-display.ps1` | Waits for the app to answer `200`, launches Edge in kiosk mode with its own profile, then watches it: relaunches Edge if it exits, and restarts it after a long backend outage once the app is back. Logs to `%LOCALAPPDATA%\DigitalTheaterPosters\start-display.log`. |
| `install-autostart.ps1` | Registers Scheduled Tasks under `\DigitalTheaterPosters\`: **Display** (at logon), **Scheduler** (`php artisan schedule:run` every minute), and optionally **Nightly** (restart Edge or reboot). |
| `uninstall-autostart.ps1` | Removes those tasks (`-StopKiosk` also closes the kiosk). |

All scripts work with the built-in Windows PowerShell 5.1.

## Install

Open **PowerShell as administrator** in the app folder:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\install-autostart.ps1 `
    -Url http://digital-theater-posters.test
```

Options:

```powershell
# Explicit PHP (see "Finding Herd's PHP" below) and app folder
.\windows\install-autostart.ps1 -Url https://digital-theater-posters.test `
    -AppPath C:\Users\theater\Herd\digital-theater-posters `
    -PhpPath C:\Users\theater\.config\herd\bin\php84\php.exe

# Also restart the kiosk Edge every night at 04:00 (off by default)
.\windows\install-autostart.ps1 -Url http://digital-theater-posters.test -NightlyRestart

# ...or reboot the whole PC nightly at 05:30 (use with auto-login)
.\windows\install-autostart.ps1 -Url http://digital-theater-posters.test -NightlyRestart -NightlyRestartMode Reboot -NightlyRestartAt 05:30

# The display account is a standard (non-admin) user and the Scheduler task fails?
.\windows\install-autostart.ps1 -Url http://digital-theater-posters.test -SchedulerLogon Interactive
```

Start it right away without logging out:

```powershell
Start-ScheduledTask -TaskPath '\DigitalTheaterPosters\' -TaskName 'Display'
```

Re-running the installer updates the tasks in place.

### Finding Herd's PHP

The installer looks in `%USERPROFILE%\.config\herd\bin\php8x\php.exe` (newest version wins), then Herd's
`php.bat` shim, then whatever `php` is on `PATH`. To check yourself, open a terminal where `php -v` works and run:

```powershell
where.exe php
herd which-php   # if your Herd version has it
```

Pass the result with `-PhpPath`. A `php.bat` shim works too.

### Why the Scheduler task uses "S4U"

`schedule:run` runs every minute. As a normal interactive task, `php.exe` would flash a console window
on the poster screen every minute. With the S4U logon type it runs as the same user, in the background,
without a window and without storing a password. S4U needs the *Log on as a batch job* right
(administrators have it by default). For a standard user either grant it
(`secpol.msc` -> Local Policies -> User Rights Assignment) or use `-SchedulerLogon Interactive`.

## Edge kiosk details

Edge is started with:

```
msedge.exe --kiosk <url> --edge-kiosk-type=fullscreen --user-data-dir=%LOCALAPPDATA%\DigitalTheaterPosters\EdgeKiosk
           --no-first-run --no-default-browser-check --disable-features=Translate
           --autoplay-policy=no-user-gesture-required --hide-crash-restore-bubble ...
```

* The dedicated `--user-data-dir` keeps the kiosk away from your normal Edge profile (no restored tabs,
  sign-in prompts or sync), and lets the watchdog find exactly its own processes.
* To leave kiosk mode: `Alt+F4` (the watchdog will relaunch it within `-CheckIntervalSeconds`;
  stop the **Display** task first, or run `.\windows\start-display.ps1 -StopKiosk` after stopping the task).
* The page itself hides the cursor, reloads itself every 24 h or when you deploy new display files,
  and keeps showing cached posters if the backend is down.

Run the watchdog by hand to see what it does:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\windows\start-display.ps1 -Url http://digital-theater-posters.test
```

## Make the PC an appliance

### Automatic login

1. Press `Win+R`, run `netplwiz`.
2. Untick **Users must enter a user name and password to use this computer**, click OK, enter the password.
3. If the checkbox is missing (Windows 11 with Windows Hello): Settings -> Accounts -> Sign-in options ->
   turn off **For improved security, only allow Windows Hello sign-in for Microsoft accounts on this device**,
   then reopen `netplwiz`.

A local (non-Microsoft) account dedicated to the theater screen is the most predictable choice.

### Never sleep, no screensaver

```powershell
powercfg /change standby-timeout-ac 0
powercfg /change monitor-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /hibernate off
```

* Screensaver: Settings -> Personalization -> Lock screen -> Screen saver -> **(None)**.
* Dynamic lock / "lock when away": Settings -> Accounts -> Sign-in options -> turn off Dynamic lock, and set
  "If you've been away, when should Windows require you to sign in again?" to **Never**.
* Optionally pick the **High performance** power plan so USB/GPU power saving doesn't blank the output.

The display uses an overlay to go black; it never relies on the monitor turning off, so HDMI-CEC or a
monitor that loses signal won't confuse it.

### Hide the taskbar and notifications

Edge's fullscreen kiosk covers the taskbar, but toasts can still appear on top:

* Settings -> System -> Notifications -> turn **Notifications** off (or set **Do not disturb** on permanently).
* Settings -> System -> Notifications -> Additional settings -> untick the "Welcome experience" / "Suggest ways
  to finish setting up" / "Get tips" options.
* Settings -> Personalization -> Taskbar -> Taskbar behaviors -> **Automatically hide the taskbar**.
* Windows Update: Settings -> Windows Update -> Advanced options -> **Active hours** covering movie nights,
  and turn off "Notify me when a restart is required".
* Settings -> System -> Display: set the screen to **Portrait** orientation and 100% scale, or set the
  rotation in the GPU control panel.

### Herd

* Herd -> Settings -> General -> **Launch Herd on system startup**.
* With Herd Pro, enable the MySQL service and its **autostart**. The watchdog waits for `/up` anyway, so
  slow startups are fine.
