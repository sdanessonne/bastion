@echo off
rem Bastion - (c) 2026 Mickael MONESTIER (Mle 110.480). Tous droits reserves.
rem Script de demarrage WinPE injecte dans boot.wim (INDEX 2 - Windows Setup).
rem Propose : installation AUTOMATISEE de Windows 11 Pro, DEPLOIEMENT d'une image
rem master (restauration rapide) ou CAPTURE d'un poste de reference.
rem
rem ---------------------------------------------------------------------------
rem ENCODAGE - lire avant de modifier l'affichage
rem ---------------------------------------------------------------------------
rem Ce fichier est en UTF-8 dans le depot ; setup-pxe.sh le convertit en CP437
rem (iconv) et en fins de ligne CRLF avant de l'injecter dans boot.wim.
rem
rem PAS de couleurs ANSI : WinPE utilise conhost, qui n'active PAS le traitement
rem des sequences d'echappement (verifie : GetConsoleMode = 0x0003, le bit
rem ENABLE_VIRTUAL_TERMINAL_PROCESSING est absent). Un "ESC[96m" s'afficherait donc
rem en clair a l'ecran. Seuls "color" (global) et les semi-graphiques fonctionnent.
rem
rem N'utiliser QUE des cadres PURS  =      -            et des
rem blocs    : ceux-la occupent les MEMES positions en CP437 et CP850, donc ils
rem s'affichent bien quelle que soit la langue du WinPE. En revanche les jonctions
rem MIXTES simple/double - celles qui melangent un trait simple et un trait double -
rem sont a PROSCRIRE : CP850 a reaffecte leurs positions a des lettres accentuees,
rem elles s'afficheraient donc en A-accent-aigu sur un WinPE francais.
rem Texte sans accent, pour la meme raison.
rem
rem RAPPEL BATCH : dans un bloc parenthese, toute parenthese litterale doit etre
rem echappee ^( ^) sinon elle referme le bloc et cmd echoue sur le reste de la
rem ligne. Le bloc est analyse DES QU'IL EST LU, meme si la condition est fausse.
rem ---------------------------------------------------------------------------
wpeinit
chcp 437 >nul 2>&1
color 1F
set GW=__DNS_IP__
set NETOK=0
set IP=-
set NETLBL=KO

rem Type d'amorcage : detecte une fois pour toutes, affiche des le menu puis
rem reutilise par les options [1] et [2] (choix du partitionnement).
set FW=BIOS
reg query HKLM\System\CurrentControlSet\Control /v PEFirmwareType 2>nul | find "0x2" >nul && set FW=UEFI

rem Reseau initialise UNE SEULE FOIS, ici : le menu affiche l'etat reel et les
rem options n'ont plus a attendre. Un echec n'est pas bloquant - l'option [4] doit
rem rester accessible pour diagnostiquer, et [1]/[2]/[3] rappelleront :netinit qui
rem reaffichera l'erreur en clair.
rem NON silencieux : sans reseau, :netinit peut tourner ~25 s. Mieux vaut afficher
rem sa progression qu'un ecran noir. Le " cls " du menu nettoie ensuite.
call :netinit

