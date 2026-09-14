@echo off
REM Lancement quotidien (Windows). A enregistrer dans le Planificateur de taches, voir README.md.
cd /d "%~dp0\.."
if exist ".venv\Scripts\python.exe" (set PYTHON=.venv\Scripts\python.exe) else (set PYTHON=python)
%PYTHON% daily_report.py %*
exit /b %ERRORLEVEL%
