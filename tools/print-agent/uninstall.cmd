@echo off
rem Stops the print agent and removes it from start-up.
schtasks /End /TN "Workshop Print Agent" >nul 2>&1
schtasks /Delete /TN "Workshop Print Agent" /F
taskkill /IM print-agent.exe /F >nul 2>&1
echo The print agent is stopped and will no longer start at sign-in.
pause