:menu
cls
rem MISE EN PAGE : la console WinPE fait 80 colonnes sur 25 lignes. Ce menu occupe
rem 24 lignes sur 75 colonnes - toute ligne ajoutee ferait defiler le logo hors de
rem l'ecran, toute ligne de plus de 80 caracteres serait repliee. Verifie au rendu.
echo.
echo    ╔═════════════════════════════════════════════════════════════════╗
echo    ║   █▀▀▄ █▀▀█ █▀▀ ▀▀█▀▀ ▀█▀ █▀▀█ █▄ █                             ║
echo    ║   █▀▀▄ █▄▄█ ▀▀█   █    █  █  █ █ ▀█    Deploiement Windows      ║
echo    ║   ▀▀▀  ▀  ▀ ▀▀▀   ▀   ▀▀▀ ▀▀▀▀ ▀  ▀    par le reseau            ║
echo    ╚═════════════════════════════════════════════════════════════════╝
echo.
echo      ┌───┬─────────────────────────────────────────────────────────┐
echo      │ 1 │  Installer Windows 11 Pro                               │
echo      │   │  Automatise de bout en bout - le disque 0 sera EFFACE   │
echo      ├───┼─────────────────────────────────────────────────────────┤
echo      │ 2 │  Deployer l'image master                                │
echo      │   │  Restauration rapide depuis la bibliotheque             │
echo      ├───┼─────────────────────────────────────────────────────────┤
echo      │ 3 │  Capturer ce poste                                      │
echo      │   │  Cree l'image master - exige un poste sysprepe          │
echo      ├───┼─────────────────────────────────────────────────────────┤
echo      │ 4 │  Invite de commandes                                    │
echo      │   │  Diagnostic reseau et disque                            │
echo      └───┴─────────────────────────────────────────────────────────┘
echo.
rem Le separateur est le semi-graphique  (CP437 0xB3), PAS la barre verticale ASCII
rem " | " qui serait interpretee par cmd comme un TUBE.
echo      Poste %IP%    Passerelle %GW% %NETLBL%    Amorcage %FW%
echo.
set CH=
set /p CH=      Votre choix [1-4] :
if "%CH%"=="1" goto setup
if "%CH%"=="2" goto deploy
if "%CH%"=="3" goto capture
if "%CH%"=="4" goto shell
goto menu

rem ----------------------------------------------------------- reseau (commun)
:netinit
if "%NETOK%"=="1" exit /b 0
echo.
echo  Initialisation du reseau...
set N=0
:nwait
set /a N+=1
rem 1) Attendre une VRAIE adresse du LAN. Si le DHCP echoue, Windows s'auto-attribue une
rem    adresse APIPA 169.254.x.x : le poste "a une IP" mais n'a AUCUNE route -> erreur 53.
ipconfig | find "192.168.182." >nul 2>&1
if not errorlevel 1 goto haveip
if %N%==8 ipconfig /renew >nul 2>&1
if %N% GEQ 25 goto nfail
ping -n 2 127.0.0.1 >nul 2>&1
goto nwait
:garde_ip
rem Retient l'adresse seulement si elle est sur le reseau du commissariat : le filtre
rem " IPv4 " seul attraperait AUSSI la passerelle par defaut, du meme sous-reseau.
set "V=%V: =%"
if "%V:~0,12%"=="192.168.182." set "IP=%V%"
goto :eof

:haveip
ipconfig | find "IPv4"
rem Adresse retenue pour l'affichage du menu. DOUBLE filtre : "192.168.182." seul
rem attraperait AUSSI la ligne "Passerelle par defaut" (meme sous-reseau) et le
rem menu afficherait l'IP de la passerelle a la place de celle du poste.
rem " findstr " N'EXISTE PAS dans WinPE : son absence faisait echouer la detection
rem en silence, et le menu affichait " Poste - " sans adresse. On n'utilise donc que
rem " find " (present) et des commandes internes.
set IP=
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| find "IPv4"') do (
  if not defined IP ( set "V=%%a" & call :garde_ip )
)
set IP=%IP: =%
rem 2) Verifier une VRAIE reponse de la passerelle. ATTENTION : le code retour de ping
rem    vaut 0 meme sur "Destination host unreachable" -> on teste la presence de "TTL=".
ping -n 2 %GW% | find "TTL=" >nul 2>&1
if errorlevel 1 goto nfail
:nok
rem Windows 10/11 refuse par defaut les partages en acces invite. La bibliotheque
rem est en LECTURE SEULE et anonyme : aucun mot de passe n'est stocke dans boot.wim
rem (rappel : boot.wim est telechargeable avant authentification au portail).
rem ATTENTION : ne JAMAIS faire "net stop LanmanWorkstation" ici -> cela coupe le client
rem SMB et tout "net use" renvoie alors l'erreur systeme 53 (chemin reseau introuvable).
reg add "HKLM\SYSTEM\CurrentControlSet\Services\LanmanWorkstation\Parameters" /v AllowInsecureGuestAuth /t REG_DWORD /d 1 /f >nul 2>&1
net start LanmanWorkstation >nul 2>&1
set NETOK=1
set NETLBL=OK
exit /b 0
:nfail
set NETLBL=KO
echo.
echo  ERREUR : reseau indisponible - pas d adresse du LAN, ou %GW% injoignable.
echo  --- Diagnostic ---
ipconfig | find "IPv4"
echo  Une adresse en 169.254.x.x = le DHCP a echoue.
echo  Test de la passerelle :
ping -n 2 %GW%
echo  ------------------
exit /b 1

