@echo off
echo ============================================
echo  LGU ARIMA Forecasting API - Starting...
echo ============================================
cd /d "%~dp0"

REM ?? Find Python executable ???????????????????????????????
set PYTHON=

REM 1) Check standard PATH first
where python >nul 2>&1
if %errorlevel% == 0 (
    set PYTHON=python
    goto :found
)

where py >nul 2>&1
if %errorlevel% == 0 (
    set PYTHON=py
    goto :found
)

REM 2) Check known pythoncore install paths (Windows Store Python)
for %%V in (3.14 3.13 3.12 3.11 3.10) do (
    for %%A in (64 32) do (
        if exist "%LOCALAPPDATA%\Python\pythoncore-%%V-%%A\python.exe" (
            set PYTHON=%LOCALAPPDATA%\Python\pythoncore-%%V-%%A\python.exe
            goto :found
        )
    )
)

REM 3) Windows Store wrapper fallback
if exist "%LOCALAPPDATA%\Microsoft\WindowsApps\python.exe" (
    set PYTHON=%LOCALAPPDATA%\Microsoft\WindowsApps\python.exe
    goto :found
)

echo ERROR: Python not found.
echo Please install Python 3.10+ from https://www.python.org/downloads/
pause
exit /b 1

:found
echo Python: %PYTHON%
"%PYTHON%" --version
echo.

echo Checking dependencies...
"%PYTHON%" -m pip install -r requirements.txt -q --disable-pip-version-check
echo.

echo ============================================
echo  API URL    : http://localhost:5001
echo  Health     : http://localhost:5001/health
echo  Stop       : Press Ctrl+C
echo ============================================
echo.
"%PYTHON%" arima_api.py
pause
