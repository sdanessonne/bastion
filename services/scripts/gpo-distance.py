#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Stratégie « Bastion — Prise de main à distance ».

Pose et configure le client de prise de main sur les postes du domaine, puis
remonte l'identifiant du poste à la console — sans lui, l'administrateur n'a
aucun moyen de savoir quel poste appeler.

Le poste se connecte en SORTANT vers le relais hébergé sur la passerelle. Rien
n'entre dans le réseau des postes : c'est ce qui permet de garder l'isolement
entre le réseau d'administration et celui du parc.

Le MODE DE CONSENTEMENT n'est pas figé dans le script : il est demandé à la
console au démarrage. Ainsi un changement de politique n'oblige pas à
régénérer la stratégie, et un poste qui n'obtient pas de réponse retombe sur
le mode le plus protecteur — accord obligatoire de l'agent.

Usage : gpo-distance.py <{GUID}> <IP de la passerelle> <jeton> <clé publique du relais>
"""
import sys

PS1 = r'''
$ErrorActionPreference = 'Stop'
$dir = 'C:\ProgramData\Bastion'
New-Item -ItemType Directory -Path $dir -Force | Out-Null
$jrn = Join-Path $dir 'distance.log'
function Note($m) {
  try { ("{0:yyyy-MM-dd HH:mm:ss}  {1}" -f (Get-Date), $m) | Add-Content -Path $jrn -Encoding UTF8 } catch { }
}
Note '--- Prise de main a distance ---'

$GW    = '__GW__'
$TOKEN = '__TOKEN__'
$CLE   = '__CLE__'
$exe   = Join-Path ${env:ProgramFiles} 'RustDesk\rustdesk.exe'

# ── 1. Installer le client s'il manque ────────────────────────────────────────
# L'installeur est servi par la passerelle, comme ceux du store : le poste n'a
# pas besoin d'atteindre Internet pour cela.
if (-not (Test-Path $exe)) {
  $src = "https://${GW}:2443/apps/rustdesk-client.exe"
  $tmp = Join-Path $env:TEMP 'rustdesk-setup.exe'
  try {
    # Certificat auto-signe de la passerelle : on l'accepte explicitement pour CET appel.
    Add-Type -TypeDefinition 'using System.Net;using System.Security.Cryptography.X509Certificates;public class BPass:ICertificatePolicy{public bool CheckValidationResult(ServicePoint s,X509Certificate c,WebRequest r,int p){return true;}}' -ErrorAction SilentlyContinue
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object BPass
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $src -OutFile $tmp -UseBasicParsing -TimeoutSec 300
    Note ('Installeur recupere (' + [math]::Round((Get-Item $tmp).Length/1MB,1) + ' Mo).')
    Start-Process -FilePath $tmp -ArgumentList '--silent-install' -Wait -NoNewWindow
    Start-Sleep -Seconds 8
  } catch {
    # Dit, jamais avale : sans client, tout le reste est sans objet.
    Note ('ECHEC installation : ' + $_.Exception.Message)
    exit 1
  }
}
if (-not (Test-Path $exe)) { Note 'ECHEC : le client reste absent apres installation.'; exit 1 }

# ── 2. Demander le mode de consentement a la console ──────────────────────────
# Repli volontaire sur « accord obligatoire » : si la console ne repond pas, on
# ne bascule PAS un poste en prise de main libre par accident.
$mode = 'accord'
try {
  $r = Invoke-RestMethod -Uri ("https://${GW}:2443/api.php?action=poste.distance&poste=" + $env:COMPUTERNAME) `
       -Headers @{ Authorization = "Bearer $TOKEN" } -TimeoutSec 20
  if ($r.mode -eq 'libre') { $mode = 'libre' }
  Note ("Mode de consentement rendu par la console : " + $mode)
} catch {
  Note ('Console injoignable pour le mode de consentement (' + $_.Exception.Message + ') : accord obligatoire par defaut.')
}

# ── 3. Ecrire la configuration ────────────────────────────────────────────────
# Le service tourne en LocalService : c'est SA configuration qu'il faut ecrire,
# pas celle de l'agent connecte. Une configuration posee dans le profil de
# l'utilisateur serait ignoree par le service, sans le moindre message.
$cfgDir = 'C:\Windows\ServiceProfiles\LocalService\AppData\Roaming\RustDesk\config'
New-Item -ItemType Directory -Path $cfgDir -Force | Out-Null

# « approve-mode = click » : l'agent doit accepter la prise de main.
# « approve-mode = password » : acces sans accord, reserve aux postes designes.
$approb = if ($mode -eq 'libre') { 'password' } else { 'click' }

$toml = @"
rendezvous_server = '$GW'
nat_type = 1
serial = 0

[options]
custom-rendezvous-server = '$GW'
relay-server = '$GW'
key = '$CLE'
approve-mode = '$approb'
direct-server = 'N'
enable-audio = 'N'
"@
try {
  Set-Content -Path (Join-Path $cfgDir 'RustDesk2.toml') -Value $toml -Encoding UTF8
  Note ("Configuration ecrite (relais $GW, approbation $approb).")
} catch {
  Note ('ECHEC ecriture de la configuration : ' + $_.Exception.Message)
  exit 1
}

# Redemarrage du service pour qu'il relise sa configuration : sans cela le poste
# continue de s'annoncer au serveur public de l'editeur jusqu'au prochain boot.
try { Restart-Service -Name RustDesk -Force -ErrorAction Stop; Start-Sleep -Seconds 5; Note 'Service redemarre.' }
catch { Note ('Service non redemarre : ' + $_.Exception.Message) }

# ── 4. Remonter l'identifiant du poste ────────────────────────────────────────
# C'est le seul lien entre un nom de machine et l'identifiant a appeler. Sans
# cette remontee, l'administrateur devrait se deplacer pour lire l'ecran du poste
# — ce que la prise de main a distance est precisement censee eviter.
try {
  $id = (& $exe --get-id 2>$null | Select-Object -First 1).Trim()
  if ($id -match '^\d{6,}$') {
    $corps = @{ poste = $env:COMPUTERNAME; distance_id = $id; mode = $mode } | ConvertTo-Json -Compress
    Invoke-RestMethod -Uri "https://${GW}:2443/api.php?action=poste.distance" -Method Post `
      -Headers @{ Authorization = "Bearer $TOKEN" } -ContentType 'application/json' -Body $corps -TimeoutSec 20 | Out-Null
    Note ("Identifiant $id remonte a la console.")
  } else {
    Note ("Identifiant illisible (recu : '" + $id + "') — le poste n'apparaitra pas dans la console.")
  }
} catch {
  Note ('ECHEC remontee de l identifiant : ' + $_.Exception.Message)
}
Note '--- Fin ---'
'''


def ps1(gw: str, token: str, cle: str) -> str:
    return PS1.replace('__GW__', gw).replace('__TOKEN__', token).replace('__CLE__', cle)


def main():
    if len(sys.argv) < 5:
        print('usage: gpo-distance.py <{GUID}> <GW_IP> <jeton> <cle>', file=sys.stderr)
        sys.exit(2)
    guid, gw, token, cle = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
    try:
        import psfile
    except ImportError:
        print(ps1(gw, token, cle))
        return
    psfile.deposer(guid, 'distance.ps1', ps1(gw, token, cle))


if __name__ == '__main__':
    main()