rem ----------------------------------------------- montage d'un partage (%1 = nom)
rem Ouvre la session vers \\%GW%\%1. 1re tentative silencieuse ; si echec, on reessaie
rem en affichant l'erreur exacte.
:mount
rem %1 = nom du partage. On ouvre la session puis on travaille en chemins UNC directs
rem (\\gw\Install\setup.exe), sans lettre de lecteur : inutile ici, et ca marche partout.
rem
rem POURQUOI LA BOUCLE DE 15 TENTATIVES - la vraie cause de l'" erreur systeme 53 " :
rem quand un poste redemarre, il n'envoie aucun FIN, donc Samba garde sa session TCP
rem ouverte. Or Windows REUTILISE le meme port source ephemere au demarrage suivant :
rem le serveur voit alors un SYN sur une connexion qu'il croit deja etablie, repond ACK
rem au lieu de SYN-ACK, et le client echoue avec l'erreur 53. Diagnostique au tcpdump,
rem confirme par smbstatus. C'est pour cela qu'une commande tapee A LA MAIN reussissait
rem (autre port source) alors que le script echouait apres chaque redemarrage.
rem Correctif de fond cote serveur : " keepalive = 30 " dans smb.conf (voir setup-ad.sh)
rem purge les sessions mortes en ~30 s. La boucle ci-dessous couvre ce delai.
set SHR=%~1
set TGT=\\%GW%\%SHR%
echo   cible = [%TGT%]
set M=0
:mretry
set /a M+=1
net use %TGT% >nul 2>&1
if not errorlevel 1 (
  echo   connecte apres %M% tentative^(s^).
  exit /b 0
)
net start LanmanWorkstation >nul 2>&1
if %M% GEQ 15 goto mfail
echo   client SMB pas encore pret, nouvelle tentative %M%/15...
ping -n 4 127.0.0.1 >nul 2>&1
goto mretry
:mfail
echo   echec apres %M% tentatives. Erreur exacte :
net use %TGT%
exit /b 1

rem ------------------------------------------- [1] installation classique
:setup
call :netinit || goto pause_menu
echo  Connexion a la source d installation...
call :mount Install
if not exist \\%GW%\Install\setup.exe (
  echo.
  echo  ERREUR : source introuvable sur \\%GW%\Install
  echo  Voir le message de "net use" ci-dessus.
  goto pause_menu
)
call :mount Images >nul 2>&1
rem Type d'amorcage -> choisit le partitionnement du fichier de reponses
set FW=bios
reg query HKLM\System\CurrentControlSet\Control /v PEFirmwareType 2>nul | find "0x2" >nul && set FW=uefi
set ANS=\\%GW%\Images\unattend-%FW%.xml
if not exist %ANS% (
  echo.
  rem Parentheses ECHAPPEES (^( ^)) : obligatoire dans un bloc " ( ... ) ", sinon le " ) "
  rem referme le bloc et cmd echoue sur le reste de la ligne (" : etait inattendu ").
  echo  Fichier de reponses absent ^(%ANS%^) : installation MANUELLE.
  \\%GW%\Install\setup.exe
  goto fin
)
rem Contournement des prerequis Windows 11, pose AUSSI ici : le fichier de reponses le
rem refait, mais ces cles doivent exister avant les tout premiers controles de setup.
reg add HKLM\SYSTEM\Setup\LabConfig /v BypassTPMCheck        /t REG_DWORD /d 1 /f >nul 2>&1
reg add HKLM\SYSTEM\Setup\LabConfig /v BypassSecureBootCheck /t REG_DWORD /d 1 /f >nul 2>&1
reg add HKLM\SYSTEM\Setup\LabConfig /v BypassCPUCheck        /t REG_DWORD /d 1 /f >nul 2>&1
reg add HKLM\SYSTEM\Setup\LabConfig /v BypassRAMCheck        /t REG_DWORD /d 1 /f >nul 2>&1
reg add HKLM\SYSTEM\Setup\LabConfig /v BypassStorageCheck    /t REG_DWORD /d 1 /f >nul 2>&1
echo.
echo   ---------------------------------------------------------
echo     Installation AUTOMATIQUE de Windows 11 Pro
echo     Amorcage : %FW%          Controles materiel : contournes
echo     ATTENTION : le disque 0 va etre EFFACE.
echo   ---------------------------------------------------------
echo.
set OK=
set /p OK=  Tapez OUI en majuscules pour lancer :
if /i not "%OK%"=="OUI" goto menu
echo.
echo  Lancement... (aucune intervention jusqu a la creation du compte local)
\\%GW%\Install\setup.exe /unattend:%ANS%
goto fin

