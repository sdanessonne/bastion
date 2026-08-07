@echo off
REM Bastion - lanceur du diagnostic d'installation des logiciels.
REM
REM POURQUOI CE FICHIER EXISTE
REM Windows refuse par defaut d'executer un script PowerShell : "l'execution de
REM scripts est desactivee sur ce systeme". Ce lanceur passe l'option qui leve
REM cette restriction POUR CET APPEL SEULEMENT - la strategie de la machine
REM n'est pas modifiee, ce qui serait un affaiblissement durable pour un besoin
REM ponctuel.
REM
REM Il demande aussi l'elevation : le diagnostic lit HKLM et peut arreter un
REM installeur bloque, deux choses impossibles sans droits d'administrateur.
REM
REM Ce fichier est en ASCII pur, sans accent : un .cmd accentue est lu dans la
REM page de codes de la console et s'affiche en charabia.

setlocal
set "PS1=%~dp0Bastion-Depannage-Applications.ps1"

if not exist "%PS1%" (
  echo Script introuvable : "%PS1%"
  echo Le fichier .ps1 doit se trouver dans le meme dossier que ce lanceur.
  pause
  exit /b 1
)

REM Deja administrateur ? On lance directement. Sinon on redemande avec elevation.
net session >nul 2>&1
if %errorlevel%==0 (
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PS1%"
) else (
  echo Elevation necessaire - une demande d'autorisation va s'afficher.
  powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
    "Start-Process powershell.exe -Verb RunAs -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','%PS1%'"
)
endlocal
