# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Vérifie que les scripts PowerShell FABRIQUÉS par les générateurs de stratégies sont
# analysables par Windows PowerShell 5.1 — celui de tous les postes du parc.
#
# ── POURQUOI CE CONTRÔLE EXISTE ───────────────────────────────────────────────
# Le collecteur d'inventaire a contenu pendant des mois une construction refusée par
# PowerShell 5.1 (« try » comme valeur dans une table de hachage multi-lignes). Le
# fichier était rejeté AVANT sa première instruction : aucun inventaire ne remontait,
# aucun message nulle part, et la console affichait simplement une liste vide — que
# l'on pouvait prendre pour « aucun poste ne s'est encore signalé ».
#
# La passerelle tourne sous Debian : elle n'a aucun moyen d'analyser du PowerShell.
# Ce contrôle se lance donc depuis le poste de développement, sous Windows.
#
# Usage :  powershell -ExecutionPolicy Bypass -File scripts\verifier-gpo-ps1.ps1
# Sortie :  code 0 si tout passe, 1 sinon.

$ErrorActionPreference = 'Stop'
$racine = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$gen    = Join-Path $racine 'services\scripts'
$tmp    = Join-Path ([IO.Path]::GetTempPath()) ('bastion-ps1-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $tmp | Out-Null

# Arguments plausibles pour chaque générateur : on ne cherche pas à produire la vraie
# stratégie, seulement du PowerShell représentatif de ce qui sera déposé au SYSVOL.
$cas = @(
    @{ f = 'gpo-inventory.py'; a = "'https://192.168.182.1:2443/admin/api.php?a=inv', 'JETON'" }
    @{ f = 'gpo-kms.py';       a = "'192.168.182.1', True" }
    @{ f = 'gpo-kms.py';       a = "'192.168.182.1', False"; s = 'sans-montee' }
    @{ f = 'gpo-timesync.py';  a = "'192.168.182.1'" }
    @{ f = 'gpo-numlock.py';   a = "" }
    # Trois tailles de liste pour les applications : c'est la VIRGULE de fin qui avait
    # rendu le script inanalysable, et elle ne se manifeste qu'au dernier élément.
    @{ f = 'gpo-apps.py'; a = "[]";                                                                   s = 'aucune appli' }
    @{ f = 'gpo-apps.py'; a = "[{'marker':'Firefox','url':'https://x/f.msi','args':'/qn','msi':True}]"; s = 'une appli' }
    @{ f = 'gpo-apps.py'; a = "[{'marker':'A','url':'https://x/a.msi','args':'/qn','msi':True}, {'marker':'B','url':'https://x/b.exe','args':'/S','msi':False}]"; s = 'deux applis' }
)

$py = (Get-Command python -ErrorAction SilentlyContinue)
if (-not $py) { $py = (Get-Command python3 -ErrorAction SilentlyContinue) }
if (-not $py) { Write-Host "python introuvable : impossible de fabriquer les scripts." -ForegroundColor Red; exit 1 }

$ko = 0
foreach ($c in $cas) {
    $nom = if ($c.s) { "$($c.f) ($($c.s))" } else { $c.f }
    $out = Join-Path $tmp (($c.f -replace '\.py$', '') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 4) + '.ps1')
    # Le générateur importe « psfile », qui écrit dans le SYSVOL : on le remplace par un
    # module vide pour n'appeler que la fabrication de texte, sans aucun effet de bord.
    $code = @"
import importlib.util, sys, io
sys.modules['psfile'] = type(sys)('psfile')
spec = importlib.util.spec_from_file_location('g', r'$(Join-Path $gen $c.f)')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
open(r'$out', 'wb').write(b'\xef\xbb\xbf' + m.ps1($($c.a)).encode('utf-8'))
"@
    $f = Join-Path $tmp 'g.py'
    Set-Content -Path $f -Value $code -Encoding UTF8
    # La sortie du generateur est CONSERVEE : s'il echoue, sa trace est la seule
    # explication disponible — l'avaler reviendrait a masquer la panne.
    $trace = & $py.Source $f 2>&1
    if (-not (Test-Path $out)) {
        Write-Host "  ECHEC  $nom : le generateur n'a rien produit" -ForegroundColor Red
        $trace | Select-Object -Last 4 | ForEach-Object { Write-Host "           $_" }
        $ko++; continue
    }
    $e = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile($out, [ref]$null, [ref]$e)
    if ($e.Count -eq 0) {
        Write-Host "  OK     $nom" -ForegroundColor Green
    } else {
        Write-Host "  ECHEC  $nom" -ForegroundColor Red
        $e | Select-Object -First 3 | ForEach-Object {
            Write-Host ("           ligne {0} : {1}" -f $_.Extent.StartLineNumber, $_.Message)
            Write-Host ("           > " + (Get-Content $out)[$_.Extent.StartLineNumber - 1])
        }
        $ko++
    }
}

Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
Write-Host ""
if ($ko -eq 0) { Write-Host "Tous les scripts de strategie s'analysent sous PowerShell $($PSVersionTable.PSVersion)." -ForegroundColor Green; exit 0 }
Write-Host "$ko script(s) ne s'analyseraient pas sur les postes — ils ne feraient RIEN une fois deployes." -ForegroundColor Red
exit 1
