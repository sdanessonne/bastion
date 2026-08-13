#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Retrait de Microsoft Edge » : script de DÉMARRAGE (SYSTEM)
qui désinstalle Edge des postes du domaine et empêche sa réinstallation automatique.

── POURQUOI UN SCRIPT ET NON UNE STRATÉGIE DE REGISTRE ───────────────────────
Aucune clé de registre ne désinstalle un logiciel. Le catalogue de GPO sait très
bien BRIDER Edge (mot de passe, InPrivate, synchronisation — voir la catégorie
« Navigateur Edge »), mais le retirer suppose d'exécuter son propre programme de
désinstallation sur le poste, sous l'identité SYSTÈME. C'est un script de
démarrage, et rien d'autre.

── CE QUE MICROSOFT AUTORISE, ET CE QU'IL REFUSE ─────────────────────────────
Edge n'est pas désinstallable partout. Depuis Windows 11 23H2, Microsoft n'ouvre
la désinstallation que sur les postes dont la RÉGION appartient à l'Espace
économique européen — c'est une conséquence du règlement sur les marchés
numériques, pas une option de configuration. Un poste réglé sur une autre région
verra la commande échouer, sans que rien n'explique pourquoi.

La France est dans l'EEE, donc le cas normal fonctionne. Mais un poste réinstallé
depuis une image étrangère, ou dont la région a été changée à la main, échouera —
et c'est exactement le genre de panne qui ne s'annonce pas. Le script relève donc
le GeoId du poste dans son journal AVANT de tenter quoi que ce soit : quand la
désinstallation échoue, la cause est déjà écrite à la ligne du dessus.

── TROIS GARDE-FOUS ──────────────────────────────────────────────────────────
1. AUCUN AUTRE NAVIGATEUR, AUCUN RETRAIT. Un poste sans navigateur ne peut plus
   atteindre le portail captif — donc plus s'authentifier, donc plus rien du
   tout. Le script vérifie la présence de Firefox ou de Chrome et ABANDONNE en le
   disant si aucun n'est installé. Déployez le navigateur par le store AVANT
   cette stratégie.
2. WEBVIEW2 EST PRÉSERVÉ. C'est un produit distinct qui partage le moteur d'Edge,
   et dont dépendent Office, Teams et une part des logiciels du store. Le blocage
   de réinstallation l'autorise EXPLICITEMENT ({F3017226-…}), sans quoi la règle
   générale « ne rien installer » l'emporterait et casserait ces applications des
   mois plus tard, sans lien visible avec cette stratégie.
3. LA RÉINSTALLATION EST BLOQUÉE, PAS SEULEMENT LE LOGICIEL RETIRÉ. Sans cela
   Windows Update repose Edge en quelques jours et le parc revient à l'état
   initial, stratégie toujours affichée comme « déployée ».

── CE QUI EST RÉVERSIBLE, ET CE QUI NE L'EST PAS ─────────────────────────────
Le blocage de réinstallation vit sous « Software\\Policies\\… » : délier la GPO le
retire proprement et Edge revient de lui-même. La désinstallation, elle, ne se
défait pas toute seule — il faudra réinstaller Edge à la main sur les postes déjà
traités. La console le dit avant de déployer.

