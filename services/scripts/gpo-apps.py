#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère/actualise la GPO « Bastion — Applications » : un script de DÉMARRAGE
(exécuté en tant que SYSTEM au boot du poste) télécharge depuis la passerelle et
installe en silence les applications sélectionnées dans le « store ». Un marqueur
registre évite la réinstallation à chaque démarrage.

Usage : gpo-apps.py <{GUID}> <apps.json>
apps.json : {"gw":"192.168.182.1","apps":[
  {"marker":"7zip","url":"http://.../apps/7zip.msi","args":"/qn","msi":true}, ...]}
"""
import sys, os, json, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

# CSE « Scripts » (démarrage/arrêt) + outil administratif standard.
SCRIPTS_CSE = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'

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

def ps1(apps, retraits=()):
    """Construit le script PowerShell d'installation."""
    lines = [
        "$ErrorActionPreference = 'SilentlyContinue'",
        "$base = 'HKLM:\\Software\\Bastion\\Apps'",
        "New-Item -Path $base -Force | Out-Null",
        "$apps = @(",
    ]
    # La VIRGULE ne suit JAMAIS le dernier élément : « @( …, ) » est une erreur de syntaxe
    # PowerShell (« Expression manquante après , »), et le script échouait alors à l'ANALYSE —
    # donc avant la moindre ligne de code, sans installer quoi que ce soit ni écrire de journal.
    # C'est la raison pour laquelle aucune application ne s'installait.
    for i, a in enumerate(apps):
        marker = a['marker'].replace("'", "")
        url = a['url'].replace("'", "")
        args = str(a.get('args', '')).replace("'", "''")
        msi = 'true' if a.get('msi') else 'false'
        virgule = ',' if i < len(apps) - 1 else ''
        nom = str(a.get('nom', a['marker'])).replace("'", "''")
        lines.append("  @{ marker='%s'; nom='%s'; url='%s'; args='%s'; msi=$%s }%s"
                     % (marker, nom, url, args, msi, virgule))
    lines += [
        ")",
        "# JOURNAL : sans lui, un echec restait totalement invisible (le script avale ses erreurs",
        "# pour ne pas deranger l'agent). On trace donc chaque etape dans un fichier lisible, que",
        "# l'outil « Install-BastionApps.cmd » affiche ensuite a l'ecran.",
        "$dir='C:\\ProgramData\\Bastion'",
        "if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }",
        "$log=Join-Path $dir 'apps.log'",
        "function Note($m) { ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) |",
        "    Out-File -FilePath $log -Append -Encoding utf8 }",
        "Note ('--- Installation des applications (utilisateur ' + $env:USERNAME + ') ---')",
        "",
        "# ── INFORMER L'AGENT ─────────────────────────────────────────────────",
        "# Ce script tourne en SYSTEM : il ne peut RIEN afficher dans la session d'un",
        "# agent (isolation de la session 0). Il ecrit donc sa progression dans un",
        "# fichier, et une petite fenetre lancee DANS la session de l'agent la lit.",
        "# C'est la seule facon d'informer sans donner de droits a l'interface.",
        "$prog = Join-Path $dir 'apps-progress.json'",
        "function Etape($i, $tot, $nom, $etat) {",
        "  try {",
        "    # ToUnixTimeSeconds : insensible a la langue du poste, contrairement a",
        "    # « Get-Date -UFormat %s » qui rend une virgule decimale en francais.",
        "    [pscustomobject]@{ i = $i; total = $tot; nom = $nom; etat = $etat;",
        "                       ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() } |",
        "      ConvertTo-Json -Compress | Set-Content -Path $prog -Encoding UTF8",
        "  } catch { }",
        "}",
        "",
        "# Ce qui reste REELLEMENT a faire : une liste de cinq applications deja",
        "# installees ne doit pas afficher une barre qui va de 1 a 5 sans rien faire.",
        "# Ce qui reste a faire : ni deja installe, ni condamne apres trop d'echecs.",
        "#",
        "# Le plafond existe parce qu'un installeur invalide — une page web enregistree",
        "# comme .exe, cas vecu — echoue a l'identique a chaque fois. Sans lui, le poste",
        "# le retelecharge et le relance a chaque demarrage, indefiniment, et bloque du",
        "# meme coup les applications suivantes de la liste.",
        "function Echecs($m) { try { [int](Get-ItemProperty -Path $base -Name ($m + '_ko') -ErrorAction Stop).($m + '_ko') } catch { 0 } }",
        "$MAX_ECHECS = 3",
        "# Dix minutes par installeur. Large pour une grosse suite bureautique sur un",
        "# poste lent, court au regard d une ouverture de session bloquee.",
        "$DELAI_MAX = 600",
        "$aFaire = @($apps | Where-Object {",
        "  -not (Get-ItemProperty -Path $base -Name $_.marker -ErrorAction SilentlyContinue) -and",
        "  (Echecs $_.marker) -lt $MAX_ECHECS })",
        "$totFaire = $aFaire.Count",
        "$fait = 0",
        "if ($totFaire -gt 0) { Etape 0 $totFaire '' 'demarrage' }",
        "foreach ($a in $apps) {",
        "  if (Get-ItemProperty -Path $base -Name $a.marker -ErrorAction SilentlyContinue) {",
        "    Note ($a.marker + ' : deja installe, ignore'); continue }",
        "  $ko = Echecs $a.marker",
        "  if ($ko -ge $MAX_ECHECS) {",
        "    # Dit a chaque passage, jamais avale : sans cette ligne, une application",
        "    # abandonnee disparaitrait du journal et personne ne saurait qu elle manque.",
        "    Note ($a.marker + ' (' + $a.nom + ') : ABANDONNE apres ' + $ko + ' echecs — installeur a verifier dans le store')",
        "    continue }",
        "  $ext = if ($a.msi) { 'msi' } else { 'exe' }",
        "  $tmp = Join-Path $env:TEMP ('bastion_' + $a.marker + '.' + $ext)",
        "  try {",
        "    $fait++",
        "    Etape $fait $totFaire $a.nom 'telechargement'",
        "    Note ($a.marker + ' : telechargement ' + $a.url)",
        "    Invoke-WebRequest -Uri $a.url -OutFile $tmp -UseBasicParsing -TimeoutSec 600 -ErrorAction Stop",
        "    $mo = [int]((Get-Item $tmp).Length/1MB)",
        "    Etape $fait $totFaire $a.nom 'installation'",
        "    Note ($a.marker + ' : recu ' + $mo + ' Mo, installation en cours')",
        "    # On lance SANS attendre, puis on borne l attente nous-memes.",
        "    #",
        "    # « -Wait » attend indefiniment. Un installeur qui ne rend jamais la main",
        "    # gele alors toute la file : aucun marqueur n est ecrit, les logiciels",
        "    # suivants ne sont jamais poses, et la passe suivante repart de zero. Vecu",
        "    # le 2026-08-07 avec SumatraPDF — six logiciels installes sur neuf, et le",
        "    # journal qui s arrete sur « installation en cours ».",
        "    #",
        "    # Le plafond de tentatives ne rattrape pas ce cas : il compte les ECHECS,",
        "    # et un processus qui ne se termine pas n echoue jamais.",
        "    if ($a.msi) { $p = Start-Process msiexec.exe -ArgumentList ('/i \"' + $tmp + '\" ' + $a.args) -PassThru }",
        "    else        { $p = Start-Process $tmp -ArgumentList $a.args -PassThru }",
        "    if (-not $p.WaitForExit($DELAI_MAX * 1000)) {",
        "      # Depasse : on arrete l installeur et on compte un echec, pour que le",
        "      # plafond finisse par l ecarter au lieu de le retenter sans fin.",
        "      try { $p.Kill(); $p.WaitForExit(5000) } catch { }",
        "      Set-ItemProperty -Path $base -Name ($a.marker + '_ko') -Value ($ko + 1)",
        "      Note ($a.marker + ' (' + $a.nom + ') : ARRETE apres ' + $DELAI_MAX + ' s sans reponse (tentative ' + ($ko + 1) + '/' + $MAX_ECHECS + ') — les suivants continuent')",
        "      continue",
        "    }",
        "    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010) {",
        "      Set-ItemProperty -Path $base -Name $a.marker -Value 1",
        "      Remove-ItemProperty -Path $base -Name ($a.marker + '_ko') -ErrorAction SilentlyContinue",
        "      Note ($a.marker + ' : INSTALLE (code ' + $p.ExitCode + ')')",
        "    } else {",
        "      Set-ItemProperty -Path $base -Name ($a.marker + '_ko') -Value ($ko + 1)",
        "      Note ($a.marker + ' : ECHEC installation, code ' + $p.ExitCode + ' (tentative ' + ($ko + 1) + '/' + $MAX_ECHECS + ')')",
        "    }",
        "    Remove-Item $tmp -Force -ErrorAction SilentlyContinue",
        "  } catch {",
        "    Set-ItemProperty -Path $base -Name ($a.marker + '_ko') -Value ($ko + 1)",
        "    Note ($a.marker + ' : ECHEC — ' + $_.Exception.Message + ' (tentative ' + ($ko + 1) + '/' + $MAX_ECHECS + ')')",
        "  }",
        "}",
    ]

    # ── RETRAITS ─────────────────────────────────────────────────────────────
    # Une application décochée dans la console doit DISPARAÎTRE des postes, sinon
    # « désactivé » ne veut rien dire pour le parc : le script cessait simplement de
    # l'installer et elle restait en place partout où elle l'était déjà.
    #
    # Règle absolue : on ne désinstalle QUE si le marqueur posé par Bastion est présent.
    # Il prouve que c'est NOUS qui avons installé ce logiciel. Sans cette condition, une
    # application homonyme installée par le service informatique ou livrée avec l'image
    # du poste serait retirée sans que personne ne l'ait demandé.
    lines += [
        "",
        "$retraits = @(",
    ]
    for i, r in enumerate(retraits):
        marker = str(r.get('marker', '')).replace("'", "")
        nom = str(r.get('nom', '')).replace("'", "''")
        virgule = ',' if i < len(retraits) - 1 else ''
        lines.append("  @{ marker='%s'; nom='%s' }%s" % (marker, nom, virgule))
    lines += [
        ")",
        "foreach ($d in $retraits) {",
        "  if (-not (Get-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue)) { continue }",
        "  # Le marqueur existe : Bastion a installe ce logiciel, il peut le retirer.",
        "  $u = $null",
        "  foreach ($rc in @('HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*',",
        "                    'HKLM:\\Software\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*')) {",
        "    $u = Get-ItemProperty $rc -ErrorAction SilentlyContinue |",
        "         Where-Object { $_.DisplayName -and $_.DisplayName -like ('*' + $d.nom + '*') } |",
        "         Select-Object -First 1",
        "    if ($u) { break }",
        "  }",
        "  if (-not $u) {",
        "    # Absent des programmes installes : deja retire a la main. On efface le marqueur,",
        "    # sinon le poste rechercherait ce logiciel a chaque demarrage, indefiniment.",
        "    Remove-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue",
        "    Note ($d.marker + ' (' + $d.nom + ') : deja absent, marqueur efface'); continue",
        "  }",
        "  # « QuietUninstallString » est la commande SILENCIEUSE fournie par l'editeur. A",
        "  # defaut, seul MSI garantit un retrait sans interface : « /I{GUID} » devient",
        "  # « /X{GUID} », plus « /qn /norestart ». Pour un .exe sans commande silencieuse,",
        "  # on NE lance rien : une fenetre d'assistant surgissant au demarrage d'un poste",
        "  # bloquerait la session sans que personne ne comprenne pourquoi.",
        "  $cmd = $u.QuietUninstallString",
        "  if (-not $cmd -and $u.UninstallString -match 'msiexec') {",
        "    $cmd = ($u.UninstallString -replace '/I\\{', '/X{') + ' /qn /norestart'",
        "  }",
        "  if (-not $cmd) {",
        "    Note ($d.marker + ' (' + $d.nom + ') : AUCUNE desinstallation silencieuse fournie par l editeur — a retirer a la main')",
        "    continue",
        "  }",
        "  try {",
        "    Note ($d.marker + ' (' + $d.nom + ') : desinstallation en cours')",
        "    $p = Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $cmd -Wait -PassThru -WindowStyle Hidden",
        "    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010 -or $p.ExitCode -eq 1605) {",
        "      # 1605 = « ce produit n'est pas installe » : le resultat voulu est atteint.",
        "      Remove-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue",
        "      Note ($d.marker + ' : DESINSTALLE (code ' + $p.ExitCode + ')')",
        "    } else {",
        "      # Le marqueur est CONSERVE : la prochaine execution reessaiera. L'effacer",
        "      # ferait oublier au poste qu'il reste un logiciel a retirer.",
        "      Note ($d.marker + ' : ECHEC desinstallation, code ' + $p.ExitCode)",
        "    }",
        "  } catch {",
        "    Note ($d.marker + ' : ECHEC — ' + $_.Exception.Message)",
        "  }",
        "}",
        "if ($totFaire -gt 0) { Etape $totFaire $totFaire '' 'termine' }",
        "",
        "# ── LA FENETRE VUE PAR L'AGENT, ET LE REJEU A L'OUVERTURE DE SESSION ──",
        "# Deux taches, deux contextes, et c'est la seule combinaison qui marche :",
        "#   - une tache SYSTEM rejoue CE script a chaque ouverture de session, sinon une",
        "#     application deployee aujourd'hui n'arriverait qu'au prochain redemarrage ;",
        "#   - une tache dans la session de l'AGENT affiche la fenetre. Un processus",
        "#     SYSTEM ne peut rien afficher a un utilisateur (isolation de la session 0),",
        "#     et l'agent, lui, n'a pas les droits d'installer. Chacun son role.",
        "$vue = Join-Path $dir 'apps-fenetre.ps1'",
        "",
        "# La fenetre : WinForms, sans dependance, et surtout SANS DROITS. Elle ne fait",
        "# que LIRE le fichier de progression — elle n'installe rien et ne peut rien casser.",
        "$src = @'",
        "Add-Type -AssemblyName System.Windows.Forms, System.Drawing",
        "$p = 'C:\\ProgramData\\Bastion\\apps-progress.json'",
        "# Rien a montrer : on sort sans rien afficher. Une fenetre qui apparait pour dire",
        "# qu'il n'y a rien a dire est une nuisance, pas une information.",
        "function Lire { try { Get-Content $p -Raw -ErrorAction Stop | ConvertFrom-Json } catch { $null } }",
        "$d = Lire",
        "if (-not $d) { exit }",
        "$age = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - [int]$d.ts",
        "if ($d.etat -eq 'termine' -and $age -gt 120) { exit }",
        "if ($age -gt 1800) { exit }",
        "",
        "$f = New-Object Windows.Forms.Form",
        "$f.Text = 'Bastion - installation des logiciels du service'",
        "$f.Size = New-Object Drawing.Size(460, 165)",
        "$f.StartPosition = 'CenterScreen'",
        "$f.FormBorderStyle = 'FixedDialog'",
        "$f.MaximizeBox = $false; $f.MinimizeBox = $false; $f.TopMost = $true",
        "$f.BackColor = [Drawing.Color]::FromArgb(17, 28, 49)",
        "$f.ForeColor = [Drawing.Color]::FromArgb(226, 232, 240)",
        "",
        "$t = New-Object Windows.Forms.Label",
        "$t.Location = New-Object Drawing.Point(18, 18)",
        "$t.Size = New-Object Drawing.Size(410, 22)",
        "$t.Font = New-Object Drawing.Font('Segoe UI', 10, [Drawing.FontStyle]::Bold)",
        "$t.Text = 'Installation des logiciels du service'",
        "$f.Controls.Add($t)",
        "",
        "$s = New-Object Windows.Forms.Label",
        "$s.Location = New-Object Drawing.Point(18, 44)",
        "$s.Size = New-Object Drawing.Size(410, 20)",
        "$s.ForeColor = [Drawing.Color]::FromArgb(148, 163, 184)",
        "$f.Controls.Add($s)",
        "",
        "$b = New-Object Windows.Forms.ProgressBar",
        "$b.Location = New-Object Drawing.Point(18, 70)",
        "$b.Size = New-Object Drawing.Size(410, 20)",
        "$b.Minimum = 0; $b.Maximum = 100",
        "$f.Controls.Add($b)",
        "",
        "$n = New-Object Windows.Forms.Label",
        "$n.Location = New-Object Drawing.Point(18, 96)",
        "$n.Size = New-Object Drawing.Size(410, 20)",
        "$n.ForeColor = [Drawing.Color]::FromArgb(148, 163, 184)",
        "$n.Font = New-Object Drawing.Font('Segoe UI', 8)",
        "$n.Text = 'Vous pouvez continuer a travailler.'",
        "$f.Controls.Add($n)",
        "",
        "$tm = New-Object Windows.Forms.Timer",
        "$tm.Interval = 1000",
        "$tm.Add_Tick({",
        "  $d = Lire",
        "  if (-not $d) { $f.Close(); return }",
        "  $tot = [int]$d.total; if ($tot -lt 1) { $tot = 1 }",
        "  $i = [int]$d.i; if ($i -gt $tot) { $i = $tot }",
        "  $b.Value = [int](100 * $i / $tot)",
        "  if ($d.etat -eq 'termine') {",
        "    $b.Value = 100",
        "    $s.Text = 'Termine. Les logiciels sont installes.'",
        "    $n.Text = 'Cette fenetre se ferme toute seule.'",
        "    $tm.Stop()",
        "    $ferme = New-Object Windows.Forms.Timer",
        "    $ferme.Interval = 4000",
        "    $ferme.Add_Tick({ $ferme.Stop(); $f.Close() })",
        "    $ferme.Start()",
        "    return",
        "  }",
        "  $verbe = if ($d.etat -eq 'telechargement') { 'Telechargement' } else { 'Installation' }",
        "  $s.Text = ('{0} de {1}  ({2} sur {3})' -f $verbe, $d.nom, $i, $tot)",
        "})",
        "$tm.Start()",
        "[void]$f.ShowDialog()",
        "'@",
        "try { Set-Content -Path $vue -Value $src -Encoding UTF8 -ErrorAction Stop } catch { }",
        "",
        "try {",
        "  # L'installation reste au DEMARRAGE, et seulement la.",
        "  #",
        "  # Une tache rejouant ce script a CHAQUE ouverture de session a ete essayee le",
        "  # 2026-08-06 : elle rendait les sessions tres lentes. Le script retente en effet",
        "  # toutes les applications dont le marqueur manque — donc, si une seule echoue,",
        "  # il retelecharge et relance tout le reste a chaque connexion, pendant que",
        "  # l'agent essaie de travailler. Le gain (une application deployee le jour meme)",
        "  # ne valait pas ce prix. On revient au demarrage seul.",
        "  # 2. L'AFFICHAGE, dans la session de l'agent. « Groupe Utilisateurs » : la tache",
        "  #    se declenche pour celui qui ouvre la session, quel qu'il soit.",
        "  $a2 = New-ScheduledTaskAction -Execute 'powershell.exe' `",
        "        -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"' + $vue + '\"')",
        "  $t2 = New-ScheduledTaskTrigger -AtLogOn",
        "  # Dix secondes : le temps que la tache SYSTEM ait ecrit son premier etat, sinon",
        "  # la fenetre lirait un fichier perime et se fermerait aussitot.",
        "  $t2.Delay = 'PT10S'",
        "  $p2 = New-ScheduledTaskPrincipal -GroupId 'S-1-5-32-545' -RunLevel Limited",
        "  $s2 = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 30)",
        "  Register-ScheduledTask -TaskName 'Bastion - avancement des installations' -Action $a2 -Trigger $t2 `",
        "        -Principal $p2 -Settings $s2 -Force -ErrorAction Stop | Out-Null",
        "  Note 'Taches d ouverture de session enregistrees (installation + affichage).'",
        "} catch {",
        "  # Dit, jamais avale : sans ces taches on retombe sur l'ancien comportement",
        "  # — installation au seul demarrage, et aucune information pour l'agent.",
        "  Note ('ECHEC enregistrement des taches : ' + $_.Exception.Message)",
        "}",
        "Note '--- Fin ---'",
    ]
    return "\r\n".join(lines) + "\r\n"

