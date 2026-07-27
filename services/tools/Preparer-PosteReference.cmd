@echo off
REM ====================================================================
REM  Bastion - Preparer-PosteReference.cmd
REM  (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
REM
REM  A executer sur le POSTE DE REFERENCE, en ADMINISTRATEUR, juste avant
REM  de capturer l'image master (menu PXE, option [3]).
REM
REM  POURQUOI CET OUTIL EXISTE
REM  L'image master mesuree le 26/07 pesait 17,8 Go compressee mais
REM  53,1 Go DEPLOYES, pour 220 957 fichiers. Or la restauration passe
REM  l'essentiel de son temps a decompresser et a ecrire : le transfert
REM  reseau, lui, ne prend que 3 minutes. Autrement dit, chaque gigaoctet
REM  retire ici est du temps gagne sur CHAQUE poste, a CHAQUE restauration.
REM
REM  Ce script ne retire QUE des donnees regenerables : caches, fichiers
REM  temporaires, corbeille, journaux, versions remplacees des composants.
REM  Windows recree tout cela au premier demarrage. Aucun logiciel installe,
REM  aucun reglage, aucun profil n'est supprime.
REM
REM  Il ne lance PAS sysprep tout seul : c'est la derniere etape, elle
REM  eteint le poste et se decide. Elle est proposee a la fin.
REM ====================================================================
REM  PAS d'" enabledelayedexpansion " ici, volontairement : avec cette option le
REM  point d'exclamation devient un caractere special, et les marqueurs " [!] "
REM  des controles ci-dessous s'afficheraient " [] ". On n'en a pas besoin : les
REM  variables posees dans un bloc ne sont relues qu'APRES ce bloc.
setlocal
title Bastion - Preparation du poste de reference

net session >nul 2>&1 || (echo [ERREUR] Relancez ce script en tant qu'Administrateur. & pause & exit /b 1)

set "LOG=C:\bastion-preparation.txt"
echo Bastion - preparation du poste de reference > "%LOG%"
echo Date : %DATE% %TIME% >> "%LOG%"

REM Espace libre AVANT, en octets. " wmic " disparait des Windows recents :
REM on passe par PowerShell, present partout.
for /f %%a in ('powershell -NoProfile -Command "(Get-PSDrive C).Free"') do set AVANT=%%a

cls
echo.
echo   =====================================================================
echo     BASTION - Preparation du poste de reference
echo   =====================================================================
echo.
echo   Ce poste va etre allege avant la capture de l'image master.
echo   Seules des donnees REGENERABLES sont retirees : caches, temporaires,
echo   corbeille, journaux, versions remplacees des composants Windows.
echo.
echo   Aucun logiciel, aucun reglage, aucun profil n'est supprime.
echo.
powershell -NoProfile -Command "'   Espace libre actuel : {0:N1} Go' -f ((Get-PSDrive C).Free/1GB)"
echo.
set GO=
set /p GO=  Poursuivre ? Tapez OUI :
if /i not "%GO%"=="OUI" (echo   Annule. & pause & exit /b 0)

REM -- 1) Magasin de composants : de loin le plus gros poste ---------------
echo.
echo  [1/9] Magasin de composants (WinSxS)
echo.
echo   Windows conserve toutes les versions REMPLACEES des composants mis a
echo   jour. Sur l'image analysee, WinSxS comptait 159 000 entrees. C'est le
echo   plus gros gain possible : souvent plusieurs gigaoctets.
echo.
echo   CONTREPARTIE, irreversible : les mises a jour deja installees ne
echo   pourront plus etre desinstallees, ni sur ce poste ni sur ceux qui
echo   recevront l'image. C'est l'usage normal pour une image master.
echo.
set NET=
set /p NET=  Nettoyer le magasin de composants ? (OUI / non) :
if /i "%NET%"=="OUI" goto net_oui
echo   Ignore - l'image restera nettement plus grosse.
echo [1] magasin de composants : IGNORE >> "%LOG%"
goto etape2
:net_oui
echo   En cours, cela peut prendre 10 a 20 minutes...
Dism /Online /Cleanup-Image /StartComponentCleanup /ResetBase >> "%LOG%" 2>&1
if errorlevel 1 (echo   ECHEC - voir %LOG%) else (echo   Termine.)
echo [1] magasin de composants : code %errorlevel% >> "%LOG%"

:etape2
REM -- 2) Cache de Windows Update ------------------------------------------
echo.
echo  [2/9] Cache de Windows Update
net stop wuauserv >nul 2>&1
net stop bits     >nul 2>&1
if exist "C:\Windows\SoftwareDistribution\Download" rd /s /q "C:\Windows\SoftwareDistribution\Download" >nul 2>&1
md "C:\Windows\SoftwareDistribution\Download" >nul 2>&1
net start bits     >nul 2>&1
net start wuauserv >nul 2>&1
echo   Termine.

