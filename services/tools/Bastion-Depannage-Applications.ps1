# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés.
#
# Débloquer l'installation des logiciels sur un poste.
#
# À LANCER EN ADMINISTRATEUR, depuis le lecteur commun :
#   clic droit sur le fichier > « Exécuter avec PowerShell »
#   ou :  powershell -ExecutionPolicy Bypass -File .\Bastion-Depannage-Applications.ps1
#
# POURQUOI CET OUTIL
# La stratégie installe les logiciels l'un après l'autre. Si un installeur ne rend
# jamais la main — cas vécu avec SumatraPDF le 2026-08-07 — le script l'attend
# indéfiniment : il n'écrit aucun marqueur, et TOUS les logiciels suivants restent
# non installés. Au démarrage d'après, tout recommence depuis le début.
#
# Rien ici n'installe quoi que ce soit. On CONSTATE, et on débloque si nécessaire.

$ErrorActionPreference = 'Continue'
$dir  = 'C:\ProgramData\Bastion'
$jrn  = Join-Path $dir 'apps.log'
$base = 'HKLM:\Software\Bastion\Apps'

function Titre($t) { Write-Host ""; Write-Host $t -ForegroundColor Cyan; Write-Host ('-' * $t.Length) -ForegroundColor DarkGray }

Write-Host "Bastion — diagnostic d'installation des logiciels" -ForegroundColor White
Write-Host "Poste : $env:COMPUTERNAME    $(Get-Date -Format 'dd/MM/yyyy HH:mm')"

# ── 1. Un installeur est-il resté en suspens ? ────────────────────────────────
Titre 'Installeur bloque'
# Les installeurs deposes par la strategie vivent dans le TEMP du compte SYSTEM et
# portent le prefixe « bastion_ ». C'est ce prefixe qui permet de ne PAS toucher a
# un installeur lance par un agent.
$tempSys = 'C:\Windows\Temp'
$suspects = Get-Process -ErrorAction SilentlyContinue | Where-Object {
    $p = $null
    try { $p = $_.Path } catch { }
    $p -and ($p -like "$tempSys\bastion_*")
}
if (-not $suspects) {
    Write-Host "  Aucun installeur Bastion en cours." -ForegroundColor Green
} else {
    foreach ($s in $suspects) {
        $age = [int]((Get-Date) - $s.StartTime).TotalMinutes
        Write-Host ("  {0} (PID {1}) lance depuis {2} min" -f $s.ProcessName, $s.Id, $age) -ForegroundColor Yellow
        Write-Host "    $($s.Path)" -ForegroundColor DarkGray
        if ($age -ge 10) {
            # Dix minutes : au-dela, aucun installeur silencieux legitime ne tourne
            # encore. En dessous, on ne touche a rien — tuer une installation en cours
            # laisserait le logiciel a moitie pose, ce qui est pire que d'attendre.
            $r = Read-Host "    Arreter ce processus bloque ? (o/N)"
            if ($r -eq 'o') {
                Stop-Process -Id $s.Id -Force -ErrorAction SilentlyContinue
                Write-Host "    Arrete. La file reprendra au prochain demarrage." -ForegroundColor Green
            }
        } else {
            Write-Host "    Moins de 10 min : installation probablement en cours, on n y touche pas." -ForegroundColor DarkGray
        }
    }
}

# ── 2. Ce qui est pose, et ce qui manque ──────────────────────────────────────
Titre 'Logiciels'
$marqueurs = @{}
if (Test-Path $base) {
    (Get-Item $base).GetValueNames() | ForEach-Object { $marqueurs[$_] = (Get-Item $base).GetValue($_) }
}
if (-not $marqueurs.Count) {
    Write-Host "  Aucun marqueur : la strategie n a jamais abouti sur ce poste." -ForegroundColor Yellow
} else {
    $poses = $marqueurs.Keys | Where-Object { $_ -notlike '*_ko' } | Sort-Object
    $rates = $marqueurs.Keys | Where-Object { $_ -like '*_ko' } | Sort-Object
    Write-Host ("  Installes : {0}" -f ($poses -join ', ')) -ForegroundColor Green
    if ($rates) {
        foreach ($r in $rates) {
            Write-Host ("  Echecs    : {0} -> {1} tentative(s)" -f $r.Replace('_ko',''), $marqueurs[$r]) -ForegroundColor Yellow
        }
        Write-Host "  Au-dela de 3 tentatives, le logiciel est abandonne et cesse de bloquer les autres." -ForegroundColor DarkGray
    }
}

# ── 3. La derniere passe s est-elle terminee ? ────────────────────────────────
Titre 'Derniere passe'
if (-not (Test-Path $jrn)) {
    Write-Host "  Journal absent ($jrn) : la strategie ne s est jamais executee ici." -ForegroundColor Yellow
} else {
    $lignes = Get-Content $jrn -Tail 400
    $debuts = @($lignes | Select-String -SimpleMatch '--- Installation des applications' )
    $fins   = @($lignes | Select-String -SimpleMatch '--- Fin ---')
    if ($debuts.Count -gt $fins.Count) {
        # Le signal qui compte : une passe commencee sans « Fin » n a pas abouti,
        # et c est exactement ce qui laisse les logiciels suivants non installes.
        Write-Host "  La derniere passe n a PAS abouti : demarree, jamais terminee." -ForegroundColor Red
        Write-Host "  C est ce qui empeche les logiciels suivants de s installer." -ForegroundColor Red
    } else {
        Write-Host "  Derniere passe terminee normalement." -ForegroundColor Green
    }
    Write-Host ""
    Write-Host "  Dix dernieres lignes du journal :" -ForegroundColor DarkGray
    $lignes | Select-Object -Last 10 | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }
}

# ── 4. La strategie est-elle bien arrivee ? ───────────────────────────────────
Titre 'Strategie'
$gp = gpresult /r /scope:computer 2>$null | Select-String -SimpleMatch 'Bastion'
if ($gp) { $gp | ForEach-Object { Write-Host "  $($_.Line.Trim())" } }
else { Write-Host "  Aucune strategie Bastion appliquee — lancez : gpupdate /force" -ForegroundColor Yellow }

Write-Host ""
Write-Host "Pour relancer l installation : gpupdate /force puis redemarrer le poste." -ForegroundColor White
Write-Host "Le journal complet : $jrn"
Write-Host ""
Read-Host "Appuyez sur Entree pour fermer"