Usage : gpo-edge.py <{GUID}>
"""
import sys, os, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

SCRIPTS_CSE = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'

# Identifiants de produit du service de mise à jour d'Edge. Ils ne changent jamais :
# ce sont eux qui distinguent le navigateur (à bloquer) du moteur WebView2 (à garder).
APP_EDGE = '{56EB18F8-B008-4CBD-B6D2-8C97FE7E9062}'
APP_WEBVIEW2 = '{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}'


def copy_ntacl(src, dst):
    try:
        r = subprocess.run(['getfattr', '--absolute-names', '-n', 'security.NTACL', '-e', 'hex', src],
                           capture_output=True, text=True)
        val = ''
        for line in r.stdout.splitlines():
            if line.startswith('security.NTACL='):
                val = line.split('=', 1)[1].strip()
        if val:
            subprocess.run(['setfattr', '-n', 'security.NTACL', '-v', val, dst], capture_output=True)
    except Exception:
        pass


def ps1():
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "",
        "# Journal : un script de demarrage n'a personne a qui parler. Sans trace, un poste",
        "# ou Edge est toujours la laisse croire que la strategie n'est jamais descendue,",
        "# alors qu'elle s'est peut-etre executee et a refuse d'agir pour une bonne raison.",
        "$jrn=\"$env:windir\\Temp\\bastion-edge.log\"",
        "function Note($m) { try { Add-Content -Path $jrn -Encoding UTF8 -Value ('{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) } catch { } }",
        "Note '--- demarrage'",
        "",
        "$base='HKLM:\\SOFTWARE\\Bastion\\Edge'",
        "if (-not (Test-Path $base)) { New-Item -Path $base -Force | Out-Null }",
        "",
        "# Emplacements possibles du navigateur. Edge s'installe en 32 bits sur les postes",
        "# 64 bits ; le second chemin couvre les postes ARM et les futures editions.",
        "$racines = @(\"${env:ProgramFiles(x86)}\\Microsoft\\Edge\\Application\", \"$env:ProgramFiles\\Microsoft\\Edge\\Application\")",
        "$exes = @($racines | ForEach-Object { Join-Path $_ 'msedge.exe' })",
        "function EdgePresent { @($exes | Where-Object { Test-Path -LiteralPath $_ }).Count -gt 0 }",
        "",
        "# ── 1. Garde-fou : ne jamais laisser un poste sans navigateur ────────────",
        "# Sans navigateur, l'agent ne peut plus ouvrir le portail captif : il ne",
        "# s'authentifie plus, donc il n'a plus de reseau du tout. Le retrait d'Edge",
        "# transformerait alors un poste fonctionnel en poste muet, et le lien avec cette",
        "# strategie ne serait pas fait. On abandonne, et on ecrit pourquoi.",
        "$autres = @(",
        "  \"$env:ProgramFiles\\Mozilla Firefox\\firefox.exe\",",
        "  \"${env:ProgramFiles(x86)}\\Mozilla Firefox\\firefox.exe\",",
        "  \"$env:ProgramFiles\\Google\\Chrome\\Application\\chrome.exe\",",
        "  \"${env:ProgramFiles(x86)}\\Google\\Chrome\\Application\\chrome.exe\")",
        "$presents = @($autres | Where-Object { Test-Path -LiteralPath $_ })",
        "if ($presents.Count -eq 0) {",
        "  Note 'ABANDON : aucun autre navigateur installe (ni Firefox ni Chrome).'",
        "  Note '  Retirer Edge couperait l acces au portail captif, donc tout le reseau.'",
        "  Note '  Deployez un navigateur par le store d applications, puis relancez.'",
        "  Note '--- fin'",
        "  exit 0",
        "}",
        "Note ('navigateur de remplacement present : ' + ($presents[0]))",
        "",
        "# ── 2. Bloquer la reinstallation ─────────────────────────────────────────",
        "# Sans ce blocage, Windows Update repose Edge en quelques jours et le parc revient",
        "# a son etat initial — la strategie restant affichee comme deployee.",
        "# Ces valeurs vivent sous Software\\Policies : delier la GPO les retire proprement.",
        "$pol='HKLM:\\SOFTWARE\\Policies\\Microsoft\\EdgeUpdate'",
        "if (-not (Test-Path $pol)) { New-Item -Path $pol -Force | Out-Null }",
        "try {",
        "  Set-ItemProperty -Path $pol -Name 'InstallDefault' -Value 0 -Type DWord",
        "  Set-ItemProperty -Path $pol -Name 'Install" + APP_EDGE + "' -Value 0 -Type DWord",
        "  Set-ItemProperty -Path $pol -Name 'Update" + APP_EDGE + "' -Value 0 -Type DWord",
        "  # WebView2 AUTORISE EXPLICITEMENT : c'est un produit distinct, dont dependent",
        "  # Office, Teams et une part du store. « InstallDefault=0 » le bloquerait sinon,",
        "  # et ces logiciels casseraient des mois plus tard sans lien visible avec Edge.",
        "  Set-ItemProperty -Path $pol -Name 'Install" + APP_WEBVIEW2 + "' -Value 1 -Type DWord",
        "  Note 'reinstallation bloquee (WebView2 preserve)'",
        "} catch { Note ('blocage de reinstallation : ECHEC — ' + $_.Exception.Message) }",
        "",
        "# ── 3. Deja fait ? ───────────────────────────────────────────────────────",
        "if (-not (EdgePresent)) {",
        "  Note 'Edge absent : rien a desinstaller'",
        "  Set-ItemProperty -Path $base -Name 'retire' -Value 1 -Type DWord",
        "  Note '--- fin'",
        "  exit 0",
        "}",
        "",
        "# ── 4. La region, relevee AVANT d'agir ───────────────────────────────────",
        "# Microsoft n'ouvre la desinstallation d'Edge que dans l'Espace economique",
        "# europeen. Un poste regle sur une autre region echouera sur un code de sortie nu.",
        "# On ecrit le GeoId maintenant : quand l'echec survient, sa cause est deja au",
        "# journal, une ligne plus haut. 84 = France.",
        "try { $geo = (Get-WinHomeLocation).GeoId } catch { $geo = 'illisible' }",
        "Note (\"region du poste (GeoId) : $geo   [84 = France ; hors EEE, Microsoft refuse le retrait]\")",
        "",
        "# ── 5. Desinstallation ───────────────────────────────────────────────────",
        "# Le programme de desinstallation vit dans le dossier de la VERSION installee :",
        "# son chemin change a chaque mise a jour d'Edge et ne peut donc pas etre code en dur.",
        "$setup = $null",
        "foreach ($r in $racines) {",
        "  if (-not (Test-Path -LiteralPath $r)) { continue }",
        "  $cand = Get-ChildItem -LiteralPath $r -Directory -ErrorAction SilentlyContinue |",
        "    Where-Object { $_.Name -match '^[0-9]+\\.' } |",
        "    Sort-Object -Property @{ Expression = { try { [version]$_.Name } catch { [version]'0.0' } } } -Descending |",
        "    ForEach-Object { Join-Path $_.FullName 'Installer\\setup.exe' } |",
        "    Where-Object { Test-Path -LiteralPath $_ } |",
        "    Select-Object -First 1",
        "  if ($cand) { $setup = $cand; break }",
        "}",
        "if (-not $setup) {",
        "  Note 'ECHEC : msedge.exe est present mais son programme de desinstallation est introuvable.'",
        "  Note '  Edge est probablement en cours de mise a jour. La strategie reessaiera au prochain demarrage.'",
        "  Note '--- fin'",
        "  exit 0",
        "}",
        "Note (\"programme de desinstallation : $setup\")",
        "",
        "try {",
        "  $p = Start-Process -FilePath $setup -PassThru -Wait -WindowStyle Hidden -ArgumentList @(",
        "    '--uninstall', '--system-level', '--verbose-logging', '--force-uninstall')",
        "  Note ('desinstallation terminee, code de sortie ' + $p.ExitCode)",
        "} catch { Note ('desinstallation : ECHEC de lancement — ' + $_.Exception.Message) }",
        "",
        "# ── 6. Constater, et non croire le code de sortie ────────────────────────",
        "# Le programme d'Edge rend la main avec un code 0 dans des cas ou il n'a rien",
        "# retire. Seule la disparition du fichier fait foi.",
        "if (EdgePresent) {",
        "  Note 'ECHEC VERIFIE : msedge.exe est toujours present apres desinstallation.'",
        "  Note '  Cause la plus frequente : region du poste hors EEE (voir le GeoId ci-dessus).'",
        "  Note '  Autre cause possible : Edge etait ouvert. Il se retirera au prochain demarrage.'",
        "} else {",
        "  Note 'RETIRE ET VERIFIE : msedge.exe absent'",
        "  Set-ItemProperty -Path $base -Name 'retire' -Value 1 -Type DWord",
        "  # Raccourcis laisses derriere : un raccourci mort sur le Bureau de chaque agent",
        "  # ressemble a une panne et fait remonter des appels pour rien.",
        "  $lnk = @(",
        "    \"$env:PUBLIC\\Desktop\\Microsoft Edge.lnk\",",
        "    \"$env:ProgramData\\Microsoft\\Windows\\Start Menu\\Programs\\Microsoft Edge.lnk\")",
        "  foreach ($l in $lnk) { if (Test-Path -LiteralPath $l) { Remove-Item -LiteralPath $l -Force; Note (\"raccourci retire : $l\") } }",
        "}",
        "",
        "# WebView2 : on VERIFIE qu'il a survecu, plutot que de le supposer. S'il est parti,",
        "# Office et Teams tomberont en panne plus tard, et personne ne remontera jusqu'ici.",
        "$wv = @(\"${env:ProgramFiles(x86)}\\Microsoft\\EdgeWebView\\Application\", \"$env:ProgramFiles\\Microsoft\\EdgeWebView\\Application\")",
        "if (@($wv | Where-Object { Test-Path -LiteralPath $_ }).Count -gt 0) { Note 'WebView2 : toujours en place' }",
        "else { Note 'ATTENTION : WebView2 introuvable — Office, Teams et certains logiciels du store peuvent ne plus demarrer.' }",
        "Note '--- fin'",
    ]
    return "\r\n".join(L) + "\r\n"


def main():
    guid = sys.argv[1]
    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable', file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    for d in (os.path.join(sysvol, 'Machine'), os.path.join(sysvol, 'Machine', 'Scripts'), startup):
        if os.path.exists(ref): copy_ntacl(ref, d)

    psfile.ecrire_ps1(os.path.join(startup, 'bastion-edge.ps1'), ps1())
    psfile.ecrire_cmd(os.path.join(startup, 'bastion-edge.cmd'),
                      '@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass '
                      '-File "%~dp0bastion-edge.ps1"\r\n')
    with open(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'), 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-edge.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in ('Startup/bastion-edge.ps1', 'Startup/bastion-edge.cmd', 'scripts.ini'):
        p = os.path.join(sysvol, 'Machine', 'Scripts', f)
        os.chmod(p, 0o644)
        if os.path.exists(ref): copy_ntacl(ref, p)

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)   # incrémente la version ORDINATEUR
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                       ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d' % newver)


if __name__ == '__main__':
    main()
