@echo off
echo ============================================
echo  Registering ARIMA API as Windows Startup Task
echo ============================================

set PYTHON=C:\Users\user\AppData\Local\Python\pythoncore-3.14-64\python.exe
set SCRIPT=%~dp0arima_api.py
set TASKNAME=LGU_ARIMA_API

REM Remove old task if exists
schtasks /Delete /TN "%TASKNAME%" /F >nul 2>&1

REM Create a VBS launcher (so the CMD window is hidden on startup)
set VBS=%~dp0run_api_hidden.vbs
echo Set WshShell = CreateObject("WScript.Shell") > "%VBS%"
echo WshShell.Run """%PYTHON%"" ""%SCRIPT%""", 0, False >> "%VBS%"

REM Register the scheduled task to run at logon
schtasks /Create /TN "%TASKNAME%" /TR "wscript.exe \"%VBS%\"" /SC ONLOGON /RL HIGHEST /F

if %errorlevel%==0 (
    echo.
    echo SUCCESS! The ARIMA API will now start automatically every time you log in.
    echo Task name: %TASKNAME%
    echo.
    echo To remove it later, run:
    echo   schtasks /Delete /TN "%TASKNAME%" /F
) else (
    echo.
    echo FAILED to register task. Try running this .bat as Administrator.
)
pause
