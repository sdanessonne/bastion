@echo off
REM ====================================================================
REM  Bastion - Install-BastionTimeGuard.cmd
REM  (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
REM
REM  A executer UNE FOIS, en Administrateur, sur un poste du domaine.
REM
REM  Brise le cercle vicieux de l'horloge des VM : au boot l'horloge derive
REM  au-dela de la tolerance Kerberos (5 min) -> la passe GPO ORDINATEUR
REM  echoue a lire SYSVOL -> la GPO qui doit recaler l'heure (script de
REM  demarrage) ne s'execute jamais -> l'heure reste fausse.
REM
REM  Ce filet est LOCAL et ne depend PAS de Kerberos : client NTP direct
REM  (UDP 123, IP, sans DNS) + correction d'ecart illimitee + tache ONSTART
REM  avec reessais, qui relance gpupdate /target:computer une fois l'heure
REM  bonne. Idempotent, aucun secret.
REM ====================================================================
setlocal
net session >nul 2>&1 || (echo [ERREUR] Relancez ce script en tant qu'Administrateur. & pause & exit /b 1)

set "SVC=HKLM\SYSTEM\CurrentControlSet\Services\W32Time"
set "PEER=192.168.182.1,0x9"

echo [1/4] Durcissement du service de temps (W32Time)...
reg add "%SVC%\Config"     /v MaxPosPhaseCorrection /t REG_DWORD /d 0xFFFFFFFF /f >nul
reg add "%SVC%\Config"     /v MaxNegPhaseCorrection /t REG_DWORD /d 0xFFFFFFFF /f >nul
reg add "%SVC%\Parameters" /v Type      /t REG_SZ /d NTP      /f >nul
reg add "%SVC%\Parameters" /v NtpServer /t REG_SZ /d "%PEER%" /f >nul
reg add "%SVC%\TimeProviders\NtpClient" /v Enabled            /t REG_DWORD /d 1   /f >nul
reg add "%SVC%\TimeProviders\NtpClient" /v SpecialPollInterval /t REG_DWORD /d 300 /f >nul
sc.exe config w32time start= auto >nul
REM Attendre le reseau au demarrage/ouverture de session : fiabilise GP + lecteurs (sans GPO).
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\CurrentVersion\Winlogon" /v SyncForegroundPolicy /t REG_DWORD /d 1 /f >nul
tzutil /s "Romance Standard Time"

echo [2/4] Ecriture du worker C:\ProgramData\Bastion\time-guard.cmd ...
if not exist "C:\ProgramData\Bastion" mkdir "C:\ProgramData\Bastion"
> "C:\ProgramData\Bastion\time-guard.cmd" echo @echo off
>>"C:\ProgramData\Bastion\time-guard.cmd" echo w32tm /config /manualpeerlist:"192.168.182.1,0x9" /syncfromflags:manual /update
>>"C:\ProgramData\Bastion\time-guard.cmd" echo sc.exe config w32time start= auto
>>"C:\ProgramData\Bastion\time-guard.cmd" echo net start w32time
>>"C:\ProgramData\Bastion\time-guard.cmd" echo set /a n=0
>>"C:\ProgramData\Bastion\time-guard.cmd" echo :loop
>>"C:\ProgramData\Bastion\time-guard.cmd" echo set /a n+=1
>>"C:\ProgramData\Bastion\time-guard.cmd" echo w32tm /resync /rediscover /force
>>"C:\ProgramData\Bastion\time-guard.cmd" echo if %%errorlevel%% EQU 0 goto ok
>>"C:\ProgramData\Bastion\time-guard.cmd" echo if %%n%% GEQ 30 goto end
>>"C:\ProgramData\Bastion\time-guard.cmd" echo timeout /t 10 /nobreak ^>nul
>>"C:\ProgramData\Bastion\time-guard.cmd" echo goto loop
>>"C:\ProgramData\Bastion\time-guard.cmd" echo :ok
>>"C:\ProgramData\Bastion\time-guard.cmd" echo gpupdate /target:computer /force
>>"C:\ProgramData\Bastion\time-guard.cmd" echo :end

echo [3/4] Creation de la tache planifiee ONSTART (SYSTEM, delai 10 s)...
schtasks /create /tn "Bastion - Recaler horloge au demarrage" /ru SYSTEM /rl HIGHEST ^
  /sc ONSTART /delay 0000:10 /tr "C:\ProgramData\Bastion\time-guard.cmd" /f

echo [4/4] Application immediate...
net stop w32time >nul 2>&1
net start w32time >nul 2>&1
call "C:\ProgramData\Bastion\time-guard.cmd"

echo.
echo === Termine. Etat du service de temps : ===
w32tm /query /status
echo.
echo Tache installee : "Bastion - Recaler horloge au demarrage"
echo Redemarrez le poste pour valider le recalage automatique au boot.
endlocal