rem ------------------------------------------- [2] deploiement de l'image
:deploy
call :netinit || goto pause_menu
echo  Connexion a la bibliotheque d images...
call :mount Images
if not exist \\%GW%\Images\master.wim (
  echo.
  echo  ERREUR : aucune image master trouvee ^(\\%GW%\Images\master.wim^).
  echo  Creez-en une d abord avec l option [3] depuis un poste de reference.
  goto pause_menu
)
echo.
echo  Image trouvee :
rem Sans findstr (absent de WinPE) : on affiche la fiche complete de l'image.
rem Quelques lignes de plus, mais l'information est la et la commande ne peut plus echouer.
Dism /Get-ImageInfo /ImageFile:\\%GW%\Images\master.wim /Index:1
echo.
echo   *****************************************************
echo    ATTENTION : le DISQUE 0 de ce poste va etre EFFACE
echo    et remplace par l image master. Donnees perdues.
echo   *****************************************************
echo.
set OK=
set /p OK=  Tapez OUI en majuscules pour confirmer :
if /i not "%OK%"=="OUI" goto menu

rem Type d'amorcage : UEFI (GPT) ou BIOS (MBR)
set FW=BIOS
reg query HKLM\System\CurrentControlSet\Control /v PEFirmwareType 2>nul | find "0x2" >nul && set FW=UEFI
echo.
echo  Mode d amorcage detecte : %FW%
echo  Partitionnement du disque 0...
if "%FW%"=="UEFI" goto dp_uefi

:dp_bios
> X:\dp.txt echo select disk 0
>> X:\dp.txt echo clean
>> X:\dp.txt echo create partition primary
>> X:\dp.txt echo format quick fs=ntfs label="Windows"
>> X:\dp.txt echo assign letter=W
>> X:\dp.txt echo active
>> X:\dp.txt echo exit
goto dp_run

:dp_uefi
> X:\dp.txt echo select disk 0
>> X:\dp.txt echo clean
>> X:\dp.txt echo convert gpt
>> X:\dp.txt echo create partition efi size=260
>> X:\dp.txt echo format quick fs=fat32 label="Systeme"
>> X:\dp.txt echo assign letter=S
>> X:\dp.txt echo create partition msr size=16
>> X:\dp.txt echo create partition primary
>> X:\dp.txt echo format quick fs=ntfs label="Windows"
>> X:\dp.txt echo assign letter=W
>> X:\dp.txt echo exit

:dp_run
diskpart /s X:\dp.txt
if errorlevel 1 (
  echo  ERREUR : le partitionnement a echoue.
  goto pause_menu
)
echo.
echo  Restauration de l image sur W:
echo.
echo   La duree depend surtout de la TAILLE DEPLOYEE de l image, pas du reseau :
echo   le transfert ne represente que quelques minutes, l essentiel du temps
echo   part en decompression et en ecriture disque. La fiche affichee plus haut
echo   indique le volume a ecrire.
echo.
rem  /ScratchDir : les fichiers temporaires de DISM vont sur le disque LOCAL
rem  fraichement formate, et non sur le disque virtuel X: de WinPE, qui vit en
rem  memoire vive et est etroit.
Dism /Apply-Image /ImageFile:\\%GW%\Images\master.wim /Index:1 /ApplyDir:W:\ /ScratchDir:W:\
if errorlevel 1 (
  echo  ERREUR : la restauration de l image a echoue.
  goto pause_menu
)
echo.
echo  Ecriture du demarrage...
if "%FW%"=="UEFI" (bcdboot W:\Windows /s S: /f UEFI) else (bcdboot W:\Windows /s W: /f BIOS)
if errorlevel 1 (
  echo  ERREUR : l ecriture du demarrage a echoue.
  goto pause_menu
)
echo.
echo   =====================================================
echo     DEPLOIEMENT TERMINE. Retirez l amorcage reseau
echo     puis redemarrez sur le disque local.
echo   =====================================================
echo.
set RB=
set /p RB=  Redemarrer maintenant ? (O/N) :
if /i "%RB%"=="O" wpeutil reboot
goto menu