def main():
    guid = sys.argv[1]
    cfg = json.load(open(sys.argv[2], 'rb'))
    apps = cfg.get('apps', [])
    # Applications décochées : à retirer des postes où Bastion les avait installées.
    retraits = cfg.get('retraits', [])

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    # Arborescence des scripts de démarrage.
    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    if os.path.exists(ref):
        copy_ntacl(ref, os.path.join(sysvol, 'Machine'))
        copy_ntacl(ref, os.path.join(sysvol, 'Machine', 'Scripts'))
        copy_ntacl(ref, startup)

    # 1) PowerShell d'installation. Écrit par psfile : marque d'ordre d'octets UTF-8 +
    #    caractères sûrs. Sans cela, un simple tiret cadratin dans un message rendait
    #    le fichier inanalysable par PowerShell 5.1 (voir services/scripts/psfile.py).
    p_ps1 = psfile.ecrire_ps1(os.path.join(startup, 'bastion-apps.ps1'), ps1(apps, retraits))
    # 2) Lanceur .cmd (contourne la politique d'exécution PowerShell) : ASCII, sans marque.
    p_cmd = psfile.ecrire_cmd(os.path.join(startup, 'bastion-apps.cmd'),
                              # « start /b » : on lance SANS attendre.
                              #
                              # Windows exécute les scripts de démarrage de façon SYNCHRONE et les
                              # ATTEND — à l'ouverture de session comme à chaque « gpupdate /force ».
                              # Or ce script télécharge et installe jusqu'à neuf logiciels, avec dix
                              # minutes de patience par installeur. Il gelait donc gpupdate, qui ne
                              # se terminait jamais.
                              #
                              # L'installation n'a aucune raison de bloquer qui que ce soit : elle se
                              # signale par sa fenêtre de progression et par son journal. On la
                              # détache, et le démarrage rend la main tout de suite.
                              '@echo off\r\nstart "Bastion" /b powershell -NoProfile '
                              '-ExecutionPolicy Bypass -WindowStyle Hidden '
                              '-File "%~dp0bastion-apps.ps1"\r\n')
    # 3) scripts.ini (UTF-16LE + BOM, comme GPMC) référencant le .cmd.
    p_ini = os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini')
    ini = "\r\n[Startup]\r\n0CmdLine=bastion-apps.cmd\r\n0Parameters=\r\n"
    with open(p_ini, 'wb') as w:
        w.write(b'\xff\xfe' + ini.encode('utf-16-le'))
    for f in (p_ps1, p_cmd, p_ini):
        os.chmod(f, 0o644)
        if os.path.exists(ref):
            copy_ntacl(ref, f)

    # ── Version + CSE Scripts via le module Samba ──
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    # versionNumber : mot BAS = version ORDINATEUR (incrémentée), mot HAUT = version utilisateur.
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)

    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    if os.path.exists(ref):
        pass  # ref réécrit ci-dessus ; l'ACL a déjà été copiée initialement

    m = ldb.Message()
    m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                       ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d apps=%d' % (newver, len(apps)))

if __name__ == '__main__':
    main()
