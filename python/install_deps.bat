@echo off
echo ============================================
echo  Installing LGU ARIMA Python Dependencies
echo ============================================

REM ── Find Python ──────────────────────────────
set PYTHON=

REM Check pythoncore (Windows Store installs the real exe here)
for %%V in (3.14 3.13 3.12 3.11 3.10) do (
    for %%A in (64 32) do (
        if exist "%LOCALAPPDATA%\Python\pythoncore-%%V-%%A\python.exe" (
            set PYTHON=%LOCALAPPDATA%\Python\pythoncore-%%V-%%A\python.exe
            goto :found
        )
    )
)

where python >nul 2>&1
if %errorlevel%==0 ( set PYTHON=python & goto :found )

where py >nul 2>&1
if %errorlevel%==0 ( set PYTHON=py & goto :found )

if exist "%LOCALAPPDATA%\Microsoft\WindowsApps\python.exe" (
    set PYTHON=%LOCALAPPDATA%\Microsoft\WindowsApps\python.exe
    goto :found
)

echo ERROR: Python not found. Install from https://www.python.org/downloads/
pause & exit /b 1

:found
echo Using Python: %PYTHON%
"%PYTHON%" --version
echo.

echo Step 1: Upgrading pip...
"%PYTHON%" -m pip install --upgrade pip

echo.
echo Step 2: Installing core packages (required)...
"%PYTHON%" -m pip install flask
"%PYTHON%" -m pip install flask-cors
"%PYTHON%" -m pip install mysql-connector-python
"%PYTHON%" -m pip install python-dateutil

echo.
echo Step 3: Installing ARIMA packages (optional but recommended)...
echo         These need pre-built wheels. If Python 3.14+ fails, that is OK.
echo         The API will fall back to Holt-Winters smoothing automatically.
echo.
"%PYTHON%" -m pip install numpy && echo numpy: OK || echo numpy: SKIPPED (no wheel for this Python version)
"%PYTHON%" -m pip install scipy && echo scipy: OK  || echo scipy: SKIPPED
"%PYTHON%" -m pip install statsmodels && echo statsmodels: OK || echo statsmodels: SKIPPED

echo.
echo ============================================
echo  Verifying installs...
echo ============================================
"%PYTHON%" -c "import flask; print('flask          OK -', flask.__version__)"
"%PYTHON%" -c "import flask_cors; print('flask-cors     OK')"
"%PYTHON%" -c "import mysql.connector; print('mysql-connector OK')"
"%PYTHON%" -c "import dateutil; print('python-dateutil OK')"
"%PYTHON%" -c "import numpy; print('numpy          OK -', numpy.__version__)" 2>nul || echo numpy          SKIPPED
"%PYTHON%" -c "import statsmodels; print('statsmodels    OK -', statsmodels.__version__)" 2>nul || echo statsmodels    SKIPPED (Holt fallback will be used)

echo.
echo ============================================
echo  Done!  Run start_api.bat to launch the API.
echo ============================================
pause