rem ------------------------------------------- [3] capture de l'image
:capture
cls
echo.
echo   ======================================================
echo      CAPTURE d un poste de reference en image master
echo   ======================================================
echo.
echo   PREREQUIS IMPORTANT : le poste doit avoir ete generalise avec
echo     C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown
echo   Sans cette etape, l image ne sera PAS deployable sur d autres postes
echo   (identifiants materiel et SID en double).
echo.
set OK=
set /p OK=  Le poste a-t-il ete generalise ? Tapez OUI :
if /i not "%OK%"=="OUI" goto menu
call :netinit || goto pause_menu
echo.
echo  L ecriture dans la bibliotheque exige un compte administrateur du domaine.
set USR=
set /p USR=  Utilisateur (ex. BASTION\Administrator) :
net use \\%GW%\ImagesRW /user:%USR% *
if errorlevel 1 (
  echo  ERREUR : authentification ou connexion refusee.
  goto pause_menu
)
rem Reperer la partition Windows du poste
set SRC=
for %%d in (C D E F) do if exist %%d:\Windows\System32\ntoskrnl.exe set SRC=%%d:
if "%SRC%"=="" (
  echo  ERREUR : aucune installation Windows trouvee sur ce poste.
  goto pause_menu
)
echo.
echo  Windows detecte sur %SRC%
if exist \\%GW%\ImagesRW\master.wim echo  NOTE : une image master existe deja, elle sera REMPLACEE.
set OK=
set /p OK=  Lancer la capture ? Tapez OUI :
if /i not "%OK%"=="OUI" goto menu
rem ============================================================================
rem  ALLEGEMENT DE L'IMAGE - c'est ICI que se joue le temps de restauration.
rem
rem  Mesure faite sur l'image du 23/07 : 17,8 Go transferes, mais 53,1 Go
rem  DEPLOYES et 220 957 fichiers. Le transfert reseau ne prend que 3 minutes
rem  a 1 Gb/s ; tout le reste du temps part en DECOMPRESSION et en ECRITURE
rem  disque. Autrement dit : la restauration est lente parce que l'image est
rem  GROSSE, pas parce que le reseau ou le partage sont mal regles.
rem
rem  Deux leviers sont appliques ci-dessous, plus un troisieme au moment de
rem  la compression (voir /Compress plus bas).
rem ============================================================================
echo.
echo  [1/3] Nettoyage du magasin de composants ^(WinSxS^)
echo.
echo   Windows conserve toutes les versions remplacees des composants mis a
echo   jour. Sur cette image, WinSxS pese 159 000 entrees. Le nettoyage les
echo   supprime et fait souvent gagner PLUSIEURS GIGAOCTETS -- donc autant de
echo   temps a chaque restauration, sur chaque poste.
echo.
echo   CONTREPARTIE : les mises a jour deja installees ne pourront plus etre
echo   desinstallees sur les postes deployes. C'est l'usage normal pour une
echo   image master, mais c'est irreversible : a vous de decider.
echo.
rem ============================================================================
rem  REPERTOIRE DE TRAVAIL DE DISM - la cause de l'echec du 28/07.
rem
rem  Par defaut DISM travaille dans X:\Windows\Temp, c'est-a-dire sur le disque
rem  EN MEMOIRE VIVE de WinPE, qui ne fait que quelques dizaines de megaoctets.
rem  DISM le signale lui-meme : " La taille du repertoire de travail peut etre
rem  insuffisante [...] La taille recommandee est d'au moins 1024 MB ", puis la
rem  capture echoue - DEUX FOIS DE SUITE EXACTEMENT A 31 %%, avec
rem  " Erreur : 6 - Descripteur non valide ".
rem
rem  Un echec qui se reproduit au MEME pourcentage n'est pas une coupure reseau :
rem  c'est une ressource qui manque toujours au meme endroit.
rem
rem  On lui donne donc un vrai dossier, sur le disque du poste. Il est AJOUTE A LA
rem  LISTE D'EXCLUSION plus bas : sans cela DISM capturerait son propre repertoire
rem  de travail, en train de grossir, a l'interieur de l'image.
rem ============================================================================
set SCRATCH=%SRC%\BastionScratch
if exist "%SCRATCH%" rd /s /q "%SCRATCH%" >nul 2>&1
md "%SCRATCH%" >nul 2>&1
if not exist "%SCRATCH%" goto scratch_repli
echo  Repertoire de travail : %SCRATCH%
goto scratch_ok
:scratch_repli
echo  AVERTISSEMENT : repertoire de travail impossible sur %SRC%
echo  La capture utilisera X: et risque d echouer faute de place.
set SCRATCH=X:\Windows\Temp
:scratch_ok

