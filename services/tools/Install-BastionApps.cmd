@echo off
REM ====================================================================
REM  Bastion - Install-BastionApps.cmd
REM  (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
REM
REM  Installe MAINTENANT les applications du catalogue Bastion, sans
REM  attendre un redemarrage.
REM
REM  Pourquoi : le deploiement d'applications passe par un SCRIPT DE
REM  DEMARRAGE de GPO, qui ne s'execute qu'au boot du poste (jamais sur
REM  un « gpupdate »). Si le traitement de la strategie ORDINATEUR echoue
REM  au demarrage (horloge desynchronisee -> Kerberos -> SYSVOL illisible),
REM  le script n'est jamais lance et rien ne s'installe.
REM
REM  Ce lanceur execute le MEME script que la GPO, lu directement dans
REM  SYSVOL : le catalogue reste donc la seule source de verite (pas de
REM  liste dupliquee ici, rien a maintenir).
REM
REM  A executer en tant qu'ADMINISTRATEUR sur le poste.
REM ====================================================================
setlocal
net session >nul 2>&1 || (echo [ERREUR] Relancez ce script en tant qu'Administrateur. & pause & exit /b 1)

set "DC=dc.bastion.pn.int"
set "REALM=bastion.pn.int"
set "PS1="

echo Recherche du script d'installation dans SYSVOL...
for /f "delims=" %%F in ('dir /s /b "\\%DC%\sysvol\%REALM%\Policies\bastion-apps.ps1" 2^>nul') do set "PS1=%%F"

if not defined PS1 (
  echo [ERREUR] Script introuvable dans \\%DC%\sysvol\%REALM%\Policies\
  echo          Verifiez que le catalogue d'applications a bien ete deploye
  echo          depuis la console Bastion ^(page Active Directory^).
  pause & exit /b 1
)

echo Script trouve : %PS1%
echo.
echo Installation en cours ^(silencieuse, cela peut prendre plusieurs minutes^)...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%"

echo.
echo === Applications marquees comme installees ===
reg query "HKLM\Software\Bastion\Apps" 2>nul || echo   ^(aucune pour l'instant^)
echo.
echo Termine. Les applications deja installees sont ignorees aux prochains lancements.
endlocal
