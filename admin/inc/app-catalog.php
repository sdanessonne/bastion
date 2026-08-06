<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Catalogue d'applications du store Bastion.
 * Chaque entrée = logiciel courant récupérable depuis sa SOURCE OFFICIELLE, avec ses
 * arguments d'installation silencieuse. Le store (admin/apps.php) télécharge l'installeur
 * vers la passerelle en un clic, puis une GPO de démarrage l'installe sur les postes.
 *
 * Champs : name, icon, cat (catégorie), msi (bool), args (silencieux), url (source), desc.
 * NB : les versions des éditeurs évoluent ; les URL pointent vers la dernière version connue
 * (ou un lien « latest » stable quand l'éditeur en fournit un). Un échec de téléchargement
 * n'est pas bloquant : l'administrateur peut toujours ajouter l'installeur manuellement.
 *
 * @return array<string,array{name:string,icon:string,cat:string,msi:bool,args:string,url:string,desc:string}>
 */
return [
    // ── Navigateurs ──────────────────────────────────────────────────────────
    'chrome'    => ['name'=>'Google Chrome (Entreprise)','icon'=>'🌐','cat'=>'Navigateurs','msi'=>true,'args'=>'/qn /norestart','url'=>'https://dl.google.com/edgedl/chrome/install/GoogleChromeStandaloneEnterprise64.msi','desc'=>'Navigateur web Google Chrome, édition entreprise (MSI gérable par GPO).'],
    'firefox'   => ['name'=>'Mozilla Firefox','icon'=>'🦊','cat'=>'Navigateurs','msi'=>false,'args'=>'-ms','url'=>'https://download.mozilla.org/?product=firefox-latest-ssl&os=win64&lang=fr','desc'=>'Navigateur web Mozilla Firefox (français, dernière version).'],
    'firefoxesr'=> ['name'=>'Mozilla Firefox ESR','icon'=>'🦊','cat'=>'Navigateurs','msi'=>false,'args'=>'-ms','url'=>'https://download.mozilla.org/?product=firefox-esr-latest-ssl&os=win64&lang=fr','desc'=>'Firefox à support étendu (ESR), recommandé sur un parc géré.'],
    'brave'     => ['name'=>'Brave','icon'=>'🦁','cat'=>'Navigateurs','msi'=>false,'args'=>'/silent /install','url'=>'https://laptop-updates.brave.com/latest/winx64/BraveBrowserStandaloneSilentSetup.exe','desc'=>'Navigateur web axé sur la confidentialité, basé sur Chromium.'],
    'chromium'  => ['name'=>'Ungoogled Chromium','icon'=>'🧭','cat'=>'Navigateurs','msi'=>false,'args'=>'/S','url'=>'github:ungoogled-software/ungoogled-chromium-windows','desc'=>'Chromium sans services Google (à récupérer depuis GitHub Releases).'],
    'tor'       => ['manuel'=>'ne publie plus qu une version portable, sans installeur','name'=>'Tor Browser','icon'=>'🧅','cat'=>'Navigateurs','msi'=>false,'args'=>'/S','url'=>'index:https://www.torproject.org/dist/torbrowser/|{v}/torbrowser-install-win64-{v}_ALL.exe','desc'=>'Navigateur anonymisant (réseau Tor). Vérifier la politique d\'usage du service.'],

    // ── Bureautique & PDF ────────────────────────────────────────────────────
    'libre'     => ['name'=>'LibreOffice','icon'=>'📄','cat'=>'Bureautique & PDF','msi'=>true,'args'=>'/qn','url'=>'index:https://download.documentfoundation.org/libreoffice/stable/|{v}/win/x86_64/LibreOffice_{v}_Win_x86-64.msi','desc'=>'Suite bureautique complète (texte, tableur, présentation, dessin).'],
    'onlyoffice'=> ['manuel'=>'page de telechargement dynamique','name'=>'ONLYOFFICE Desktop','icon'=>'📝','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'/silent','url'=>'https://download.onlyoffice.com/install/desktop/editors/windows/onlyoffice-desktopeditors-x64.exe','desc'=>'Suite bureautique compatible avec les formats Microsoft Office.'],
    'sumatra'   => ['name'=>'SumatraPDF','icon'=>'📕','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'-s','url'=>'https://www.sumatrapdfreader.org/dl/rel/3.5.2/SumatraPDF-3.5.2-64-install.exe','desc'=>'Lecteur PDF, ePub, MOBI, CBZ… ultra-léger.'],
    'foxit'     => ['manuel'=>'aucun lien direct publie : le telechargement passe par du JavaScript','name'=>'Foxit PDF Reader','icon'=>'📗','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'/quiet','url'=>'https://cdn01.foxitsoftware.com/pub/foxit/reader/desktop/win/2024.1/FoxitPDFReader20241_enu_Setup.exe','desc'=>'Lecteur PDF complet (annotations, formulaires).'],
    'pdfsam'    => ['name'=>'PDFsam Basic','icon'=>'✂️','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'/S','url'=>'github:torakiki/pdfsam','desc'=>'Fusionner, découper, faire pivoter des PDF (GitHub Releases).'],
    'notepadpp' => ['name'=>'Notepad++','icon'=>'🗒️','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'/S','url'=>'https://github.com/notepad-plus-plus/notepad-plus-plus/releases/download/v8.6.9/npp.8.6.9.Installer.x64.exe','desc'=>'Éditeur de texte et de code avec coloration syntaxique.'],
    'thunderbird'=>['name'=>'Mozilla Thunderbird','icon'=>'🐦','cat'=>'Bureautique & PDF','msi'=>false,'args'=>'-ms','url'=>'https://download.mozilla.org/?product=thunderbird-latest-ssl&os=win64&lang=fr','desc'=>'Client de messagerie (courriel, agenda, contacts).'],

    // ── Multimédia ───────────────────────────────────────────────────────────
    'vlc'       => ['name'=>'VLC media player','icon'=>'🎬','cat'=>'Multimédia','msi'=>false,'args'=>'/S','url'=>'https://get.videolan.org/vlc/3.0.21/win64/vlc-3.0.21-win64.exe','desc'=>'Lecteur multimédia universel (tous formats audio/vidéo).'],
    'audacity'  => ['name'=>'Audacity','icon'=>'🎙️','cat'=>'Multimédia','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'github:audacity/audacity','desc'=>'Éditeur audio multipiste (GitHub Releases).'],
    'gimp'      => ['name'=>'GIMP','icon'=>'🖌️','cat'=>'Multimédia','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'https://download.gimp.org/gimp/v2.10/windows/gimp-2.10.38-setup.exe','desc'=>'Retouche et création d\'images (alternative libre à Photoshop).'],
    'irfanview' => ['manuel'=>'page de telechargement dynamique','name'=>'IrfanView','icon'=>'🖼️','cat'=>'Multimédia','msi'=>false,'args'=>'/silent /assoc=1','url'=>'https://www.irfanview.info/files/iview472_x64_setup.exe','desc'=>'Visionneuse d\'images rapide et légère.'],
    'obs'       => ['name'=>'OBS Studio','icon'=>'📹','cat'=>'Multimédia','msi'=>false,'args'=>'/S','url'=>'github:obsproject/obs-studio','desc'=>'Enregistrement et diffusion vidéo (GitHub Releases).'],
    'handbrake' => ['name'=>'HandBrake','icon'=>'🎞️','cat'=>'Multimédia','msi'=>false,'args'=>'/S','url'=>'github:HandBrake/HandBrake','desc'=>'Convertisseur vidéo multiformat (GitHub Releases).'],
    'klite'     => ['name'=>'K-Lite Codec Pack','icon'=>'🔊','cat'=>'Multimédia','msi'=>false,'args'=>'/verysilent','url'=>'https://files3.codecguide.com/K-Lite_Codec_Pack_1815_Standard.exe','desc'=>'Pack de codecs audio/vidéo pour Windows.'],

    // ── Communication ────────────────────────────────────────────────────────
    'zoom'      => ['name'=>'Zoom Workplace','icon'=>'🎥','cat'=>'Communication','msi'=>true,'args'=>'/quiet /norestart','url'=>'https://zoom.us/client/latest/ZoomInstallerFull.msi','desc'=>'Visioconférence et réunions en ligne.'],
    'teams'     => ['manuel'=>'Microsoft ne publie qu un paquet MSIX, non installable par cette GPO','name'=>'Microsoft Teams','icon'=>'👥','cat'=>'Communication','msi'=>true,'args'=>'/quiet','url'=>'https://statics.teams.cdn.office.net/production-windows-x64/enterprise/teamsbootstrapper.exe','desc'=>'Messagerie d\'équipe et visioconférence Microsoft.'],
    'slack'     => ['manuel'=>'page de telechargement dynamique','name'=>'Slack','icon'=>'💬','cat'=>'Communication','msi'=>true,'args'=>'/quiet','url'=>'https://slack.com/ssb/download-win64-msi','desc'=>'Messagerie d\'équipe et collaboration.'],
    'discord'   => ['name'=>'Discord','icon'=>'🎮','cat'=>'Communication','msi'=>false,'args'=>'/S','url'=>'https://discord.com/api/download?platform=win','desc'=>'Messagerie vocale et textuelle (communautés).'],
    'signal'    => ['name'=>'Signal Desktop','icon'=>'🔐','cat'=>'Communication','msi'=>false,'args'=>'/S','url'=>'electronyml:https://updates.signal.org/desktop/|signal-desktop-win-x64-{v}.exe','desc'=>'Messagerie chiffrée de bout en bout.'],

    // ── Accès distant & réseau ───────────────────────────────────────────────
    'anydesk'   => ['name'=>'AnyDesk','icon'=>'🖧','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'--install "C:\\Program Files (x86)\\AnyDesk" --start-with-win --silent','url'=>'https://download.anydesk.com/AnyDesk.exe','desc'=>'Prise de contrôle à distance légère.'],
    'teamviewer'=> ['name'=>'TeamViewer','icon'=>'🖥️','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/S','url'=>'https://download.teamviewer.com/download/TeamViewer_Setup_x64.exe','desc'=>'Assistance et prise de contrôle à distance.'],
    'rustdesk'  => ['name'=>'RustDesk','icon'=>'🦀','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'--silent-install','url'=>'github:rustdesk/rustdesk','desc'=>'Bureau à distance open source, auto-hébergeable (GitHub).'],
    'putty'     => ['name'=>'PuTTY','icon'=>'🔌','cat'=>'Accès distant & réseau','msi'=>true,'args'=>'/qn','url'=>'https://the.earth.li/~sgtatham/putty/latest/w64/putty-64bit-installer.msi','desc'=>'Client SSH / Telnet / série.'],
    'winscp'    => ['manuel'=>'page de telechargement dynamique','name'=>'WinSCP','icon'=>'📁','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'https://winscp.net/download/WinSCP-6.3.4-Setup.exe','desc'=>'Client de transfert de fichiers SFTP / SCP / FTP.'],
    'filezilla' => ['manuel'=>'URL versionnee sans lien stable','name'=>'FileZilla','icon'=>'🦎','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/S','url'=>'https://dl2.cdn.filezilla-project.org/client/FileZilla_3.67.1_win64-setup.exe','desc'=>'Client FTP / FTPS / SFTP.'],
    'wireshark' => ['name'=>'Wireshark','icon'=>'🦈','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/S','url'=>'https://www.wireshark.org/download/win64/Wireshark-latest-x64.exe','desc'=>'Analyseur de trafic réseau (capture de paquets).'],
    'openvpn'   => ['name'=>'OpenVPN Connect','icon'=>'🛡️','cat'=>'Accès distant & réseau','msi'=>true,'args'=>'/qn','url'=>'https://openvpn.net/downloads/openvpn-connect-v3-windows.msi','desc'=>'Client VPN OpenVPN.'],
    'wireguard' => ['name'=>'WireGuard','icon'=>'🔒','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/quiet','url'=>'https://download.wireguard.com/windows-client/wireguard-installer.exe','desc'=>'Client VPN WireGuard (moderne, rapide).'],
    'advancedip'=> ['name'=>'Advanced IP Scanner','icon'=>'📡','cat'=>'Accès distant & réseau','msi'=>false,'args'=>'/VERYSILENT','url'=>'https://download.advanced-ip-scanner.com/download/files/Advanced_IP_Scanner_2.5.4594.1.exe','desc'=>'Scanner réseau (postes, ports, partages).'],

    // ── Archivage & utilitaires ──────────────────────────────────────────────
    '7zip'      => ['name'=>'7-Zip','icon'=>'🗜️','cat'=>'Archivage & utilitaires','msi'=>true,'args'=>'/qn /norestart','url'=>'https://www.7-zip.org/a/7z2409-x64.msi','desc'=>'Archiveur de fichiers (zip, 7z, rar, tar…).'],
    'winrar'    => ['name'=>'WinRAR','icon'=>'📦','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/S','url'=>'https://www.rarlab.com/rar/winrar-x64-701fr.exe','desc'=>'Archiveur RAR / ZIP (français).'],
    'peazip'    => ['name'=>'PeaZip','icon'=>'🫛','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/S','url'=>'github:peazip/PeaZip','desc'=>'Archiveur libre multiformat (GitHub Releases).'],
    'everything'=> ['name'=>'Everything','icon'=>'🔎','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/S','url'=>'https://www.voidtools.com/Everything-1.4.1.1024.x64-Setup.exe','desc'=>'Recherche instantanée de fichiers par nom.'],
    'ccleaner'  => ['name'=>'CCleaner','icon'=>'🧹','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/S','url'=>'https://download.ccleaner.com/ccsetup.exe','desc'=>'Nettoyage des fichiers temporaires et du registre.'],
    'powertoys' => ['name'=>'Microsoft PowerToys','icon'=>'🧰','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/quiet /norestart','url'=>'github:microsoft/PowerToys','desc'=>'Utilitaires avancés pour Windows (GitHub Releases).'],
    'treesize'  => ['name'=>'TreeSize Free','icon'=>'🌳','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT','url'=>'https://downloads.jam-software.de/treesize_free/TreeSizeFreeSetup.exe','desc'=>'Analyse de l\'espace disque par dossier.'],
    'sharex'    => ['name'=>'ShareX','icon'=>'📸','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'github:ShareX/ShareX','desc'=>'Capture d\'écran et partage avancés (GitHub Releases).'],
    'greenshot' => ['name'=>'Greenshot','icon'=>'🟢','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'github:greenshot/greenshot','desc'=>'Capture d\'écran légère avec éditeur (GitHub Releases).'],
    'rufus'     => ['name'=>'Rufus','icon'=>'💽','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/S','url'=>'github:pbatard/rufus','desc'=>'Création de clés USB bootables (GitHub Releases).'],
    'keepass'   => ['name'=>'KeePass','icon'=>'🔑','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'sourceforge:keepass|/KeePass 2.x','desc'=>'Gestionnaire de mots de passe hors ligne.'],
    'bitwarden' => ['name'=>'Bitwarden','icon'=>'🛡️','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/allusers /S','url'=>'https://vault.bitwarden.com/download/?app=desktop&platform=windows','desc'=>'Gestionnaire de mots de passe (coffre chiffré).'],
    'cpuz'      => ['name'=>'CPU-Z','icon'=>'🧠','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT','url'=>'https://download.cpuid.com/cpu-z/cpu-z_2.10-en.exe','desc'=>'Informations détaillées sur le matériel (CPU, RAM, carte mère).'],
    'crystaldisk'=>['name'=>'CrystalDiskInfo','icon'=>'💾','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT','url'=>'sourceforge:crystaldiskinfo','desc'=>'État de santé des disques (S.M.A.R.T.).'],
    'hwmonitor' => ['name'=>'HWMonitor','icon'=>'🌡️','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT','url'=>'https://download.cpuid.com/hwmonitor/hwmonitor_1.53.exe','desc'=>'Surveillance des températures, tensions et ventilateurs.'],
    'notepad3'  => ['name'=>'Notepad3','icon'=>'📃','cat'=>'Archivage & utilitaires','msi'=>false,'args'=>'/VERYSILENT','url'=>'github:rizonesoft/Notepad3','desc'=>'Éditeur de texte léger (GitHub Releases).'],

    // ── Sécurité ─────────────────────────────────────────────────────────────
    'malwarebytes'=>['name'=>'Malwarebytes','icon'=>'🐞','cat'=>'Sécurité','msi'=>false,'args'=>'/VERYSILENT','url'=>'https://downloads.malwarebytes.com/file/mb-windows','desc'=>'Anti-malware complémentaire à l\'antivirus.'],
    'gpg4win'   => ['name'=>'Gpg4win','icon'=>'✉️','cat'=>'Sécurité','msi'=>false,'args'=>'/S','url'=>'https://files.gpg4win.org/gpg4win-4.3.1.exe','desc'=>'Chiffrement et signature de fichiers/e-mails (GnuPG + Kleopatra).'],
    'veracrypt' => ['name'=>'VeraCrypt','icon'=>'🔏','cat'=>'Sécurité','msi'=>false,'args'=>'/S','url'=>'https://launchpad.net/veracrypt/trunk/1.26.14/+download/VeraCrypt_Setup_x64_1.26.14.msi','desc'=>'Chiffrement de disques et de conteneurs.'],
    'keepassxc' => ['name'=>'KeePassXC','icon'=>'🗝️','cat'=>'Sécurité','msi'=>true,'args'=>'/quiet','url'=>'github:keepassxreboot/keepassxc','desc'=>'Gestionnaire de mots de passe multiplateforme (GitHub Releases).'],
    'clamwin'   => ['name'=>'ClamWin','icon'=>'🦪','cat'=>'Sécurité','msi'=>false,'args'=>'/VERYSILENT','url'=>'sourceforge:clamwin','desc'=>'Antivirus libre basé sur ClamAV.'],

    // ── Développement ────────────────────────────────────────────────────────
    'vscode'    => ['name'=>'Visual Studio Code','icon'=>'💠','cat'=>'Développement','msi'=>false,'args'=>'/VERYSILENT /MERGETASKS=!runcode','url'=>'https://update.code.visualstudio.com/latest/win32-x64/stable','desc'=>'Éditeur de code extensible (dernière version stable).'],
    'git'       => ['name'=>'Git pour Windows','icon'=>'🔧','cat'=>'Développement','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'github:git-for-windows/git','desc'=>'Système de gestion de versions (GitHub Releases).'],
    'python'    => ['name'=>'Python 3','icon'=>'🐍','cat'=>'Développement','msi'=>false,'args'=>'/quiet InstallAllUsers=1 PrependPath=1','url'=>'https://www.python.org/ftp/python/3.12.4/python-3.12.4-amd64.exe','desc'=>'Interpréteur Python 3 (installation pour tous les utilisateurs).'],
    'nodejs'    => ['name'=>'Node.js LTS','icon'=>'🟩','cat'=>'Développement','msi'=>true,'args'=>'/qn','url'=>'https://nodejs.org/dist/v20.15.0/node-v20.15.0-x64.msi','desc'=>'Environnement d\'exécution JavaScript (LTS).'],
    'temurin'   => ['name'=>'Eclipse Temurin JDK 17','icon'=>'☕','cat'=>'Développement','msi'=>true,'args'=>'/quiet','url'=>'https://api.adoptium.net/v3/installer/latest/17/ga/windows/x64/jdk/hotspot/normal/eclipse?project=jdk','desc'=>'Kit de développement Java (OpenJDK, dernière version 17).'],
    'winmerge'  => ['name'=>'WinMerge','icon'=>'🔀','cat'=>'Développement','msi'=>false,'args'=>'/VERYSILENT /NORESTART','url'=>'github:WinMerge/winmerge','desc'=>'Comparaison et fusion de fichiers/dossiers (GitHub Releases).'],
    'dbeaver'   => ['name'=>'DBeaver Community','icon'=>'🦫','cat'=>'Développement','msi'=>false,'args'=>'/S','url'=>'https://dbeaver.io/files/dbeaver-ce-latest-x86_64-setup.exe','desc'=>'Client universel de bases de données.'],
    'postman'   => ['name'=>'Postman','icon'=>'📮','cat'=>'Développement','msi'=>false,'args'=>'/S','url'=>'https://dl.pstmn.io/download/latest/win64','desc'=>'Client de test d\'API REST/HTTP.'],
    'winget'    => ['manuel'=>'paquet MSIX, non installable par cette GPO','name'=>'App Installer (winget)','icon'=>'📥','cat'=>'Développement','msi'=>false,'args'=>'','url'=>'https://aka.ms/getwinget','desc'=>'Gestionnaire de paquets en ligne de commande de Microsoft.'],

    // ── Graphisme & PAO ──────────────────────────────────────────────────────
    'inkscape'  => ['manuel'=>'galerie web sans lien stable','name'=>'Inkscape','icon'=>'🖊️','cat'=>'Graphisme & PAO','msi'=>true,'args'=>'/qn','url'=>'https://inkscape.org/gallery/item/44617/inkscape-1.3.2_2023-11-25_091e20e-x64.msi','desc'=>'Éditeur d\'images vectorielles (SVG).'],
    'blender'   => ['name'=>'Blender','icon'=>'🧊','cat'=>'Graphisme & PAO','msi'=>true,'args'=>'/qn','url'=>'https://download.blender.org/release/Blender4.2/blender-4.2.1-windows-x64.msi','desc'=>'Création 3D (modélisation, animation, rendu).'],
    'krita'     => ['name'=>'Krita','icon'=>'🎨','cat'=>'Graphisme & PAO','msi'=>false,'args'=>'/S','url'=>'https://download.kde.org/stable/krita/5.2.6/krita-x64-5.2.6-setup.exe','desc'=>'Peinture numérique et illustration.'],
    'paintnet'  => ['manuel'=>'publie une archive ZIP, pas un installeur','name'=>'Paint.NET','icon'=>'🖍️','cat'=>'Graphisme & PAO','msi'=>false,'args'=>'/auto','url'=>'github:paintdotnet/release','desc'=>'Retouche d\'images simple et efficace (GitHub Releases).'],
    'darktable' => ['name'=>'darktable','icon'=>'📷','cat'=>'Graphisme & PAO','msi'=>false,'args'=>'/S','url'=>'github:darktable-org/darktable','desc'=>'Développement de photos RAW (GitHub Releases).'],

    // ── Cloud & stockage ─────────────────────────────────────────────────────
    'nextcloud' => ['manuel'=>'publication GitHub sans binaire Windows','name'=>'Nextcloud Desktop','icon'=>'☁️','cat'=>'Cloud & stockage','msi'=>false,'args'=>'/S','url'=>'github:nextcloud/desktop','desc'=>'Client de synchronisation Nextcloud (GitHub Releases).'],
    'owncloud'  => ['manuel'=>'page de telechargement dynamique','name'=>'ownCloud Desktop','icon'=>'🌥️','cat'=>'Cloud & stockage','msi'=>false,'args'=>'/S','url'=>'https://download.owncloud.com/desktop/ownCloud/stable/latest/win/ownCloud-x64.exe','desc'=>'Client de synchronisation ownCloud.'],
    'syncthing' => ['name'=>'Syncthing (SyncTrayzor)','icon'=>'🔄','cat'=>'Cloud & stockage','msi'=>false,'args'=>'/VERYSILENT','url'=>'github:canton7/SyncTrayzor','desc'=>'Synchronisation de fichiers pair-à-pair (GitHub Releases).'],
    'winscp2'   => ['manuel'=>'URL versionnee sans lien stable','name'=>'FreeFileSync','icon'=>'🗂️','cat'=>'Cloud & stockage','msi'=>false,'args'=>'/SILENT','url'=>'https://freefilesync.org/download/FreeFileSync_Windows_Setup.exe','desc'=>'Synchronisation et sauvegarde de dossiers.'],

    // ── Runtimes & compléments ───────────────────────────────────────────────
    'vcredist'  => ['name'=>'Visual C++ Redistributable 2015-2022','icon'=>'🧩','cat'=>'Runtimes & compléments','msi'=>false,'args'=>'/install /quiet /norestart','url'=>'https://aka.ms/vs/17/release/vc_redist.x64.exe','desc'=>'Bibliothèques d\'exécution requises par de nombreux logiciels.'],
    'dotnet'    => ['name'=>'.NET Desktop Runtime 8','icon'=>'🟪','cat'=>'Runtimes & compléments','msi'=>false,'args'=>'/install /quiet /norestart','url'=>'https://aka.ms/dotnet/8.0/windowsdesktop-runtime-win-x64.exe','desc'=>'Environnement d\'exécution .NET 8 (applications de bureau).'],
    'webview2'  => ['name'=>'Microsoft Edge WebView2','icon'=>'🪟','cat'=>'Runtimes & compléments','msi'=>false,'args'=>'/silent /install','url'=>'https://go.microsoft.com/fwlink/p/?LinkId=2124703','desc'=>'Composant Web utilisé par de nombreuses applications modernes.'],
    'powershell7'=>['name'=>'PowerShell 7','icon'=>'⌨️','cat'=>'Runtimes & compléments','msi'=>true,'args'=>'/qn','url'=>'github:PowerShell/PowerShell','desc'=>'Shell et langage de script multiplateforme (GitHub Releases).'],

    // ── Métier & divers ──────────────────────────────────────────────────────
    'qgis'      => ['name'=>'QGIS','icon'=>'🗺️','cat'=>'Métier & divers','msi'=>true,'args'=>'/qn','url'=>'https://download.qgis.org/downloads/QGIS-OSGeo4W-3.44.3-1.msi','desc'=>'Système d\'information géographique (cartographie).'],
    'zotero'    => ['name'=>'Zotero','icon'=>'📚','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'https://www.zotero.org/download/client/dl?channel=release&platform=win-x64','desc'=>'Gestion de références bibliographiques.'],
    'calibre'   => ['name'=>'Calibre','icon'=>'📖','cat'=>'Métier & divers','msi'=>true,'args'=>'/qn','url'=>'https://download.calibre-ebook.com/7.14.0/calibre-64bit-7.14.0.msi','desc'=>'Gestion et conversion de livres numériques.'],
    'anki'      => ['name'=>'Anki','icon'=>'🃏','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:ankitects/anki','desc'=>'Cartes mémoire et révision espacée.'],
    'obsidian'  => ['name'=>'Obsidian','icon'=>'🪨','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:obsidianmd/obsidian-releases','desc'=>'Prise de notes en Markdown, base de connaissances (GitHub).'],
    'joplin'    => ['name'=>'Joplin','icon'=>'📓','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:laurent22/joplin','desc'=>'Prise de notes et tâches, chiffrable (GitHub Releases).'],
    'freecad'   => ['name'=>'FreeCAD','icon'=>'📐','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:FreeCAD/FreeCAD','desc'=>'Modélisation paramétrique 3D / CAO (GitHub Releases).'],
    'scribus'   => ['name'=>'Scribus','icon'=>'📰','cat'=>'Métier & divers','msi'=>false,'args'=>'/VERYSILENT','url'=>'sourceforge:scribus','desc'=>'Publication assistée par ordinateur (PAO).'],
    'stellarium'=> ['name'=>'Stellarium','icon'=>'🔭','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:Stellarium/stellarium','desc'=>'Planétarium virtuel (GitHub Releases).'],
    'kdenlive'  => ['name'=>'Kdenlive','icon'=>'✂️','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'dirscan:https://download.kde.org/stable/kdenlive/|windows','desc'=>'Montage vidéo non linéaire.'],
    'etcher'    => ['name'=>'balenaEtcher','icon'=>'🔥','cat'=>'Métier & divers','msi'=>false,'args'=>'/S','url'=>'github:balena-io/etcher','desc'=>'Écriture d\'images disque sur clés USB/SD (GitHub Releases).'],
    'virtualbox'=> ['name'=>'Oracle VirtualBox','icon'=>'📦','cat'=>'Métier & divers','msi'=>false,'args'=>'--silent','url'=>'https://download.virtualbox.org/virtualbox/7.0.20/VirtualBox-7.0.20-163906-Win.exe','desc'=>'Virtualisation de systèmes d\'exploitation.'],
];