set NET=
set /p NET=  Nettoyer le magasin de composants ? ^(OUI / non^) :
if /i "%NET%"=="OUI" (
  echo   Nettoyage hors ligne en cours, patientez...
  Dism /Image:%SRC%\ /Cleanup-Image /StartComponentCleanup /ResetBase /ScratchDir:"%SCRATCH%"
  if errorlevel 1 echo   AVERTISSEMENT : le nettoyage a echoue, la capture continue.
) else (
  echo   Nettoyage ignore.
)

rem Liste d'exclusion : ce qui n'a AUCUNE raison de voyager dans une image
rem master. Fichier d'echange, mise en veille prolongee, corbeille, caches de
rem mise a jour et de livraison, temporaires. Rien d'utile n'est retire : ces
rem elements sont recrees par Windows au premier demarrage.
echo.
echo  [2/3] Exclusions
> X:\wimscript.ini echo [ExclusionList]
rem  Le repertoire de travail de DISM, EN PREMIER : il grossit pendant la capture,
rem  et sans cette exclusion DISM se capturerait lui-meme en train de travailler.
>>X:\wimscript.ini echo \BastionScratch
>>X:\wimscript.ini echo \BastionCapture
>>X:\wimscript.ini echo \pagefile.sys
>>X:\wimscript.ini echo \hiberfil.sys
>>X:\wimscript.ini echo \swapfile.sys
>>X:\wimscript.ini echo \System Volume Information
>>X:\wimscript.ini echo \$Recycle.Bin
>>X:\wimscript.ini echo \RECYCLER
>>X:\wimscript.ini echo \Windows\CSC
>>X:\wimscript.ini echo \Windows\Temp\*
>>X:\wimscript.ini echo \Windows\SoftwareDistribution\Download\*
>>X:\wimscript.ini echo \Windows\Prefetch\*
>>X:\wimscript.ini echo \Windows\Logs\CBS\*
>>X:\wimscript.ini echo \Windows\MEMORY.DMP
>>X:\wimscript.ini echo \Windows\Minidump\*
>>X:\wimscript.ini echo \Windows\ServiceProfiles\NetworkService\AppData\Local\Temp\*
>>X:\wimscript.ini echo \Users\*\AppData\Local\Temp\*
>>X:\wimscript.ini echo \Users\*\AppData\Local\Microsoft\Windows\INetCache\*
>>X:\wimscript.ini echo \Users\*\AppData\Local\Microsoft\Windows\Explorer\thumbcache_*.db
>>X:\wimscript.ini echo \ProgramData\Microsoft\Windows\DeliveryOptimization\*
>>X:\wimscript.ini echo \ProgramData\Microsoft\Windows Defender\Scans\*
>>X:\wimscript.ini echo.
rem Ne pas gaspiller de temps processeur a recompresser ce qui l'est deja.
>>X:\wimscript.ini echo [CompressionExclusionList]
>>X:\wimscript.ini echo *.mp3
>>X:\wimscript.ini echo *.zip
>>X:\wimscript.ini echo *.cab
>>X:\wimscript.ini echo *.wim
>>X:\wimscript.ini echo *.esd
>>X:\wimscript.ini echo \Windows\inf\*.pnf
echo   Liste d exclusion ecrite.

