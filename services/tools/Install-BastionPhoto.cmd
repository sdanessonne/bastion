@echo off
REM ====================================================================
REM  Bastion - Install-BastionPhoto.cmd
REM  (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
REM
REM  Fait apparaitre la PHOTO DE L'AGENT sur l'ecran de connexion, dans le
REM  menu Demarrer et dans Parametres > Comptes.
REM
REM  Pourquoi un outil et non une strategie : Windows 11 ne lit pas la photo
REM  de l'annuaire pour l'image de compte. Il faut deposer les fichiers dans
REM  C:\Users\Public\AccountPictures et ecrire dans la base de registre, ce
REM  qui exige des droits ADMINISTRATEUR. Un script de session ordinaire
REM  n'en a pas ; un script de demarrage, lui, ne s'execute qu'au boot et
REM  echoue si l'horloge du poste est decalee. On installe donc une TACHE
REM  PLANIFIEE, executee en tant que SYSTEME a chaque ouverture de session
REM  et independante du domaine.
REM
REM  A executer UNE FOIS par poste, en tant qu'ADMINISTRATEUR.
REM ====================================================================
setlocal
net session >nul 2>&1 || (echo [ERREUR] Relancez ce script en tant qu'Administrateur. & pause & exit /b 1)

set "GW=192.168.182.1"
set "DIR=C:\ProgramData\Bastion"

echo [1/3] Recuperation du script depuis la passerelle...
if not exist "%DIR%" mkdir "%DIR%"
curl.exe -s --retry 3 --retry-delay 2 -o "%DIR%\photo-tile.ps1" "http://%GW%:2080/boot/photo-tile.ps1"
if not exist "%DIR%\photo-tile.ps1" (
  echo [ERREUR] Script introuvable. La passerelle repond-elle ^(ping %GW%^) ?
  pause & exit /b 1
)

echo [2/3] Creation de la tache planifiee ^(SYSTEME, a chaque ouverture de session^)...
schtasks /create /tn "Bastion - Photo de l'agent" /ru SYSTEM /rl HIGHEST ^
  /sc ONLOGON /delay 0000:15 ^
  /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%\photo-tile.ps1\"" /f

echo [3/3] Application immediate pour la session en cours...
powershell -NoProfile -ExecutionPolicy Bypass -File "%DIR%\photo-tile.ps1"

echo.
echo === Journal ===
if exist "%DIR%\photo.log" (
  powershell -NoProfile -Command "Get-Content '%DIR%\photo.log' -Tail 15"
) else (
  echo   Aucun journal produit.
)
echo.
echo Termine. La photo apparait apres FERMETURE puis REOUVERTURE de la session.
echo Si l'agent n'a pas encore de photo dans la console, rien ne change ^(c'est normal^).
endlocal
