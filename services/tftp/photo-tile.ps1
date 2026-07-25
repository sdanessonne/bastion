# Bastion - photo-tile.ps1
# (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
#
# Pose la photo de l'agent comme IMAGE DE COMPTE Windows (ecran de connexion, menu Demarrer,
# Parametres > Comptes).
#
# Pourquoi ce detour : Windows 11 ne lit PAS la photo de l'annuaire pour l'image de compte. Il
# faut deposer le fichier dans C:\Users\Public\AccountPictures\<SID>\ et l'enregistrer dans la
# base de registre - deux operations qui exigent des droits ADMINISTRATEUR. D'ou une tache
# planifiee executee en tant que SYSTEME a chaque ouverture de session, plutot qu'un script de
# session ordinaire (qui, lui, tourne sans privileges).
#
# Ce script ne fait qu'ecrire une image : il ne collecte rien et ne transmet rien.

$ErrorActionPreference = 'SilentlyContinue'
$GW    = '192.168.182.1'
$TOKEN = '__TOKEN__'
$dir   = 'C:\ProgramData\Bastion'
$log   = Join-Path $dir 'photo.log'
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
function Note($m) { ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) |
    Out-File -FilePath $log -Append -Encoding utf8 }

# Agent actuellement connecte (la tache tourne en SYSTEME : il faut le demander a la session).
$who = (Get-CimInstance Win32_ComputerSystem).UserName      # « DOMAINE\identifiant »
if (-not $who) { Note 'Aucune session ouverte, rien a faire.'; exit }
$login = $who.Split('\')[-1]
try {
    $sid = (New-Object Security.Principal.NTAccount($who)).Translate([Security.Principal.SecurityIdentifier]).Value
} catch { Note ("SID introuvable pour " + $who); exit }

# Ne rien refaire si la photo posee est deja la bonne (evite d'ecrire a chaque ouverture).
$marq = Join-Path $dir ("photo-$login.tag")
$dest = "C:\Users\Public\AccountPictures\$sid"

try {
    $tmp = Join-Path $env:TEMP "bastion-photo-$login.png"
    # TLS 1.2 AU MINIMUM : la console refuse explicitement TLS 1.0 et 1.1
    # (SSLProtocol ... -TLSv1 -TLSv1.1). Selon l'etat de .NET Framework et les strategies
    # de durcissement en place, PowerShell 5.1 peut encore proposer TLS 1.0 en premier ; la
    # connexion echoue alors sur un « Could not create SSL/TLS secure channel » qui ne dit
    # rien de la cause. On ajoute donc les protocoles au lieu de s'en remettre au defaut.
    # Valeurs numeriques (3072 = Tls12, 12288 = Tls13) et try separes : une version de .NET
    # qui ignore Tls13 leve une exception a l'affectation et ferait tout echouer.
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072 } catch { }
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 12288 } catch { }
    $cb = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    try {
        # « ${GW} » et non « $GW » : suivi de « : », PowerShell interprète le nom comme un
        # qualificateur de portée et la variable se résout à vide - l'adresse serait fausse.
        Invoke-WebRequest -Uri "https://${GW}:8443/api.php?action=poste.photo&user=$login" `
            -Headers @{ Authorization = "Bearer $TOKEN" } -OutFile $tmp -UseBasicParsing `
            -TimeoutSec 30 -ErrorAction Stop
    } finally { [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $cb }

    if (-not (Test-Path $tmp) -or (Get-Item $tmp).Length -lt 100) { Note "$login : aucune photo disponible"; exit }
    $sig = (Get-FileHash $tmp -Algorithm SHA256).Hash
    if ((Test-Path $marq) -and (Get-Content $marq -Raw).Trim() -eq $sig) { Remove-Item $tmp -Force; exit }

    if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest -Force | Out-Null }
    Add-Type -AssemblyName System.Drawing
    $src = [System.Drawing.Image]::FromFile($tmp)
    # Windows attend une image par taille ; il choisit la plus proche selon l'ecran.
    foreach ($px in 32, 40, 48, 96, 192, 208, 240, 424, 448, 1080) {
        $bmp = New-Object System.Drawing.Bitmap $px, $px
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        $g.InterpolationMode = 'HighQualityBicubic'
        $g.DrawImage($src, 0, 0, $px, $px)
        $bmp.Save((Join-Path $dest "Image$px.jpg"), [System.Drawing.Imaging.ImageFormat]::Jpeg)
        $g.Dispose(); $bmp.Dispose()
    }
    $src.Dispose(); Remove-Item $tmp -Force

    $rk = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\$sid"
    if (-not (Test-Path $rk)) { New-Item -Path $rk -Force | Out-Null }
    foreach ($px in 32, 40, 48, 96, 192, 208, 240, 424, 448, 1080) {
        Set-ItemProperty -Path $rk -Name "Image$px" -Value (Join-Path $dest "Image$px.jpg")
    }
    Set-Content -Path $marq -Value $sig
    Note "$login : photo appliquee ($sid)"
} catch {
    # Trait d'union ASCII, JAMAIS de tiret cadratin (U+2014) dans une chaine : PowerShell 5.1
    # lit un .ps1 sans marque d'ordre d'octets en CP1252, et le dernier octet de ce caractere
    # y VAUT un guillemet fermant -- il coupe la chaine en deux et rend le fichier entier
    # inanalysable. Ce script ne s'executait alors plus du tout, pas meme sa journalisation.
    # Voir services/scripts/psfile.py et services/scripts/check-scripts.py.
    Note ("$login : echec - " + $_.Exception.Message)
}