rem ============================================================================
rem  L'image en service n'est PLUS supprimee avant la capture.
rem
rem  Elle l'etait, et le 28/07 la capture a echoue a 31 % -- « erreur 6,
rem  descripteur non valide ». Le parc s'est retrouve SANS AUCUNE image master.
rem
rem  On capture desormais sous un nom PROVISOIRE, on verifie que le resultat est
rem  lisible, et l'image en service n'est remplacee qu'apres. Une capture
rem  interrompue ne coute plus que du temps.
rem
rem  Cela suppose que les deux images tiennent le temps de la capture. Si la
rem  place manque, DISM echoue et l'ancienne est toujours la : on perd une
rem  capture, pas le parc. C'est le bon sens de l'echec.
rem ============================================================================
:capture_lancer
set NEW=\\%GW%\ImagesRW\master.new.wim
if exist %NEW% del /f /q %NEW%

rem ============================================================================
rem  ON CAPTURE EN LOCAL, PUIS ON COPIE.
rem
rem  Ecrire un fichier de plusieurs gigaoctets sur un partage reseau pendant 30 a
rem  60 minutes, c'est demander a une session SMB de tenir sans faillir tout ce
rem  temps. Trois captures de suite ont echoue exactement a 31,0 % avec
rem  " Erreur : 6 - Descripteur non valide ".
rem
rem  On sort donc le reseau de l'operation longue : DISM ecrit sur le disque du
rem  poste, puis le fichier est COPIE. Une copie interrompue se relance sans
rem  refaire la capture -- et si l'echec a 31 % persiste malgre tout, il ne sera
rem  plus imputable au partage. Dans les deux cas on aura appris quelque chose.
rem
rem  Le dossier est exclu de la capture (voir wimscript.ini) : sans cela DISM
rem  capturerait le fichier qu'il est en train d'ecrire.
rem ============================================================================
set LOCAL=%SRC%\BastionCapture
if exist "%LOCAL%" rd /s /q "%LOCAL%" >nul 2>&1
md "%LOCAL%" >nul 2>&1
if not exist "%LOCAL%" goto capture_via_reseau
set OUT=%LOCAL%\master.wim
set MODE=local
echo.
echo  Capture ECRITE EN LOCAL : %OUT%
echo   Elle sera copiee sur le partage une fois terminee et verifiee.
goto capture_go
:capture_via_reseau
set OUT=%NEW%
set MODE=reseau
echo.
echo  AVERTISSEMENT : ecriture locale impossible sur %SRC%
echo   La capture ecrira directement sur le partage ^(moins fiable^).
:capture_go
echo.
echo  [3/3] Capture en cours...
rem  /Compress:FAST et non MAX. Ce choix est CONTRE-INTUITIF, il merite d'etre
rem  explique :
rem    - MAX  ^(LZX^)    : fichier le plus petit, mais decompression LENTE.
rem    - FAST ^(XPRESS^) : fichier ~25 %% plus gros, decompression 2 a 3x plus
rem                       rapide.
rem  Le fichier n'est transfere qu'une fois et le reseau n'est pas le facteur
rem  limitant ^(3 min sur 1 Gb/s^). La decompression, elle, est refaite sur
rem  CHAQUE poste, a chaque restauration, et c'est elle qui prend le temps.
rem  On optimise donc ce qui est repete, pas ce qui est unique.
Dism /Capture-Image /ImageFile:"%OUT%" /CaptureDir:%SRC%\ /Name:"Bastion Master" /Compress:fast /ConfigFile:X:\wimscript.ini /ScratchDir:"%SCRATCH%"
if not errorlevel 1 goto capture_valider

echo.
echo  ERREUR : la capture a echoue.
if exist %NEW% del /f /q %NEW%
echo   Le fichier partiel a ete supprime.
if exist \\%GW%\ImagesRW\master.wim echo   L image master precedente est INTACTE : le parc reste deployable.