REM -- 3) Cache d'optimisation de livraison --------------------------------
REM  C'est le cache pair-a-pair des mises a jour : il peut peser plusieurs Go
REM  et n'a evidemment aucune raison de voyager dans une image.
echo.
echo  [3/9] Cache d'optimisation de livraison
net stop dosvc >nul 2>&1
if exist "C:\ProgramData\Microsoft\Windows\DeliveryOptimization\Cache" rd /s /q "C:\ProgramData\Microsoft\Windows\DeliveryOptimization\Cache" >nul 2>&1
net start dosvc >nul 2>&1
echo   Termine.

REM -- 4) Fichiers temporaires, systeme ET tous les profils ----------------
echo.
echo  [4/9] Fichiers temporaires
if exist "C:\Windows\Temp" rd /s /q "C:\Windows\Temp" >nul 2>&1
md "C:\Windows\Temp" >nul 2>&1
for /d %%p in (C:\Users\*) do (
  if exist "%%p\AppData\Local\Temp" rd /s /q "%%p\AppData\Local\Temp" >nul 2>&1
  md "%%p\AppData\Local\Temp" >nul 2>&1
  if exist "%%p\AppData\Local\Microsoft\Windows\INetCache" rd /s /q "%%p\AppData\Local\Microsoft\Windows\INetCache" >nul 2>&1
  if exist "%%p\AppData\Local\CrashDumps" rd /s /q "%%p\AppData\Local\CrashDumps" >nul 2>&1
)
echo   Termine.

REM -- 5) Corbeille --------------------------------------------------------
echo.
echo  [5/9] Corbeille
powershell -NoProfile -Command "Clear-RecycleBin -Force -ErrorAction SilentlyContinue" >nul 2>&1
if exist "C:\$Recycle.Bin" rd /s /q "C:\$Recycle.Bin" >nul 2>&1
echo   Termine.

REM -- 6) Journaux d'evenements et rapports d'erreurs ----------------------
REM  Un journal d'evenements plein raconte l'histoire du poste de reference :
REM  il n'a rien a faire sur les postes deployes, et il pese.
echo.
echo  [6/9] Journaux d'evenements et rapports d'erreurs
for /f "delims=" %%L in ('wevtutil el 2^>nul') do wevtutil cl "%%L" >nul 2>&1
if exist "C:\ProgramData\Microsoft\Windows\WER" rd /s /q "C:\ProgramData\Microsoft\Windows\WER" >nul 2>&1
if exist "C:\Windows\Logs\CBS" rd /s /q "C:\Windows\Logs\CBS" >nul 2>&1
if exist "C:\Windows\Minidump" rd /s /q "C:\Windows\Minidump" >nul 2>&1
if exist "C:\Windows\MEMORY.DMP" del /f /q "C:\Windows\MEMORY.DMP" >nul 2>&1
echo   Termine.

REM -- 7) Mise en veille prolongee -----------------------------------------
REM  hiberfil.sys pese l'equivalent d'une bonne partie de la memoire vive.
REM  Il est exclu a la capture, mais autant ne pas le garder sur le modele.
echo.
echo  [7/9] Mise en veille prolongee (hiberfil.sys)
powercfg /h off >nul 2>&1
echo   Desactivee.

REM -- 8) Restes divers ----------------------------------------------------
echo.
echo  [8/9] Restes divers
if exist "C:\Windows\Prefetch" rd /s /q "C:\Windows\Prefetch" >nul 2>&1
md "C:\Windows\Prefetch" >nul 2>&1
if exist "C:\Windows.old" rd /s /q "C:\Windows.old" >nul 2>&1
if exist "C:\ProgramData\Microsoft\Windows Defender\Scans\History" rd /s /q "C:\ProgramData\Microsoft\Windows Defender\Scans\History" >nul 2>&1
REM  Points de restauration : inutiles sur un modele, et parfois volumineux.
vssadmin delete shadows /all /quiet >nul 2>&1
echo   Termine.

REM -- 9) Bilan ------------------------------------------------------------
echo.
echo  [9/9] Bilan
for /f %%a in ('powershell -NoProfile -Command "(Get-PSDrive C).Free"') do set APRES=%%a
REM  Le bilan est calcule par PowerShell, avec un garde-fou : si l'une des deux
REM  mesures a echoue, on le dit au lieu d'afficher un chiffre faux.
powershell -NoProfile -Command ^
  "$a='%AVANT%'; $b='%APRES%';" ^
  "if ($a -match '^^\d+$' -and $b -match '^^\d+$') {" ^
  "  '   Avant : {0,7:N1} Go libres' -f ([double]$a/1GB);" ^
  "  '   Apres : {0,7:N1} Go libres' -f ([double]$b/1GB);" ^
  "  '   GAGNE : {0,7:N1} Go' -f (([double]$b-[double]$a)/1GB) }" ^
  "else { '   (mesure de l espace libre indisponible)' }"
