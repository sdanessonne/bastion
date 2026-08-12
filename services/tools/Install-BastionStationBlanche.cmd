@echo off
REM Bastion - installation du logiciel de station blanche.
REM
REM A LANCER EN ADMINISTRATEUR sur le poste d'analyse.
REM Clic droit sur ce fichier > "Executer en tant qu'administrateur".
REM
REM CE QU'IL FAIT
REM   1. copie l'executable depuis le partage Commun vers un dossier local ;
REM   2. cree un raccourci sur le Bureau et dans le menu Demarrer ;
REM   3. laisse le dossier accessible en ECRITURE.
REM
REM POURQUOI PAS "PROGRAM FILES"
REM Le logiciel enregistre sa configuration - adresse de la passerelle et jeton
REM de station - dans un fichier "station.json" place A COTE de l'executable.
REM Dans Program Files, ce dossier n'est pas accessible en ecriture a un compte
REM ordinaire : l'ecran de configuration refuserait d'enregistrer, avec un
REM message que personne ne rattacherait aux droits du dossier. On installe donc
REM sous ProgramData, ou l'ecriture est possible.
REM
REM Ce fichier est en ASCII pur, sans accent : un .cmd accentue est lu dans la
REM page de codes de la console et s'affiche en charabia.

setlocal
set "SRC=\\dc.bastion.pn.int\Commun\BastionStationBlanche.exe"
set "DST=C:\ProgramData\Bastion\StationBlanche"
set "EXE=%DST%\BastionStationBlanche.exe"

echo.
echo   Bastion - installation de la station blanche
echo   --------------------------------------------

net session >nul 2>&1
if not %errorlevel%==0 (
  echo   ECHEC : droits d'administrateur necessaires.
  echo   Clic droit sur ce fichier ^> "Executer en tant qu'administrateur".
  pause
  exit /b 1
)

if not exist "%SRC%" (
  echo   ECHEC : logiciel introuvable sur le partage.
  echo     %SRC%
  echo.
  echo   Verifiez que ce poste atteint le partage Commun, et que le fichier
  echo   y a bien ete depose depuis la console Bastion.
  pause
  exit /b 1
)

echo   Copie depuis le partage...
if not exist "%DST%" mkdir "%DST%"
copy /Y "%SRC%" "%EXE%" >nul
if not exist "%EXE%" (
  echo   ECHEC : la copie n'a pas abouti.
  pause
  exit /b 1
)

REM On COMPARE les tailles : une copie interrompue sur un lien reseau instable
REM laisse un fichier present mais tronque, qui refuse de demarrer sans dire
REM pourquoi. Mieux vaut le constater ici.
for %%A in ("%SRC%") do set "TSRC=%%~zA"
for %%A in ("%EXE%") do set "TDST=%%~zA"
if not "%TSRC%"=="%TDST%" (
  echo   ECHEC : copie incomplete - %TDST% octets recus sur %TSRC% attendus.
  echo   Lien reseau instable ? Relancez cette installation.
  del /q "%EXE%" 2>nul
  pause
  exit /b 1
)
echo   Copie verifiee : %TDST% octets.

REM Ecriture autorisee pour les utilisateurs : c'est la condition pour que
REM l'ecran de configuration puisse enregistrer station.json.
icacls "%DST%" /grant "*S-1-5-32-545:(OI)(CI)M" >nul 2>&1

echo   Creation des raccourcis...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$w = New-Object -ComObject WScript.Shell;" ^
  "foreach ($d in @([Environment]::GetFolderPath('CommonDesktopDirectory'), [Environment]::GetFolderPath('CommonPrograms'))) {" ^
  "  $s = $w.CreateShortcut((Join-Path $d 'Bastion - Station blanche.lnk'));" ^
  "  $s.TargetPath = '%EXE%'; $s.WorkingDirectory = '%DST%';" ^
  "  $s.Description = 'Analyse des supports amovibles'; $s.Save() }" >nul 2>&1

echo.
echo   Installation terminee.
echo     Logiciel  : %EXE%
echo     Raccourci : Bureau et menu Demarrer
echo.
echo   IL RESTE UNE ETAPE : lancer le logiciel et renseigner l'adresse de la
echo   passerelle ainsi que le JETON DE STATION. Ce jeton se genere dans la
echo   console Bastion, page Antivirus, panneau "Stations blanches".
echo   Chaque station a le sien : si l'une est volee, son jeton se revoque
echo   seul, sans toucher aux autres.
echo.
pause
endlocal