rem ============================================================================
rem  ON MONTRE LE JOURNAL, ON NE RENVOIE PLUS VERS LUI.
rem
rem  Trois echecs successifs, toujours EXACTEMENT a 31,0 %%, ont ete attribues
rem  tour a tour a une temporisation Samba puis a un repertoire de travail trop
rem  petit. Les deux ont ete corriges ; l'echec est reste identique. Autrement
rem  dit : trois hypotheses, aucune preuve. Le journal de DISM, lui, contient la
rem  cause exacte -- il etait juste sur le poste, que personne ne lisait.
rem
rem  Il est desormais affiche a l'ecran ET recopie sur le partage, pour pouvoir
rem  etre examine depuis la console sans revenir sur le poste.
rem ============================================================================
echo.
echo   ================= JOURNAL DISM - CAUSE EXACTE =================
if not exist X:\windows\Logs\DISM\dism.log goto capture_nolog
rem  " find " et non " findstr " : findstr N'EXISTE PAS dans WinPE.
type X:\windows\Logs\DISM\dism.log | find /i "error" | more +0
echo.
echo   --- fin du journal ---
type X:\windows\Logs\DISM\dism.log | more +0 > X:\dismtail.txt
copy /y X:\windows\Logs\DISM\dism.log \\%GW%\ImagesRW\dism-echec.log >nul 2>&1
if exist \\%GW%\ImagesRW\dism-echec.log echo   Journal complet copie sur le partage : dism-echec.log
goto capture_apres_log
:capture_nolog
echo   Journal introuvable ^(X:\windows\Logs\DISM\dism.log^).
:capture_apres_log
echo   ==============================================================
echo.
echo   Manque de place : supprimez l ancienne image depuis la console
echo   ^(Postes ^> Images^) et relancez la capture.
goto pause_menu

:capture_valider
rem  On VERIFIE avant de remplacer. Une image illisible se detecte ici, sur le
rem  poste de reference, et non le jour ou un poste en a besoin -- le menu de
rem  deploiement, lui, ne teste que l'EXISTENCE du fichier.
echo.
echo  Verification de l image capturee...
Dism /Get-ImageInfo /ImageFile:%NEW% /Index:1 >nul
if errorlevel 1 (
  echo  ERREUR : l image capturee est illisible, elle est ecartee.
  del /f /q %NEW%
  if exist \\%GW%\ImagesRW\master.wim echo   L image master precedente est INTACTE.
  goto pause_menu
)
echo   Image lisible.
rem  Menage : le repertoire de travail ne doit pas rester sur le poste.
if exist "%SCRATCH%" rd /s /q "%SCRATCH%" >nul 2>&1

if "%MODE%"=="reseau" goto capture_en_service
echo.
echo  Copie vers le partage ^(cette etape, elle, se relance sans refaire la capture^)...
copy /y "%OUT%" %NEW% >nul
if errorlevel 1 (
  echo  ERREUR : la copie vers le partage a echoue.
  echo   L image capturee reste sur le poste : %OUT%
  echo   Relancez l option [3] ou copiez-la a la main -- la capture, elle, est bonne.
  goto pause_menu
)
echo   Copie terminee.
rd /s /q "%LOCAL%" >nul 2>&1

:capture_en_service

echo  Mise en service...
if exist \\%GW%\ImagesRW\master.wim del /f /q \\%GW%\ImagesRW\master.wim
move /y %NEW% \\%GW%\ImagesRW\master.wim >nul
if errorlevel 1 (
  echo  ERREUR : le renommage a echoue. L image est disponible sous
  echo    master.new.wim -- renommez-la a la main en master.wim.
  goto pause_menu
)
echo.
echo   =====================================================
echo     CAPTURE TERMINEE ET VERIFIEE. L image est disponible
echo     pour le deploiement (option [2]) sur les autres postes.
echo   =====================================================
goto pause_menu

rem ------------------------------------------------------- divers
:pause_menu
echo.
echo  Appuyez sur une touche pour revenir au menu ^(ou fermez pour l invite^)...
pause >nul
goto menu

:shell
echo.
echo  Invite de commandes. Tapez "exit" pour revenir au menu.
echo.
cmd /k
goto menu

:fin