echo. >> "%LOG%"
echo Avant : %AVANT% octets libres >> "%LOG%"
echo Apres : %APRES% octets libres >> "%LOG%"

REM ====================================================================
REM  CONTROLES AVANT SYSPREP - ce sont les trois causes d'echec les plus
REM  frequentes, et sysprep ne les annonce qu'apres coup, dans un journal.
REM ====================================================================
echo.
echo   =====================================================================
echo     CONTROLES AVANT GENERALISATION
echo   =====================================================================
echo.

set ALERTE=0

REM  1. Appartenance au domaine. sysprep /generalize retire le poste du
REM     domaine, mais un modele prepare HORS domaine evite bien des surprises
REM     (strategies deja appliquees, profils, certificats du poste).
for /f "delims=" %%d in ('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).PartOfDomain"') do set DOM=%%d
if /i "%DOM%"=="True" (
  echo   [!] Ce poste est JOINT AU DOMAINE.
  echo       sysprep l'en retirera, mais un modele prepare hors domaine est
  echo       plus sur : pas de strategies deja appliquees, pas de certificat
  echo       machine, pas de profils du domaine dans l'image.
  set ALERTE=1
) else (
  echo   [ok] Poste hors domaine.
)

REM  2. Profils utilisateur. Chaque profil part dans l'image et la gonfle ;
REM     certains font aussi echouer sysprep.
for /f %%n in ('powershell -NoProfile -Command "(Get-ChildItem C:\Users -Directory ^| Where-Object { $_.Name -notin @('Public','Default','Default User','All Users') }).Count"') do set NPROF=%%n
if not "%NPROF%"=="0" (
  echo   [!] %NPROF% profil^(s^) utilisateur present^(s^) dans C:\Users.
  echo       Ils seront captures tels quels. Supprimez ceux qui ne servent pas
  echo       ^(Parametres ^> Systeme ^> A propos ^> Parametres avances^).
  set ALERTE=1
) else (
  echo   [ok] Aucun profil utilisateur superflu.
)

REM  3. Redemarrage en attente : sysprep refuse de tourner dans cet etat.
set PENDING=0
reg query "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending" >nul 2>&1 && set PENDING=1
reg query "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired" >nul 2>&1 && set PENDING=1
if "%PENDING%"=="1" (
  echo   [!] Un REDEMARRAGE est en attente. sysprep echouera tant qu'il n'aura
  echo       pas eu lieu. Redemarrez, puis relancez ce script.
  set ALERTE=1
) else (
  echo   [ok] Aucun redemarrage en attente.
)

echo.
echo   Journal complet : %LOG%
echo.

if "%ALERTE%"=="1" (
  echo   ---------------------------------------------------------------------
  echo    Des points d'attention sont signales ci-dessus. Traitez-les avant
  echo    de generaliser, sinon la capture risque d'etre inutilisable.
  echo   ---------------------------------------------------------------------
  echo.
)

REM -- Generalisation, proposee et jamais imposee --------------------------
echo   DERNIERE ETAPE : la generalisation ^(sysprep^).
echo.
echo   Elle retire l'identite du poste ^(SID, nom, domaine, materiel^) pour que
echo   l'image soit deployable ailleurs. SANS ELLE, l'image n'est PAS
echo   utilisable sur d'autres postes : SID en double, conflits de noms.
echo.
echo   Le poste s'ETEINDRA a la fin. Redemarrez-le ensuite en PXE et choisissez
echo   l'option [3] Capturer ce poste.
echo.
echo   ATTENTION : apres generalisation, ne rouvrez PAS de session Windows sur
echo   ce poste - cela annulerait la preparation. Passez directement au PXE.
echo.
set SP=
set /p SP=  Lancer sysprep maintenant ? Tapez OUI (ou Entree pour le faire plus tard) :
if /i not "%SP%"=="OUI" goto fin

echo.
echo   Generalisation en cours, le poste va s'eteindre...
"%SystemRoot%\System32\Sysprep\sysprep.exe" /generalize /oobe /shutdown
if errorlevel 1 (
  echo.
  echo   [ERREUR] sysprep a echoue. La cause exacte est dans :
  echo     %SystemRoot%\System32\Sysprep\Panther\setupact.log
  echo   Cause la plus frequente : une application du Store installee pour un
  echo   utilisateur mais pas provisionnee pour tous.
  pause
)
goto fin

:fin
echo.
echo   Termine. Pour capturer : demarrage reseau ^(PXE^) puis option [3].
echo.
pause
endlocal
