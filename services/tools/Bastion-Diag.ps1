# Bastion - Bastion-Diag.ps1
# (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
#
# A COLLER DANS UNE FENETRE POWERSHELL EN ADMINISTRATEUR, sur le poste a diagnostiquer.
# Affiche tout a l'ecran (copiable) ET ecrit le rapport unique C:\bastion-diag.txt.
# Ne modifie rien de durable. Objectif : capturer les CODES d'erreur exacts
# (1058 lecture gpt.ini, ecart d'horloge/skew Kerberos, etat W32Time, Drive Maps 4098,
#  lettres reseau, client DFS) pour trancher les causes racines.

Start-Transcript -Path 'C:\bastion-diag.txt' -Append -Force | Out-Null
function H($t){ Write-Host "`n=== $t ===" -ForegroundColor Cyan }
function E($e){ if($e){$e|%{ Write-Host ("--- {0} Id={1} {2} [{3}]" -f $_.TimeCreated,$_.Id,$_.LevelDisplayName,$_.ProviderName) -ForegroundColor Yellow; Write-Host $_.Message; Write-Host ("Props: "+(($_.Properties|%{$_.Value}) -join ' | ')) }} else { Write-Host "  (aucun)" -ForegroundColor DarkGray } }

H "0. IDENTITE / HORLOGE / CANAL SECURISE"
Write-Host ("Poste $env:COMPUTERNAME  Session $env:USERDOMAIN\$env:USERNAME")
Write-Host ("Heure LOCALE : " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz'))
nltest /sc_query:bastion.pn.int
Test-ComputerSecureChannel -Verbose

H "0b. CODE WIN32 EXACT DU 1058 (repro directe de la lecture gpt.ini)"
$gpt="\\bastion.pn.int\sysvol\bastion.pn.int\Policies\{B3172638-E7F4-457F-A2DB-CB8AE2A81A30}\gpt.ini"
Write-Host ("Test-Path: " + (Test-Path -LiteralPath $gpt))
try { Get-Content -LiteralPath $gpt -ErrorAction Stop | Out-Null; Write-Host "Lecture OK" -ForegroundColor Green }
catch { Write-Host ("EXCEPTION: "+$_.Exception.Message) -ForegroundColor Red; Write-Host ("HRESULT: 0x{0:X8}" -f $_.Exception.HResult) -ForegroundColor Red }

H "0c. ACCES AUX PARTAGES : nom de SERVEUR vs nom de DOMAINE (le correctif lecteurs)"
foreach ($u in @('\\dc.bastion.pn.int\Commun','\\bastion.pn.int\Commun')) {
  Write-Host ("--- dir $u ---") -ForegroundColor Yellow
  cmd /c "dir ""$u"" 2>&1 & echo ERRLEVEL=%errorlevel%"
}

H "1. GROUP POLICY 1058/1030"
E (Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Microsoft-Windows-GroupPolicy';Id=1058,1030,1006} -MaxEvents 20 -ErrorAction SilentlyContinue)

H "2. SKEW / ECART D'HEURE vs DC (192.168.182.1 = source NTP)"
w32tm /stripchart /computer:192.168.182.1 /samples:3 /dataonly
net time \\192.168.182.1
klist
E (Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Microsoft-Windows-Time-Service'} -MaxEvents 20 -ErrorAction SilentlyContinue)

H "3. W32TIME : etat / source / configuration"
w32tm /query /status /verbose
w32tm /query /configuration
w32tm /query /source
sc.exe qc w32time

H "4. DRIVE MAPS : code exact (event 4098, source Group Policy)"
E (Get-WinEvent -FilterHashtable @{LogName='Application';Id=4098} -MaxEvents 30 -ErrorAction SilentlyContinue | Where-Object { $_.ProviderName -like 'Group Policy*' } | Select-Object -First 20)
gpresult /h C:\bastion-gpresult.html /f

H "5. LETTRES RESEAU + COLLISION VirtualBox"
net use
subst
Get-CimInstance Win32_LogicalDisk -Filter "DriveType=4" | Select-Object DeviceID,ProviderName,VolumeName | Format-Table -Auto
net view \\VBOXSVR 2>$null

H "6. CLIENT DFS (0x35 = BAD_NETPATH) + services cles"
Get-Service Dfsc,Mup,LanmanWorkstation,Netlogon,W32Time,gpsvc -ErrorAction SilentlyContinue | Format-Table Name,Status,StartType -Auto
sc.exe qc Dfsc

H "7. PHOTO DE L'AGENT (image de compte Windows)"
# But : dire POURQUOI la photo n'apparait pas, au lieu de le deviner. Chaque maillon est
# teste separement et affiche son resultat, y compris l'erreur exacte de l'appel HTTPS.
$dir = 'C:\ProgramData\Bastion'
Write-Host "--- Tache planifiee ---" -ForegroundColor Yellow
schtasks /query /tn "Bastion - Photo de l'agent" /v /fo LIST 2>&1 |
    Select-String 'Nom de la tache|TaskName|Statut|Status|Derniere|Last Run|Dernier resultat|Last Result'

Write-Host "--- Script pose sur le poste ---" -ForegroundColor Yellow
# Deux origines possibles : la STRATEGIE (bastion-photo.ps1, depose au demarrage) ou
# l'outil manuel de depannage (photo-tile.ps1). On cherche celui de la strategie en
# premier : c'est desormais le mecanisme normal.
$ps = Join-Path $dir 'bastion-photo.ps1'
$origine = 'strategie de groupe'
if (-not (Test-Path $ps)) { $ps = Join-Path $dir 'photo-tile.ps1'; $origine = 'outil manuel' }
Write-Host ("  origine : " + $origine)
if (Test-Path $ps) {
    $o = [IO.File]::ReadAllBytes($ps)
    $bom = ($o.Length -ge 3 -and $o[0] -eq 0xEF -and $o[1] -eq 0xBB -and $o[2] -eq 0xBF)
    Write-Host ("  {0}  {1} octets  marque UTF-8 : {2}" -f $ps, $o.Length, $(if($bom){'OUI'}else{'NON - script illisible par PowerShell 5.1 !'}))
    # Analyse SANS execution : si le fichier ne s'analyse pas, rien n'a jamais tourne.
    $err = $null
    [void][Management.Automation.Language.Parser]::ParseFile($ps, [ref]$null, [ref]$err)
    if ($err -and $err.Count) { Write-Host ("  ANALYSE KO : " + $err[0].Message) -ForegroundColor Red }
    else { Write-Host "  Analyse PowerShell : OK" -ForegroundColor Green }
} else {
    Write-Host "  AUCUN script photo sur ce poste." -ForegroundColor Red
    Write-Host "  -> soit la strategie « Bastion - Photo de l'agent » n'est pas encore descendue" -ForegroundColor Red
    Write-Host "     (elle s'applique au DEMARRAGE : redemarrez, ou gpupdate /target:computer /force)," -ForegroundColor Red
    Write-Host "  -> soit ce poste est hors domaine : lancez alors Install-BastionPhoto.cmd." -ForegroundColor Red
}

Write-Host "--- Journal ---" -ForegroundColor Yellow
if (Test-Path "$dir\photo.log") { Get-Content "$dir\photo.log" -Tail 15 }
else { Write-Host "  aucun journal : le script n'a jamais atteint sa premiere ligne" -ForegroundColor Red }

Write-Host "--- Appel a la passerelle (le maillon le plus fragile) ---" -ForegroundColor Yellow
$login = $env:USERNAME
$tok = ''
if (Test-Path $ps) { $m = [regex]::Match((Get-Content $ps -Raw), "\`$TOKEN\s*=\s*'([^']*)'"); if ($m.Success) { $tok = $m.Groups[1].Value } }
Write-Host ("  identifiant teste : {0}   jeton : {1}" -f $login, $(if($tok){'present'}else{'ABSENT'}))
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072 } catch { }
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 12288 } catch { }
Write-Host ("  protocoles proposes : " + [Net.ServicePointManager]::SecurityProtocol)
$cb = [Net.ServicePointManager]::ServerCertificateValidationCallback
[Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
try {
    $r = Invoke-WebRequest -Uri "https://192.168.182.1:8443/api.php?action=poste.photo&user=$login" `
         -Headers @{ Authorization = "Bearer $tok" } -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop
    Write-Host ("  HTTP {0} - {1} octets recus" -f $r.StatusCode, $r.RawContentLength) -ForegroundColor Green
} catch {
    Write-Host ("  ECHEC : " + $_.Exception.Message) -ForegroundColor Red
    if ($_.Exception.Response) { Write-Host ("  Code HTTP : " + [int]$_.Exception.Response.StatusCode) -ForegroundColor Red }
} finally { [Net.ServicePointManager]::ServerCertificateValidationCallback = $cb }

Write-Host "--- Image posee sur le poste ---" -ForegroundColor Yellow
try {
    $sid = (New-Object Security.Principal.NTAccount("$env:USERDOMAIN\$env:USERNAME")).Translate([Security.Principal.SecurityIdentifier]).Value
    Write-Host ("  SID : " + $sid)
    $d = "C:\Users\Public\AccountPictures\$sid"
    if (Test-Path $d) { Get-ChildItem $d | Select-Object Name,Length | Format-Table -Auto }
    else { Write-Host "  aucun dossier d'images de compte" -ForegroundColor Red }
    $rk = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\$sid"
    if (Test-Path $rk) { Write-Host ("  registre : " + ((Get-Item $rk).Property -join ', ')) }
    else { Write-Host "  registre : aucune entree" -ForegroundColor Red }
} catch { Write-Host ("  SID irresoluble : " + $_.Exception.Message) -ForegroundColor Red }
Write-Host "  RAPPEL : la photo n'apparait qu'apres FERMETURE puis REOUVERTURE de session." -ForegroundColor Cyan

Stop-Transcript | Out-Null
Write-Host "`nRapport : C:\bastion-diag.txt  |  RSoP : C:\bastion-gpresult.html" -ForegroundColor Green
