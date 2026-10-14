@echo off
rem Starts the print agent now and every time this Windows user signs in.
rem Run it from the folder that holds print-agent.exe and config.json.
setlocal
set "HERE=%~dp0"
if not exist "%HERE%config.json" (
    echo config.json is missing. Copy config.example.json to config.json and fill it in first.
    pause
    exit /b 1
)
schtasks /Create /TN "Workshop Print Agent" /TR "\"%HERE%print-agent.exe\"" /SC ONLOGON /RL LIMITED /F
if errorlevel 1 (
    echo Could not register the start-up task.
    pause
    exit /b 1
)
schtasks /Run /TN "Workshop Print Agent"
echo The print agent is running and will start again whenever you sign in.
pause
