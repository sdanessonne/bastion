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

Stop-Transcript | Out-Null
Write-Host "`nRapport : C:\bastion-diag.txt  |  RSoP : C:\bastion-gpresult.html" -ForegroundColor Green
