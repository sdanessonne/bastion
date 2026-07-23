<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Catalogue de stratégies de groupe (GPO) prêtes à déployer — « store » des GPO.
 * Chaque entrée crée/configure une GPO de registre (Registry.pol) et la lie au domaine.
 * L'administrateur déploie en un clic depuis admin/ad.php (onglet Active Directory).
 *
 * Champs : cat (catégorie), title, icon, scope ('Ordinateur'|'Utilisateur'), desc,
 *          policies => [ {keyname, valuename, class:'MACHINE'|'USER', type, data} ].
 * Types : REG_SZ (chaîne), REG_DWORD (entier), REG_BINARY (tableau d'octets).
 *
 * Les clés sous Software\Policies\... sont de « vraies » stratégies (retirées proprement
 * quand la GPO est délièe). Les 8 premières clés reprennent le catalogue historique
 * (verrou/usb/banniere/panneau/cmd/taskmgr/msi/fond) pour préserver la détection « déployée ».
 *
 * @return array<string,array{cat:string,title:string,icon:string,scope:string,desc:string,policies:array}>
 */
$K_EXPL_U = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer';
$K_SYS_U  = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\System';
$K_SYSPOL = 'Software\\Policies\\Microsoft\\Windows\\System';
$K_DEF    = 'Software\\Policies\\Microsoft\\Windows Defender';
$K_EDGE   = 'Software\\Policies\\Microsoft\\Edge';
$K_CHROME = 'Software\\Policies\\Google\\Chrome';
$K_FF     = 'Software\\Policies\\Mozilla\\Firefox';
$K_WU     = 'Software\\Policies\\Microsoft\\Windows\\WindowsUpdate';
$K_WUAU   = 'Software\\Policies\\Microsoft\\Windows\\WindowsUpdate\\AU';
$K_FW_DOM = 'Software\\Policies\\Microsoft\\WindowsFirewall\\DomainProfile';
$K_FW_STD = 'Software\\Policies\\Microsoft\\WindowsFirewall\\StandardProfile';
$K_FW_PUB = 'Software\\Policies\\Microsoft\\WindowsFirewall\\PublicProfile';
$K_CLOUD  = 'Software\\Policies\\Microsoft\\Windows\\CloudContent';
$K_DATA   = 'Software\\Policies\\Microsoft\\Windows\\DataCollection';

return [
    // ═══ Sécurité & durcissement (Ordinateur) ═══════════════════════════════
    'usb' => ['cat'=>'Sécurité & durcissement','title'=>'Blocage des clés USB de stockage','icon'=>'🚫','scope'=>'Ordinateur',
        'desc'=>"Empêche l'utilisation des périphériques de stockage USB (clés, disques) — protection des données sensibles.",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Services\\USBSTOR','valuename'=>'Start','class'=>'MACHINE','type'=>'REG_DWORD','data'=>4]]],
    'usb_ro' => ['cat'=>'Sécurité & durcissement','title'=>'Clés USB en lecture seule','icon'=>'📛','scope'=>'Ordinateur',
        'desc'=>"Autorise la lecture des supports USB mais interdit toute écriture (exfiltration bloquée).",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\StorageDevicePolicies','valuename'=>'WriteProtect','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'denyremovable' => ['cat'=>'Sécurité & durcissement','title'=>"Interdire l'installation de périphériques amovibles",'icon'=>'⛔','scope'=>'Ordinateur',
        'desc'=>"Bloque l'installation de tout nouveau périphérique amovible au niveau du pilote.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\DeviceInstall\\Restrictions','valuename'=>'DenyRemovableDevices','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'noautorun' => ['cat'=>'Sécurité & durcissement','title'=>"Désactiver l'exécution automatique (AutoRun)",'icon'=>'🎯','scope'=>'Ordinateur',
        'desc'=>"Désactive AutoRun/AutoPlay sur tous les lecteurs (vecteur classique de logiciels malveillants).",
        'policies'=>[
            ['keyname'=>$K_EXPL_U,'valuename'=>'NoDriveTypeAutoRun','class'=>'MACHINE','type'=>'REG_DWORD','data'=>255],
            ['keyname'=>$K_EXPL_U,'valuename'=>'NoAutorun','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'uac' => ['cat'=>'Sécurité & durcissement','title'=>"Renforcer le contrôle de compte (UAC)",'icon'=>'🛡️','scope'=>'Ordinateur',
        'desc'=>"Active l'UAC, demande le consentement sur le Bureau sécurisé pour toute élévation.",
        'policies'=>[
            ['keyname'=>$K_SYS_U,'valuename'=>'EnableLUA','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_SYS_U,'valuename'=>'ConsentPromptBehaviorAdmin','class'=>'MACHINE','type'=>'REG_DWORD','data'=>2],
            ['keyname'=>$K_SYS_U,'valuename'=>'PromptOnSecureDesktop','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'nolmhash' => ['cat'=>'Sécurité & durcissement','title'=>"Ne pas stocker les empreintes LM",'icon'=>'🔓','scope'=>'Ordinateur',
        'desc'=>"Interdit le stockage des empreintes de mot de passe LM (faibles) à la prochaine modification.",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Lsa','valuename'=>'NoLMHash','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'lsappl' => ['cat'=>'Sécurité & durcissement','title'=>"Protéger le processus LSA (RunAsPPL)",'icon'=>'🧱','scope'=>'Ordinateur',
        'desc'=>"Exécute LSASS en processus protégé (protège les identifiants contre le vol type Mimikatz).",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Lsa','valuename'=>'RunAsPPL','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'restrictanon' => ['cat'=>'Sécurité & durcissement','title'=>"Restreindre l'accès anonyme",'icon'=>'🕵️','scope'=>'Ordinateur',
        'desc'=>"Bloque l'énumération anonyme des comptes et partages (durcissement SMB/NetBIOS).",
        'policies'=>[
            ['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Lsa','valuename'=>'RestrictAnonymous','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Lsa','valuename'=>'RestrictAnonymousSAM','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'nosmb1' => ['cat'=>'Sécurité & durcissement','title'=>"Désactiver SMBv1 (serveur)",'icon'=>'🧯','scope'=>'Ordinateur',
        'desc'=>"Désactive le protocole SMBv1 obsolète et vulnérable (WannaCry/EternalBlue).",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Services\\LanmanServer\\Parameters','valuename'=>'SMB1','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'psscripts' => ['cat'=>'Sécurité & durcissement','title'=>"Scripts PowerShell signés uniquement",'icon'=>'📜','scope'=>'Ordinateur',
        'desc'=>"Impose la politique d'exécution PowerShell « AllSigned » (scripts signés seulement).",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\PowerShell','valuename'=>'EnableScripts','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\PowerShell','valuename'=>'ExecutionPolicy','class'=>'MACHINE','type'=>'REG_SZ','data'=>'AllSigned'],
        ]],
    'pslog' => ['cat'=>'Sécurité & durcissement','title'=>"Journaliser les blocs PowerShell",'icon'=>'🧾','scope'=>'Ordinateur',
        'desc'=>"Active la journalisation des blocs de script PowerShell (traçabilité, détection).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\PowerShell\\ScriptBlockLogging','valuename'=>'EnableScriptBlockLogging','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nowsh' => ['cat'=>'Sécurité & durcissement','title'=>"Désactiver Windows Script Host",'icon'=>'🚧','scope'=>'Ordinateur',
        'desc'=>"Bloque l'exécution des scripts .vbs/.js par WScript/CScript (vecteur d'infection courant).",
        'policies'=>[['keyname'=>'Software\\Microsoft\\Windows Script Host\\Settings','valuename'=>'Enabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'msi' => ['cat'=>'Sécurité & durcissement','title'=>"Interdire l'installation de logiciels (MSI)",'icon'=>'📥','scope'=>'Ordinateur',
        'desc'=>"Bloque l'installation de nouveaux logiciels via le programme d'installation Windows.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Installer','valuename'=>'DisableMSI','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nostore' => ['cat'=>'Sécurité & durcissement','title'=>"Désactiver le Microsoft Store",'icon'=>'🏬','scope'=>'Ordinateur',
        'desc'=>"Retire l'accès au Microsoft Store (empêche l'installation d'applications non validées).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\WindowsStore','valuename'=>'RemoveWindowsStore','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nocamera' => ['cat'=>'Sécurité & durcissement','title'=>"Désactiver la caméra",'icon'=>'📷','scope'=>'Ordinateur',
        'desc'=>"Interdit l'usage de la caméra intégrée sur les postes (confidentialité).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Camera','valuename'=>'AllowCamera','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'nowpbt' => ['cat'=>'Sécurité & durcissement','title'=>"Bloquer l'invite de commandes (poste)",'icon'=>'🖥️','scope'=>'Ordinateur',
        'desc'=>"Interdit l'exécution de scripts batch et de l'invite de commandes au niveau machine.",
        'policies'=>[['keyname'=>$K_SYSPOL,'valuename'=>'DisableCMD','class'=>'MACHINE','type'=>'REG_DWORD','data'=>2]]],

    // ═══ Defender & SmartScreen (Ordinateur) ════════════════════════════════
    'smartscreen' => ['cat'=>'Defender & SmartScreen','title'=>"Activer SmartScreen (Windows)",'icon'=>'🛡️','scope'=>'Ordinateur',
        'desc'=>"Active le filtre SmartScreen et bloque l'exécution des fichiers non reconnus.",
        'policies'=>[
            ['keyname'=>$K_SYSPOL,'valuename'=>'EnableSmartScreen','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_SYSPOL,'valuename'=>'ShellSmartScreenLevel','class'=>'MACHINE','type'=>'REG_SZ','data'=>'Block'],
        ]],
    'defrt' => ['cat'=>'Defender & SmartScreen','title'=>"Forcer la protection en temps réel",'icon'=>'🧬','scope'=>'Ordinateur',
        'desc'=>"Empêche la désactivation de la protection en temps réel de Microsoft Defender.",
        'policies'=>[['keyname'=>$K_DEF.'\\Real-Time Protection','valuename'=>'DisableRealtimeMonitoring','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'defcloud' => ['cat'=>'Defender & SmartScreen','title'=>"Protection cloud Defender (élevée)",'icon'=>'☁️','scope'=>'Ordinateur',
        'desc'=>"Active la protection fournie par le cloud Microsoft à un niveau élevé.",
        'policies'=>[
            ['keyname'=>$K_DEF.'\\Spynet','valuename'=>'SpynetReporting','class'=>'MACHINE','type'=>'REG_DWORD','data'=>2],
            ['keyname'=>$K_DEF.'\\Spynet','valuename'=>'SubmitSamplesConsent','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'defpua' => ['cat'=>'Defender & SmartScreen','title'=>"Bloquer les applications indésirables (PUA)",'icon'=>'🗑️','scope'=>'Ordinateur',
        'desc'=>"Bloque les programmes potentiellement indésirables (adwares, barres d'outils).",
        'policies'=>[['keyname'=>$K_DEF,'valuename'=>'PUAProtection','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'edgescreen' => ['cat'=>'Defender & SmartScreen','title'=>"SmartScreen dans Edge",'icon'=>'🌐','scope'=>'Ordinateur',
        'desc'=>"Active SmartScreen dans Microsoft Edge (protection contre sites et téléchargements dangereux).",
        'policies'=>[
            ['keyname'=>$K_EDGE,'valuename'=>'SmartScreenEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_EDGE,'valuename'=>'SmartScreenPuaEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],

    // ═══ Confidentialité & télémétrie (Ordinateur) ══════════════════════════
    'telemetry' => ['cat'=>'Confidentialité & télémétrie','title'=>"Télémétrie au minimum",'icon'=>'📉','scope'=>'Ordinateur',
        'desc'=>"Réduit la collecte de données de diagnostic Windows au niveau le plus bas.",
        'policies'=>[
            ['keyname'=>$K_DATA,'valuename'=>'AllowTelemetry','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_DATA,'valuename'=>'DoNotShowFeedbackNotifications','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'nocortana' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver Cortana",'icon'=>'🎙️','scope'=>'Ordinateur',
        'desc'=>"Désactive l'assistant Cortana et la recherche web associée.",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Windows Search','valuename'=>'AllowCortana','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Windows Search','valuename'=>'DisableWebSearch','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Windows Search','valuename'=>'ConnectedSearchUseWeb','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'noconsumer' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver les fonctionnalités grand public",'icon'=>'🛍️','scope'=>'Ordinateur',
        'desc'=>"Supprime les suggestions d'applications, publicités et installations automatiques (candy crush, etc.).",
        'policies'=>[
            ['keyname'=>$K_CLOUD,'valuename'=>'DisableWindowsConsumerFeatures','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_CLOUD,'valuename'=>'DisableSoftLanding','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'noadvid' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver l'identifiant de publicité",'icon'=>'🎫','scope'=>'Ordinateur',
        'desc'=>"Désactive l'identifiant de publicité utilisé pour le suivi entre applications.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\AdvertisingInfo','valuename'=>'DisabledByGroupPolicy','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nolocation' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver la localisation",'icon'=>'📍','scope'=>'Ordinateur',
        'desc'=>"Coupe les services de localisation sur les postes.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\LocationAndSensors','valuename'=>'DisableLocation','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'noonedrive' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver OneDrive",'icon'=>'☁️','scope'=>'Ordinateur',
        'desc'=>"Empêche l'usage de OneDrive pour le stockage de fichiers (données hors du domaine).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\OneDrive','valuename'=>'DisableFileSyncNGSC','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nosync' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver la synchronisation des paramètres",'icon'=>'🔁','scope'=>'Ordinateur',
        'desc'=>"Empêche la synchronisation des paramètres Windows via le compte Microsoft.",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\SettingSync','valuename'=>'DisableSettingSync','class'=>'MACHINE','type'=>'REG_DWORD','data'=>2],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\SettingSync','valuename'=>'DisableSettingSyncUserOverride','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'nowidgets' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver les widgets / Actualités",'icon'=>'📰','scope'=>'Ordinateur',
        'desc'=>"Retire le bouton Widgets et le flux Actualités et centres d'intérêt de la barre des tâches.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Dsh','valuename'=>'AllowNewsAndInterests','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'nocopilot' => ['cat'=>'Confidentialité & télémétrie','title'=>"Désactiver Windows Copilot",'icon'=>'🤖','scope'=>'Utilisateur',
        'desc'=>"Retire l'assistant Copilot de la barre des tâches et de Windows.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\WindowsCopilot','valuename'=>'TurnOffWindowsCopilot','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Interface & bureau (Utilisateur) ═══════════════════════════════════
    'verrou' => ['cat'=>'Interface & bureau','title'=>'Verrouillage automatique de session (10 min)','icon'=>'🔒','scope'=>'Utilisateur',
        'desc'=>"Verrouille l'écran après 10 minutes d'inactivité et exige le mot de passe pour reprendre.",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Control Panel\\Desktop','valuename'=>'ScreenSaveActive','class'=>'USER','type'=>'REG_SZ','data'=>'1'],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Control Panel\\Desktop','valuename'=>'ScreenSaverIsSecure','class'=>'USER','type'=>'REG_SZ','data'=>'1'],
            ['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Control Panel\\Desktop','valuename'=>'ScreenSaveTimeOut','class'=>'USER','type'=>'REG_SZ','data'=>'600'],
        ]],
    'panneau' => ['cat'=>'Interface & bureau','title'=>'Désactiver le Panneau de configuration','icon'=>'🖥️','scope'=>'Utilisateur',
        'desc'=>"Bloque l'accès au Panneau de configuration et à l'application Paramètres pour les agents.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoControlPanel','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'cmd' => ['cat'=>'Interface & bureau','title'=>"Désactiver l'invite de commandes (cmd)",'icon'=>'⌨️','scope'=>'Utilisateur',
        'desc'=>"Empêche l'ouverture de l'invite de commandes Windows par les agents.",
        'policies'=>[['keyname'=>$K_SYSPOL,'valuename'=>'DisableCMD','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'taskmgr' => ['cat'=>'Interface & bureau','title'=>'Désactiver le Gestionnaire des tâches','icon'=>'🛠️','scope'=>'Utilisateur',
        'desc'=>"Empêche l'ouverture du Gestionnaire des tâches (Ctrl+Maj+Échap).",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'DisableTaskMgr','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'fond' => ['cat'=>'Interface & bureau','title'=>"Empêcher la modification du fond d'écran",'icon'=>'🖼️','scope'=>'Utilisateur',
        'desc'=>"Verrouille le fond d'écran : l'agent ne peut plus le changer.",
        'policies'=>[['keyname'=>'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\ActiveDesktop','valuename'=>'NoChangingWallPaper','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'norun' => ['cat'=>'Interface & bureau','title'=>"Retirer la commande « Exécuter »",'icon'=>'🏃','scope'=>'Utilisateur',
        'desc'=>"Supprime « Exécuter » du menu Démarrer (limite le lancement de commandes arbitraires).",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoRun','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'noregedit' => ['cat'=>'Interface & bureau','title'=>"Interdire l'Éditeur du Registre",'icon'=>'🧰','scope'=>'Utilisateur',
        'desc'=>"Bloque l'accès à regedit et aux outils de modification du registre.",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'DisableRegistryTools','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nofolderopt' => ['cat'=>'Interface & bureau','title'=>"Interdire les Options des dossiers",'icon'=>'📂','scope'=>'Utilisateur',
        'desc'=>"Empêche l'accès aux Options des dossiers de l'Explorateur.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoFolderOptions','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'locktaskbar' => ['cat'=>'Interface & bureau','title'=>"Verrouiller la barre des tâches",'icon'=>'📌','scope'=>'Utilisateur',
        'desc'=>"Empêche le déplacement et le redimensionnement de la barre des tâches.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'LockTaskbar','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nocdrive' => ['cat'=>'Interface & bureau','title'=>"Masquer le lecteur C: dans l'Explorateur",'icon'=>'🅲','scope'=>'Utilisateur',
        'desc'=>"Masque le disque système C: dans l'Explorateur (n'empêche pas l'accès direct par chemin).",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoDrives','class'=>'USER','type'=>'REG_DWORD','data'=>4]]],
    'noaddprinter' => ['cat'=>'Interface & bureau','title'=>"Interdire l'ajout d'imprimantes",'icon'=>'🖨️','scope'=>'Utilisateur',
        'desc'=>"Empêche l'utilisateur d'ajouter de nouvelles imprimantes.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoAddPrinter','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nodelprinter' => ['cat'=>'Interface & bureau','title'=>"Interdire la suppression d'imprimantes",'icon'=>'🖨️','scope'=>'Utilisateur',
        'desc'=>"Empêche l'utilisateur de supprimer des imprimantes installées.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoDeletePrinter','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nosetting' => ['cat'=>'Interface & bureau','title'=>"Masquer les pages de Paramètres système",'icon'=>'⚙️','scope'=>'Utilisateur',
        'desc'=>"Interdit l'accès aux pages système de l'application Paramètres (personnalisation, comptes…).",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'SettingsPageVisibility','class'=>'USER','type'=>'REG_SZ','data'=>'hide:personalization;accounts;privacy']]],
    'nonotif' => ['cat'=>'Interface & bureau','title'=>"Masquer la zone de recherche de la barre des tâches",'icon'=>'🔍','scope'=>'Utilisateur',
        'desc'=>"Réduit la recherche à une simple icône (interface épurée sur les postes en libre-service).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Windows Search','valuename'=>'SearchboxTaskbarMode','class'=>'USER','type'=>'REG_DWORD','data'=>0]]],
    'nolockchange' => ['cat'=>'Interface & bureau','title'=>"Désactiver le changement de mot de passe (Ctrl+Alt+Suppr)",'icon'=>'🔑','scope'=>'Utilisateur',
        'desc'=>"Retire l'option « Modifier un mot de passe » de l'écran sécurisé.",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'DisableChangePassword','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nostartrec' => ['cat'=>'Interface & bureau','title'=>"Masquer les éléments récents",'icon'=>'🕘','scope'=>'Utilisateur',
        'desc'=>"N'affiche pas les documents/applications récemment ouverts (confidentialité sur poste partagé).",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoRecentDocsHistory','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Ouverture de session & écran (Ordinateur) ══════════════════════════
    'banniere' => ['cat'=>'Ouverture de session & écran','title'=>'Bannière juridique à la connexion','icon'=>'⚖️','scope'=>'Ordinateur',
        'desc'=>"Affiche un avertissement légal avant l'ouverture de session (accès réservé, activité journalisée).",
        'policies'=>[
            ['keyname'=>$K_SYS_U,'valuename'=>'legalnoticecaption','class'=>'MACHINE','type'=>'REG_SZ','data'=>'Accès réservé aux personnels habilités'],
            ['keyname'=>$K_SYS_U,'valuename'=>'legalnoticetext','class'=>'MACHINE','type'=>'REG_SZ','data'=>"Ce système est réservé aux agents habilités. Toute activité est enregistrée et contrôlée. Tout accès non autorisé est passible de poursuites."],
        ]],
    'nolastuser' => ['cat'=>'Ouverture de session & écran','title'=>"Ne pas afficher le dernier utilisateur",'icon'=>'👤','scope'=>'Ordinateur',
        'desc'=>"Masque le nom du dernier compte connecté sur l'écran d'ouverture de session.",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'DontDisplayLastUserName','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nofastswitch' => ['cat'=>'Ouverture de session & écran','title'=>"Désactiver le changement rapide d'utilisateur",'icon'=>'🔀','scope'=>'Ordinateur',
        'desc'=>"Interdit la bascule rapide entre sessions (une seule session interactive à la fois).",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'HideFastUserSwitching','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'cachedlogon' => ['cat'=>'Ouverture de session & écran','title'=>"Limiter les connexions en cache",'icon'=>'💽','scope'=>'Ordinateur',
        'desc'=>"Réduit le nombre d'identifiants mis en cache localement (limite le vol hors ligne).",
        'policies'=>[['keyname'=>'Software\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon','valuename'=>'CachedLogonsCount','class'=>'MACHINE','type'=>'REG_SZ','data'=>'2']]],
    'nolockscreen' => ['cat'=>'Ouverture de session & écran','title'=>"Désactiver l'écran de verrouillage",'icon'=>'🖼️','scope'=>'Ordinateur',
        'desc'=>"Passe directement à l'invite d'ouverture de session (pas d'écran de verrouillage décoratif).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Personalization','valuename'=>'NoLockScreen','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nolockcam' => ['cat'=>'Ouverture de session & écran','title'=>"Interdire la caméra sur l'écran de verrouillage",'icon'=>'📸','scope'=>'Ordinateur',
        'desc'=>"Empêche l'accès à la caméra depuis l'écran de verrouillage.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Personalization','valuename'=>'NoLockScreenCamera','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Réseau (Ordinateur) ════════════════════════════════════════════════
    'fw' => ['cat'=>'Réseau','title'=>"Activer le pare-feu Windows (tous profils)",'icon'=>'🧱','scope'=>'Ordinateur',
        'desc'=>"Active et verrouille le pare-feu Windows sur les profils Domaine, Privé et Public.",
        'policies'=>[
            ['keyname'=>$K_FW_DOM,'valuename'=>'EnableFirewall','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FW_STD,'valuename'=>'EnableFirewall','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FW_PUB,'valuename'=>'EnableFirewall','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'fwinbound' => ['cat'=>'Réseau','title'=>"Bloquer les connexions entrantes par défaut",'icon'=>'🚪','scope'=>'Ordinateur',
        'desc'=>"Bloque tout le trafic entrant non explicitement autorisé (profils Domaine et Public).",
        'policies'=>[
            ['keyname'=>$K_FW_DOM,'valuename'=>'DefaultInboundAction','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FW_PUB,'valuename'=>'DefaultInboundAction','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'nonetbridge' => ['cat'=>'Réseau','title'=>"Interdire le pont réseau",'icon'=>'🌉','scope'=>'Ordinateur',
        'desc'=>"Empêche la création d'un pont réseau entre interfaces (contournement du filtrage).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Network Connections','valuename'=>'NC_AllowNetBridge_NLA','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'noics' => ['cat'=>'Réseau','title'=>"Interdire le partage de connexion (ICS)",'icon'=>'📶','scope'=>'Ordinateur',
        'desc'=>"Empêche le partage de connexion Internet depuis le poste (point d'accès sauvage).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\Network Connections','valuename'=>'NC_ShowSharedAccessUI','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'syncfg' => ['cat'=>'Réseau','title'=>"Attendre le réseau à l'ouverture de session",'icon'=>'🔗','scope'=>'Ordinateur',
        'desc'=>"Force l'application SYNCHRONE des stratégies au démarrage/ouverture de session (désactive la « Fast Logon Optimization »). Indispensable pour que les LECTEURS RÉSEAU et le FOND D'ÉCRAN s'appliquent dès la 1ʳᵉ connexion (sinon ils n'apparaissent qu'au 2ᵉ logon).",
        'policies'=>[['keyname'=>$K_SYSPOL,'valuename'=>'SyncForegroundPolicy','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'nollmnr' => ['cat'=>'Réseau','title'=>"Désactiver LLMNR",'icon'=>'📡','scope'=>'Ordinateur',
        'desc'=>"Désactive la résolution de noms LLMNR (souvent exploitée pour le vol d'identifiants).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows NT\\DNSClient','valuename'=>'EnableMulticast','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'ntp' => ['cat'=>'Réseau','title'=>"Synchronisation de l'heure (NTP passerelle)",'icon'=>'🕰️','scope'=>'Ordinateur',
        'desc'=>"Configure ET ACTIVE le client de temps Windows sur le serveur NTP de la passerelle. Indispensable au domaine : Kerberos refuse toute authentification si l'horloge du poste s'écarte de plus de 5 minutes de celle du contrôleur — les lecteurs réseau et les GPO cessent alors de s'appliquer (erreur « Fonction incorrecte »).",
        'policies'=>[
            // « Configure Windows NTP Client » : serveur + mode.
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\Parameters','valuename'=>'NtpServer','class'=>'MACHINE','type'=>'REG_SZ','data'=>'192.168.182.1,0x9'],
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\Parameters','valuename'=>'Type','class'=>'MACHINE','type'=>'REG_SZ','data'=>'NTP'],
            // « Enable Windows NTP Client » : SANS cette clé, le serveur configuré ci-dessus
            // n'est pas interrogé — le client de temps reste inactif. C'était le manque.
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\TimeProviders\\NtpClient','valuename'=>'Enabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            // Intervalle référencé par le drapeau « 0x9 » du serveur : interrogation horaire.
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\TimeProviders\\NtpClient','valuename'=>'SpecialPollInterval','class'=>'MACHINE','type'=>'REG_DWORD','data'=>3600],
            // Corriger l'heure MÊME sur un grand écart : une VM figée puis relancée peut
            // avoir dérivé de plusieurs heures ; par défaut Windows refuse de corriger au-delà
            // d'un certain seuil, et l'horloge resterait fausse. 0xFFFFFFFF = corriger toujours.
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\Config','valuename'=>'MaxPosPhaseCorrection','class'=>'MACHINE','type'=>'REG_DWORD','data'=>4294967295],
            ['keyname'=>'Software\\Policies\\Microsoft\\W32Time\\Config','valuename'=>'MaxNegPhaseCorrection','class'=>'MACHINE','type'=>'REG_DWORD','data'=>4294967295],
        ]],

    // ═══ Windows Update (Ordinateur) ════════════════════════════════════════
    'wuauto' => ['cat'=>'Windows Update','title'=>"Mises à jour automatiques (télécharger et planifier)",'icon'=>'🔄','scope'=>'Ordinateur',
        'desc'=>"Active Windows Update et télécharge automatiquement les mises à jour.",
        'policies'=>[
            ['keyname'=>$K_WUAU,'valuename'=>'NoAutoUpdate','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_WUAU,'valuename'=>'AUOptions','class'=>'MACHINE','type'=>'REG_DWORD','data'=>4],
        ]],
    'wunoreboot' => ['cat'=>'Windows Update','title'=>"Pas de redémarrage auto si session ouverte",'icon'=>'⏸️','scope'=>'Ordinateur',
        'desc'=>"Empêche le redémarrage automatique après mise à jour tant qu'un utilisateur est connecté.",
        'policies'=>[['keyname'=>$K_WUAU,'valuename'=>'NoAutoRebootWithLoggedOnUsers','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'wuhours' => ['cat'=>'Windows Update','title'=>"Heures d'activité (8h–19h)",'icon'=>'🕗','scope'=>'Ordinateur',
        'desc'=>"Définit la plage d'heures d'activité pendant laquelle Windows ne redémarre pas.",
        'policies'=>[
            ['keyname'=>$K_WUAU,'valuename'=>'ActiveHoursStart','class'=>'MACHINE','type'=>'REG_DWORD','data'=>8],
            ['keyname'=>$K_WUAU,'valuename'=>'ActiveHoursEnd','class'=>'MACHINE','type'=>'REG_DWORD','data'=>19],
        ]],
    'wudeferfeat' => ['cat'=>'Windows Update','title'=>"Reporter les mises à jour de fonctionnalités (180 j)",'icon'=>'📅','scope'=>'Ordinateur',
        'desc'=>"Diffère les montées de version majeures de Windows de 180 jours (stabilité du parc).",
        'policies'=>[
            ['keyname'=>$K_WU,'valuename'=>'DeferFeatureUpdates','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_WU,'valuename'=>'DeferFeatureUpdatesPeriodInDays','class'=>'MACHINE','type'=>'REG_DWORD','data'=>180],
        ]],
    'wudeferqual' => ['cat'=>'Windows Update','title'=>"Reporter les mises à jour de qualité (7 j)",'icon'=>'🧪','scope'=>'Ordinateur',
        'desc'=>"Diffère les correctifs mensuels de 7 jours (validation avant déploiement).",
        'policies'=>[
            ['keyname'=>$K_WU,'valuename'=>'DeferQualityUpdates','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_WU,'valuename'=>'DeferQualityUpdatesPeriodInDays','class'=>'MACHINE','type'=>'REG_DWORD','data'=>7],
        ]],
    'wunoinsider' => ['cat'=>'Windows Update','title'=>"Bloquer le programme Windows Insider",'icon'=>'🚫','scope'=>'Ordinateur',
        'desc'=>"Empêche l'inscription aux builds préliminaires (Insider) sur les postes de production.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\PreviewBuilds','valuename'=>'AllowBuildPreview','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],

    // ═══ Navigateur Microsoft Edge (Ordinateur) ═════════════════════════════
    'edgehome' => ['cat'=>'Navigateur Edge','title'=>"Page d'accueil intranet imposée (Edge)",'icon'=>'🏠','scope'=>'Ordinateur',
        'desc'=>"Force la page d'accueil et la page de démarrage d'Edge vers l'intranet Bastion.",
        'policies'=>[
            ['keyname'=>$K_EDGE,'valuename'=>'HomepageLocation','class'=>'MACHINE','type'=>'REG_SZ','data'=>'http://192.168.182.1:2080/portal/intranet.php'],
            ['keyname'=>$K_EDGE,'valuename'=>'HomepageIsNewTabPage','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_EDGE,'valuename'=>'RestoreOnStartup','class'=>'MACHINE','type'=>'REG_DWORD','data'=>4],
        ]],
    'edgenopwd' => ['cat'=>'Navigateur Edge','title'=>"Désactiver le gestionnaire de mots de passe (Edge)",'icon'=>'🔑','scope'=>'Ordinateur',
        'desc'=>"Empêche Edge d'enregistrer les mots de passe (évite le stockage local d'identifiants).",
        'policies'=>[['keyname'=>$K_EDGE,'valuename'=>'PasswordManagerEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'edgenoinprivate' => ['cat'=>'Navigateur Edge','title'=>"Désactiver la navigation InPrivate (Edge)",'icon'=>'🕶️','scope'=>'Ordinateur',
        'desc'=>"Interdit le mode InPrivate (toute la navigation reste journalisée).",
        'policies'=>[['keyname'=>$K_EDGE,'valuename'=>'InPrivateModeAvailability','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'edgenosync' => ['cat'=>'Navigateur Edge','title'=>"Désactiver la synchronisation Edge",'icon'=>'🔁','scope'=>'Ordinateur',
        'desc'=>"Empêche la synchronisation du profil Edge vers un compte Microsoft externe.",
        'policies'=>[
            ['keyname'=>$K_EDGE,'valuename'=>'SyncDisabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_EDGE,'valuename'=>'BrowserGuestModeEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'edgenopersonal' => ['cat'=>'Navigateur Edge','title'=>"Bloquer les données personnelles à la fermeture (Edge)",'icon'=>'🧹','scope'=>'Ordinateur',
        'desc'=>"Empêche Edge de proposer l'import de données et désactive la personnalisation par le web.",
        'policies'=>[
            ['keyname'=>$K_EDGE,'valuename'=>'AutofillCreditCardEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_EDGE,'valuename'=>'AutofillAddressEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],

    // ═══ Navigateur Google Chrome (Ordinateur) ══════════════════════════════
    'chromehome' => ['cat'=>'Navigateur Chrome','title'=>"Page d'accueil intranet imposée (Chrome)",'icon'=>'🏠','scope'=>'Ordinateur',
        'desc'=>"Force la page d'accueil de Chrome vers l'intranet Bastion.",
        'policies'=>[
            ['keyname'=>$K_CHROME,'valuename'=>'HomepageLocation','class'=>'MACHINE','type'=>'REG_SZ','data'=>'http://192.168.182.1:2080/portal/intranet.php'],
            ['keyname'=>$K_CHROME,'valuename'=>'HomepageIsNewTabPage','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'chromenopwd' => ['cat'=>'Navigateur Chrome','title'=>"Désactiver le gestionnaire de mots de passe (Chrome)",'icon'=>'🔑','scope'=>'Ordinateur',
        'desc'=>"Empêche Chrome d'enregistrer les mots de passe.",
        'policies'=>[['keyname'=>$K_CHROME,'valuename'=>'PasswordManagerEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'chromenoincognito' => ['cat'=>'Navigateur Chrome','title'=>"Désactiver le mode navigation privée (Chrome)",'icon'=>'🕶️','scope'=>'Ordinateur',
        'desc'=>"Interdit le mode Incognito de Chrome (navigation journalisée).",
        'policies'=>[['keyname'=>$K_CHROME,'valuename'=>'IncognitoModeAvailability','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'chromenosync' => ['cat'=>'Navigateur Chrome','title'=>"Désactiver la synchronisation Chrome",'icon'=>'🔁','scope'=>'Ordinateur',
        'desc'=>"Empêche la synchronisation du profil Chrome vers un compte Google.",
        'policies'=>[
            ['keyname'=>$K_CHROME,'valuename'=>'SyncDisabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_CHROME,'valuename'=>'BrowserGuestModeEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'chromesafe' => ['cat'=>'Navigateur Chrome','title'=>"Navigation sécurisée renforcée (Chrome)",'icon'=>'🛡️','scope'=>'Ordinateur',
        'desc'=>"Active la protection Safe Browsing de Chrome au niveau standard/renforcé.",
        'policies'=>[['keyname'=>$K_CHROME,'valuename'=>'SafeBrowsingProtectionLevel','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Navigateur Mozilla Firefox (Ordinateur) ════════════════════════════
    'ffhome' => ['cat'=>'Navigateur Firefox','title'=>"Page d'accueil intranet imposée (Firefox)",'icon'=>'🏠','scope'=>'Ordinateur',
        'desc'=>"Force la page d'accueil de Firefox vers l'intranet Bastion et la verrouille.",
        'policies'=>[
            ['keyname'=>$K_FF.'\\Homepage','valuename'=>'URL','class'=>'MACHINE','type'=>'REG_SZ','data'=>'http://192.168.182.1:2080/portal/intranet.php'],
            ['keyname'=>$K_FF.'\\Homepage','valuename'=>'StartPage','class'=>'MACHINE','type'=>'REG_SZ','data'=>'homepage'],
            ['keyname'=>$K_FF.'\\Homepage','valuename'=>'Locked','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'ffnopwd' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver l'enregistrement des mots de passe (Firefox)",'icon'=>'🔑','scope'=>'Ordinateur',
        'desc'=>"Empêche Firefox de proposer et d'enregistrer les mots de passe.",
        'policies'=>[
            ['keyname'=>$K_FF,'valuename'=>'OfferToSaveLogins','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_FF,'valuename'=>'PasswordManagerEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'ffnoprivate' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver la navigation privée (Firefox)",'icon'=>'🕶️','scope'=>'Ordinateur',
        'desc'=>"Interdit les fenêtres de navigation privée (toute la navigation reste journalisée).",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'DisablePrivateBrowsing','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],

    // ── DNS chiffré : la stratégie la PLUS importante du catalogue ────────────
    // Le filtrage de contenu s'appuie sur le résolveur DNS de la passerelle. Un
    // simple « DNS sécurisé » coché dans le navigateur envoie les requêtes en
    // HTTPS à un résolveur public et ANNULE tout le filtrage — sans le moindre
    // droit administrateur. La passerelle refuse déjà les résolveurs DoH connus
    // (voir services/filter/doh-resolvers.txt), mais cette liste est un filet,
    // pas une barrière : un résolveur non listé passerait. Le seul correctif de
    // fond est de désactiver DoH DANS le navigateur, et de verrouiller le réglage.
    'ffnodoh' => ['cat'=>'Navigateur Firefox','title'=>"Interdire le DNS chiffré / DoH (Firefox)",'icon'=>'🔒','scope'=>'Ordinateur',
        'desc'=>"Désactive et VERROUILLE le « DNS sécurisé ». Sans cela, l'utilisateur contourne tout le filtrage de contenu en une case à cocher, sans droit administrateur.",
        'policies'=>[
            ['keyname'=>$K_FF.'\\DNSOverHTTPS','valuename'=>'Enabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
            ['keyname'=>$K_FF.'\\DNSOverHTTPS','valuename'=>'Locked','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'edgenodoh' => ['cat'=>'Navigateur Edge','title'=>"Interdire le DNS chiffré / DoH (Edge)",'icon'=>'🔒','scope'=>'Ordinateur',
        'desc'=>"Force Edge à utiliser le résolveur DNS du système — donc celui de Bastion, qui filtre.",
        'policies'=>[
            ['keyname'=>$K_EDGE,'valuename'=>'DnsOverHttpsMode','class'=>'MACHINE','type'=>'REG_SZ','data'=>'off'],
            ['keyname'=>$K_EDGE,'valuename'=>'BuiltInDnsClientEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'chromenodoh' => ['cat'=>'Navigateur Chrome','title'=>"Interdire le DNS chiffré / DoH (Chrome)",'icon'=>'🔒','scope'=>'Ordinateur',
        'desc'=>"Force Chrome à utiliser le résolveur DNS du système — donc celui de Bastion, qui filtre.",
        'policies'=>[
            ['keyname'=>$K_CHROME,'valuename'=>'DnsOverHttpsMode','class'=>'MACHINE','type'=>'REG_SZ','data'=>'off'],
            ['keyname'=>$K_CHROME,'valuename'=>'BuiltInDnsClientEnabled','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0],
        ]],
    'ffnotelemetry' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver la télémétrie (Firefox)",'icon'=>'📉','scope'=>'Ordinateur',
        'desc'=>"Coupe l'envoi des données de télémétrie et des rapports par Firefox.",
        'policies'=>[
            ['keyname'=>$K_FF,'valuename'=>'DisableTelemetry','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FF,'valuename'=>'DisableFirefoxStudies','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'ffnosync' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver les comptes Firefox / Sync",'icon'=>'🔁','scope'=>'Ordinateur',
        'desc'=>"Empêche la connexion à un compte Firefox et la synchronisation du profil.",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'DisableFirefoxAccounts','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'fftracking' => ['cat'=>'Navigateur Firefox','title'=>"Protection renforcée contre le pistage (Firefox)",'icon'=>'🛡️','scope'=>'Ordinateur',
        'desc'=>"Active et verrouille la protection contre le pistage, le minage et l'empreinte numérique.",
        'policies'=>[
            ['keyname'=>$K_FF.'\\EnableTrackingProtection','valuename'=>'Value','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FF.'\\EnableTrackingProtection','valuename'=>'Locked','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FF.'\\EnableTrackingProtection','valuename'=>'Cryptomining','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_FF.'\\EnableTrackingProtection','valuename'=>'Fingerprinting','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'ffnoconfig' => ['cat'=>'Navigateur Firefox','title'=>"Bloquer about:config (Firefox)",'icon'=>'🔒','scope'=>'Ordinateur',
        'desc'=>"Interdit l'accès à la page de configuration avancée about:config.",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'BlockAboutConfig','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'ffnopocket' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver Pocket (Firefox)",'icon'=>'📥','scope'=>'Ordinateur',
        'desc'=>"Retire l'intégration Pocket de Firefox.",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'DisablePocket','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'ffnoupdate' => ['cat'=>'Navigateur Firefox','title'=>"Désactiver la mise à jour automatique (Firefox)",'icon'=>'🧷','scope'=>'Ordinateur',
        'desc'=>"Désactive les mises à jour automatiques de Firefox (maîtrise des versions sur le parc).",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'DisableAppUpdate','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'ffnodefault' => ['cat'=>'Navigateur Firefox','title'=>"Ne pas vérifier le navigateur par défaut (Firefox)",'icon'=>'✅','scope'=>'Ordinateur',
        'desc'=>"Supprime l'invite « Firefox n'est pas votre navigateur par défaut » au démarrage.",
        'policies'=>[['keyname'=>$K_FF,'valuename'=>'DontCheckDefaultBrowser','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Microsoft Office (Ordinateur) ══════════════════════════════════════
    'officemacros' => ['cat'=>'Microsoft Office','title'=>"Bloquer les macros Office non signées",'icon'=>'📊','scope'=>'Ordinateur',
        'desc'=>"Désactive les macros VBA non signées dans Word, Excel et PowerPoint (avec notification).",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\Word\\Security','valuename'=>'VBAWarnings','class'=>'MACHINE','type'=>'REG_DWORD','data'=>3],
            ['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\Excel\\Security','valuename'=>'VBAWarnings','class'=>'MACHINE','type'=>'REG_DWORD','data'=>3],
            ['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\PowerPoint\\Security','valuename'=>'VBAWarnings','class'=>'MACHINE','type'=>'REG_DWORD','data'=>3],
        ]],
    'officemacrosnet' => ['cat'=>'Microsoft Office','title'=>"Bloquer les macros des fichiers Internet",'icon'=>'🌐','scope'=>'Ordinateur',
        'desc'=>"Bloque l'exécution des macros dans les documents provenant d'Internet (marque du web).",
        'policies'=>[
            ['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\Word\\Security','valuename'=>'BlockContentExecutionFromInternet','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\Excel\\Security','valuename'=>'BlockContentExecutionFromInternet','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'officenotelemetry' => ['cat'=>'Microsoft Office','title'=>"Réduire la télémétrie Office",'icon'=>'📉','scope'=>'Ordinateur',
        'desc'=>"Limite l'envoi de données de diagnostic par les applications Office.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Office\\16.0\\Common\\ClientTelemetry','valuename'=>'DisableTelemetry','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],

    // ═══ Sécurité réseau & accès distant (Ordinateur) ═══════════════════════
    'smbsign' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Signature SMB obligatoire",'icon'=>'✍️','scope'=>'Ordinateur',
        'desc'=>"Exige la signature numérique des communications SMB (client et serveur) contre les attaques relais.",
        'policies'=>[
            ['keyname'=>'SYSTEM\\CurrentControlSet\\Services\\LanmanWorkstation\\Parameters','valuename'=>'RequireSecuritySignature','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>'SYSTEM\\CurrentControlSet\\Services\\LanmanServer\\Parameters','valuename'=>'RequireSecuritySignature','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1],
        ]],
    'smbnoplain' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Interdire les mots de passe SMB en clair",'icon'=>'🔒','scope'=>'Ordinateur',
        'desc'=>"Empêche l'envoi de mots de passe non chiffrés à des serveurs SMB tiers.",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Services\\LanmanWorkstation\\Parameters','valuename'=>'EnablePlainTextPassword','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],
    'rdpdeny' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Désactiver le Bureau à distance (RDP)",'icon'=>'🖥️','scope'=>'Ordinateur',
        'desc'=>"Refuse toute connexion Bureau à distance entrante sur les postes.",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Terminal Server','valuename'=>'fDenyTSConnections','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'rdpnla' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Exiger l'authentification NLA pour RDP",'icon'=>'🔐','scope'=>'Ordinateur',
        'desc'=>"Impose l'authentification au niveau réseau (NLA) pour toute session RDP autorisée.",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows NT\\Terminal Services','valuename'=>'UserAuthentication','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'clearpagefile' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Effacer le fichier d'échange à l'arrêt",'icon'=>'🧽','scope'=>'Ordinateur',
        'desc'=>"Efface le fichier de pagination à chaque arrêt (empêche la récupération de données en mémoire).",
        'policies'=>[['keyname'=>'SYSTEM\\CurrentControlSet\\Control\\Session Manager\\Memory Management','valuename'=>'ClearPageFileAtShutdown','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'noconnecteduser' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Interdire les comptes Microsoft",'icon'=>'🚷','scope'=>'Ordinateur',
        'desc'=>"Empêche l'ajout et l'usage de comptes Microsoft personnels sur les postes du domaine.",
        'policies'=>[['keyname'=>$K_SYS_U,'valuename'=>'NoConnectedUser','class'=>'MACHINE','type'=>'REG_DWORD','data'=>3]]],
    'nogamedvr' => ['cat'=>'Sécurité réseau & accès distant','title'=>"Désactiver Xbox Game Bar / Game DVR",'icon'=>'🎮','scope'=>'Ordinateur',
        'desc'=>"Désactive l'enregistrement de jeu et la Game Bar (inutile sur poste professionnel).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\GameDVR','valuename'=>'AllowGameDVR','class'=>'MACHINE','type'=>'REG_DWORD','data'=>0]]],

    // ═══ Interface & bureau — compléments (Utilisateur) ═════════════════════
    'notoast' => ['cat'=>'Interface & bureau','title'=>"Désactiver les notifications toast",'icon'=>'🔕','scope'=>'Utilisateur',
        'desc'=>"Supprime les notifications toast des applications (postes en libre-service).",
        'policies'=>[['keyname'=>'Software\\Policies\\Microsoft\\Windows\\CurrentVersion\\PushNotifications','valuename'=>'NoToastApplicationNotification','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nospotlight' => ['cat'=>'Interface & bureau','title'=>"Désactiver Windows à la une (Spotlight)",'icon'=>'✨','scope'=>'Utilisateur',
        'desc'=>"Retire les suggestions et contenus Windows Spotlight (écran de verrouillage, menu Démarrer).",
        'policies'=>[
            ['keyname'=>$K_CLOUD,'valuename'=>'DisableWindowsSpotlightFeatures','class'=>'USER','type'=>'REG_DWORD','data'=>1],
            ['keyname'=>$K_CLOUD,'valuename'=>'DisableThirdPartySuggestions','class'=>'USER','type'=>'REG_DWORD','data'=>1],
        ]],
    'nopintaskbar' => ['cat'=>'Interface & bureau','title'=>"Interdire l'épinglage à la barre des tâches",'icon'=>'📎','scope'=>'Utilisateur',
        'desc'=>"Empêche l'utilisateur d'épingler ou de détacher des programmes de la barre des tâches.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoPinningToTaskbar','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'nolockworkstation' => ['cat'=>'Interface & bureau','title'=>"Masquer les options d'arrêt (session)",'icon'=>'⏻','scope'=>'Utilisateur',
        'desc'=>"Retire les commandes d'arrêt/redémarrage du menu Démarrer pour les agents.",
        'policies'=>[['keyname'=>$K_EXPL_U,'valuename'=>'NoClose','class'=>'USER','type'=>'REG_DWORD','data'=>1]]],
    'showext' => ['cat'=>'Interface & bureau','title'=>"Afficher les extensions de fichiers",'icon'=>'🏷️','scope'=>'Utilisateur',
        'desc'=>"Force l'affichage des extensions (protège contre les fichiers piégés « facture.pdf.exe »).",
        'policies'=>[['keyname'=>'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced','valuename'=>'HideFileExt','class'=>'USER','type'=>'REG_DWORD','data'=>0]]],

    // ═══ Windows Update — compléments (Ordinateur) ══════════════════════════
    'wunodriver' => ['cat'=>'Windows Update','title'=>"Exclure les pilotes de Windows Update",'icon'=>'🧷','scope'=>'Ordinateur',
        'desc'=>"Empêche Windows Update d'installer automatiquement des pilotes (maîtrise du parc matériel).",
        'policies'=>[['keyname'=>$K_WU,'valuename'=>'ExcludeWUDriversInQualityUpdate','class'=>'MACHINE','type'=>'REG_DWORD','data'=>1]]],
    'wutarget' => ['cat'=>'Windows Update','title'=>"Notifier avant téléchargement",'icon'=>'🔔','scope'=>'Ordinateur',
        'desc'=>"Configure Windows Update pour notifier avant de télécharger et installer (postes sensibles).",
        'policies'=>[['keyname'=>$K_WUAU,'valuename'=>'AUOptions','class'=>'MACHINE','type'=>'REG_DWORD','data'=>2]]],
];
