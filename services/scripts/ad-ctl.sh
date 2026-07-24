#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — pilotage contrôlé de l'Active Directory (samba-tool + partages).
# Appelé par la console admin via sudo. Liste blanche stricte de catégories/actions.
set -eu
ST=/usr/bin/samba-tool

cat="${1:-}"
sub="${2:-}"
a="${3:-}"
b="${4:-}"
c="${5:-}"

case "$cat" in
  user)
    case "$sub" in
      list)        exec "$ST" user list ;;
      show)        exec "$ST" user show "$a" ;;
      create)      exec "$ST" user create "$a" "$b" ;;
      delete)      exec "$ST" user delete "$a" ;;
      setpassword) exec "$ST" user setpassword "$a" --newpassword="$b" ;;
      enable)      exec "$ST" user enable "$a" ;;
      disable)     exec "$ST" user disable "$a" ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  computer)
    case "$sub" in
      list)   exec "$ST" computer list ;;
      delete) exec "$ST" computer delete "$a" ;;
      detail)
        # Inventaire : pour chaque poste du domaine, « nom TAB systeme TAB derniere_ouverture ».
        # Lecture en un seul appel via SamDB (rapide) ; lastLogonTimestamp est un FILETIME Windows
        # (100 ns depuis 1601) converti en horodatage Unix.
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        python3 - "$dn" <<'PY' 2>/dev/null
import sys, ldb
from samba.samdb import SamDB
from samba.auth import system_session
from samba.param import LoadParm
lp = LoadParm(); lp.load_default()
db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
res = db.search(base=sys.argv[1], expression='(objectClass=computer)',
                attrs=['sAMAccountName', 'operatingSystem', 'lastLogonTimestamp'])
def val(e, k):
    return str(e[k][0]) if (k in e and len(e[k])) else ''
for e in res:
    name = val(e, 'sAMAccountName').rstrip('$')
    if not name:
        continue
    osname = val(e, 'operatingSystem')
    ll = ''
    v = val(e, 'lastLogonTimestamp')
    if v.isdigit() and int(v) > 0:
        ll = str(int(int(v) / 10000000 - 11644473600))
    print(name + '\t' + osname + '\t' + ll)
PY
        ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  group)
    case "$sub" in
      list)          exec "$ST" group list ;;
      add)           exec "$ST" group add "$a" ;;
      delete)        exec "$ST" group delete "$a" ;;
      addmembers)    exec "$ST" group addmembers "$a" "$b" ;;
      removemembers) exec "$ST" group removemembers "$a" "$b" ;;
      listmembers)   exec "$ST" group listmembers "$a" ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  ou)
    case "$sub" in
      list)   exec "$ST" ou list ;;
      create) exec "$ST" ou create "$a" ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  gpo)
    # La création de GPO nécessite des droits Administrateur du domaine.
    ADPASS=$(sed -n 's/^AD_ADMIN_PASS="\{0,1\}\([^"]*\)"\{0,1\}/\1/p' /etc/proxyfibre/ad.env 2>/dev/null || true)
    # Jauge d'installation (facultative). Si un nonce est passé en 5e argument, l'avancement
    # est écrit dans /run/proxyfibre/gpo-<nonce>.progress (« pourcentage TAB libellé »), lu par
    # la console pour animer une barre de progression. PUREMENT COSMÉTIQUE : toutes les écritures
    # sont « best effort » (|| true) et n'altèrent JAMAIS le déploiement. pct < 0 = échec.
    PROG=""
    prog(){ [ -n "$PROG" ] || return 0; printf '%s\t%s\n' "$1" "$2" > "$PROG" 2>/dev/null || true; }
    if printf '%s' "$c" | grep -qE '^[A-Za-z0-9]+$'; then
      # /dev/shm : tmpfs 1777 déjà utilisé pour le cache AD → lisible par www-data (la console).
      find /dev/shm -maxdepth 1 -name 'pf-gpo-*.progress' -mmin +30 -delete 2>/dev/null || true
      PROG="/dev/shm/pf-gpo-$c.progress"
      prog 3 "Préparation…"; chmod 644 "$PROG" 2>/dev/null || true
    fi
    case "$sub" in
      list)   exec "$ST" gpo listall ;;
      health) exec python3 /usr/local/sbin/proxyfibre-gpo-health ;;   # diagnostic lecture seule (JSON)
      create) exec "$ST" gpo create "$a" -U "Administrator%${ADPASS}" ;;
      deploy)
        # Déploiement catalogue : (ré)écrit les stratégies registre du JSON $b dans la GPO
        # nommée $a, puis lie la GPO à la racine du domaine.
        # IDEMPOTENT PAR NOM : si une GPO du même nom d'affichage existe déjà, on RÉUTILISE
        # son GUID au lieu d'en créer une seconde. Sans cela, re-déployer une GPO du catalogue
        # (ex. corriger la stratégie de temps) laissait un DOUBLON : l'ancienne version,
        # incomplète et toujours liée, continuait de s'appliquer à côté de la nouvelle.
        prog 8 "Connexion au domaine…"
        [ -f "$b" ] || { prog -1 "Fichier de stratégie manquant"; echo "ERROR: json absent" >&2; exit 2; }
        prog 20 "Création de la stratégie…"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$a" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$a" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { prog -1 "Création de la GPO échouée"; echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        # Écriture directe du Registry.pol sur le SYSVOL local (l'écriture SMB de
        # `gpo load` est refusée ; `gpo create` a déjà posé l'arborescence en local).
        prog 40 "Écriture des paramètres…"
        python3 /usr/local/sbin/proxyfibre-gpo-apply "$guid" "$b" >/dev/null 2>&1 \
          || { prog -1 "Écriture des paramètres échouée"; echo "ERROR: application des strategies echouee ($guid)" >&2; exit 1; }
        rm -f "$b" 2>/dev/null || true
        prog 55 "Liaison au domaine…"
        realm=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$realm" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        # Lien au domaine : posé seulement s'il n'existe pas déjà (re-déploiement).
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 \
          || echo "ATTENTION: GPO prete mais lien au domaine echoue"
        prog 65 "Réparation des permissions SYSVOL…"
        echo "$guid deployee et liee au domaine"
        ;;
      appstore)
        # Store d'applications : GPO « Bastion — Applications » avec script de
        # démarrage qui installe les apps depuis la passerelle. $a = fichier JSON.
        [ -f "$a" ] || { echo "ERROR: json absent" >&2; exit 2; }
        name="Bastion — Applications"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-apps "$guid" "$a" >/dev/null 2>&1 \
          || { echo "ERROR: generation du script echouee ($guid)" >&2; exit 1; }
        realm=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$realm" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid applications deployees"
        ;;
      activation)
        # Activation KMS automatique : GPO script démarrage + enregistrements DNS
        # d'auto-découverte du serveur KMS. $a = IP de la passerelle (serveur KMS).
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        name="Bastion — Activation Windows/Office"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-kms "$guid" "$a" >/dev/null 2>&1 \
          || { echo "ERROR: generation script activation echouee ($guid)" >&2; exit 1; }
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        # DNS : A kms + SRV _vlmcs._tcp → auto-découverte du KMS par les postes.
        # (le serveur DNS RPC de samba écoute sur l'IP du DC .2, pas 127.0.0.1)
        "$ST" dns add 192.168.182.2 "$rl" kms A "$a" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        "$ST" dns add 192.168.182.2 "$rl" _vlmcs._tcp SRV "kms.${rl} 1688 0 0" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid activation deployee"
        ;;
      timesync)
        # GPO « Bastion — Recaler l'heure au démarrage » : script de démarrage (SYSTEM) qui force
        # w32tm /resync a chaque boot (postes/VM dont l'horloge se decale et ne se resynchronise pas).
        name="Bastion — Recaler l'heure au démarrage"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-timesync "$guid" "192.168.182.1" >/dev/null 2>&1 \
          || { echo "ERROR: generation script heure echouee ($guid)" >&2; exit 1; }
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid recalage heure deployee"
        ;;
      drives)
        # Lecteurs réseau : GPO « Bastion — Lecteurs réseau » (GPP Drive Maps). $a = JSON.
        [ -f "$a" ] || { echo "ERROR: json absent" >&2; exit 2; }
        name="Bastion — Lecteurs réseau"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-drives "$guid" "$a" >/dev/null 2>&1 \
          || { echo "ERROR: generation Drives.xml echouee ($guid)" >&2; exit 1; }
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid lecteurs deployes"
        ;;
      wallpaper)
        # Fond d'écran imposé : GPO « Bastion — Fond d'écran ». $a = image, $b = style.
        # L'image est hébergée dans le SYSVOL de la GPO (lisible par tous les postes) et
        # référencée par une valeur de registre Wallpaper (stratégie « Bureau »).
        [ -f "$a" ] || { echo "ERROR: image absente" >&2; exit 2; }
        style=$(printf '%s' "$b" | tr -cd '0-9'); [ -n "$style" ] || style=10
        tile=0; [ "$b" = "tile" ] && { style=0; tile=1; }
        name="Bastion — Fond d'écran"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        pol="/var/lib/samba/sysvol/$rl/Policies/$guid"
        [ -d "$pol" ] || { echo "ERROR: SYSVOL GPO introuvable" >&2; exit 1; }
        # La stratégie « Fond d'écran » de Windows ne rend fiablement que le JPG (le PNG
        # donne souvent un écran noir) → on convertit SYSTÉMATIQUEMENT l'image en JPG.
        ext=jpg
        udir="$pol/User"
        mkdir -p "$udir"
        rm -f "$udir"/bastion-wallpaper.* 2>/dev/null || true
        img="$udir/bastion-wallpaper.jpg"
        if command -v convert >/dev/null 2>&1 && convert "${a}[0]" -background white -flatten -quality 90 "$img" 2>/dev/null && [ -s "$img" ]; then
          :   # converti en JPG
        else
          cp "$a" "$img"   # repli : copie brute si ImageMagick indisponible
        fi
        chmod 644 "$img"
        # ACL NT : recopier depuis GPT.INI (posée par gpo create) vers le dossier et l'image.
        ref="$pol/GPT.INI"
        if [ -f "$ref" ]; then
          v=$(getfattr --absolute-names -n security.NTACL -e hex "$ref" 2>/dev/null | sed -n 's/^security.NTACL=//p')
          if [ -n "$v" ]; then
            setfattr -n security.NTACL -v "$v" "$udir" 2>/dev/null || true
            setfattr -n security.NTACL -v "$v" "$img"  2>/dev/null || true
          fi
        fi
        # Chemin UNC de l'image dans SYSVOL (répliqué, lisible par les utilisateurs authentifiés).
        unc="\\\\${rl}\\SysVol\\${rl}\\Policies\\${guid}\\User\\bastion-wallpaper.${ext}"
        tmpj=$(mktemp)
        python3 - "$unc" "$style" "$tile" > "$tmpj" <<'PY'
import json, sys
unc, style, tile = sys.argv[1], sys.argv[2], sys.argv[3]
k = "Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\System"
print(json.dumps([
    {"keyname": k, "valuename": "Wallpaper",      "class": "USER", "type": "REG_SZ", "data": unc},
    {"keyname": k, "valuename": "WallpaperStyle", "class": "USER", "type": "REG_SZ", "data": style},
    {"keyname": k, "valuename": "TileWallpaper",  "class": "USER", "type": "REG_SZ", "data": tile},
]))
PY
        python3 /usr/local/sbin/proxyfibre-gpo-apply "$guid" "$tmpj" >/dev/null 2>&1; rc=$?
        rm -f "$tmpj"
        [ "$rc" -eq 0 ] || { echo "ERROR: application du fond d'ecran echouee ($guid)" >&2; exit 1; }
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid fond d'ecran deploye"
        ;;
      sysvolreset)
        # Réparation manuelle des permissions NT de SYSVOL (bouton console).
        "$ST" ntacl sysvolreset >/dev/null 2>&1 \
          && echo "permissions SYSVOL reparees" \
          || { echo "ERROR: reparation SYSVOL echouee" >&2; exit 1; }
        ;;
      link|unlink)
        # DÉSACTIVE (unlink) / RÉACTIVE (link) une GPO en la déliant/reliant à la racine du
        # domaine. Désactiver ≠ supprimer : la GPO reste, elle cesse simplement de s'appliquer.
        # $a = GUID. Les deux GPO par défaut de Windows sont protégées.
        [ -n "$a" ] || { echo "ERROR: GUID requis" >&2; exit 2; }
        case "$(printf '%s' "$a" | tr 'a-f' 'A-F')" in
          "{31B2F340-016D-11D2-945F-00C04FB984F9}"|"{6AC1786C-016F-11D2-945F-00C04FB984F9}")
            echo "ERROR: GPO systeme Windows protegee" >&2; exit 3 ;;
        esac
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        if [ "$sub" = "unlink" ]; then
          "$ST" gpo dellink "$dn" "$a" -U "Administrator%${ADPASS}" >/dev/null 2>&1 \
            && echo "gpo $a desactivee (deliee du domaine)" || { echo "ERROR: desactivation echouee" >&2; exit 1; }
        else
          "$ST" gpo setlink "$dn" "$a" -U "Administrator%${ADPASS}" >/dev/null 2>&1 \
            && echo "gpo $a reactivee (reliee au domaine)" || { echo "ERROR: reactivation echouee" >&2; exit 1; }
        fi
        ;;
      delete)
        # SUPPRIME définitivement une GPO (objet LDAP + arborescence SYSVOL). $a = GUID.
        # Irréversible. Les deux GPO par défaut de Windows sont protégées.
        [ -n "$a" ] || { echo "ERROR: GUID requis" >&2; exit 2; }
        case "$(printf '%s' "$a" | tr 'a-f' 'A-F')" in
          "{31B2F340-016D-11D2-945F-00C04FB984F9}"|"{6AC1786C-016F-11D2-945F-00C04FB984F9}")
            echo "ERROR: GPO systeme Windows protegee" >&2; exit 3 ;;
        esac
        "$ST" gpo del "$a" -U "Administrator%${ADPASS}" >/dev/null 2>&1 \
          && echo "gpo $a supprimee" || { echo "ERROR: suppression echouee" >&2; exit 1; }
        ;;
      domainlinks)
        # Liste (un GUID par ligne, en MAJUSCULES) les GPO LIÉES QUELQUE PART dans le domaine :
        # racine ET unités d'organisation (ex. la « Default Domain Controllers Policy » est liée
        # à l'OU « Domain Controllers », pas à la racine). On parcourt donc TOUT le sous-arbre à
        # la recherche des objets porteurs d'un attribut gPLink. Sinon une GPO parfaitement active
        # mais liée à une OU serait affichée « désactivée ». Lecture via le module Python de Samba
        # (« ldbsearch » n'est pas toujours installé — paquet ldb-tools absent).
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        python3 - "$dn" <<'PY' 2>/dev/null
import sys, re, ldb
from samba.samdb import SamDB
from samba.auth import system_session
from samba.param import LoadParm
lp = LoadParm(); lp.load_default()
db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
res = db.search(base=sys.argv[1], scope=ldb.SCOPE_SUBTREE, expression='(gPLink=*)', attrs=['gPLink'])
seen = set()
for e in res:
    if 'gPLink' not in e:
        continue
    raw = e['gPLink'][0]
    val = raw.decode('utf-8', 'replace') if isinstance(raw, (bytes, bytearray)) else str(raw)
    for m in re.findall(r'\{[0-9A-Fa-f-]+\}', val):
        u = m.upper()
        if u not in seen:
            seen.add(u); print(u)
PY
        ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac
    # ── Réparation systématique des permissions SYSVOL après toute écriture de GPO ──
    # Les scripts Python écrivent Registry.pol / Drives.xml / images directement dans le
    # SYSVOL et recopient l'ACL NT « au mieux ». Quand cette recopie échoue, les postes ne
    # peuvent plus LIRE le gpt.ini de la GPO (« Windows a tenté en vain de lire… »), et plus
    # aucune stratégie ne s'applique. « ntacl sysvolreset » réaligne toutes les ACL sur
    # l'AD : on le lance après chaque modification, plutôt que d'espérer que la recopie a
    # tenu. Ignoré pour les sous-actions en lecture seule et le reset manuel (déjà fait).
    case "$sub" in
        list|create|sysvolreset|domainlinks) : ;;
        *) "$ST" ntacl sysvolreset >/dev/null 2>&1 || true ;;
    esac
    prog 100 "Terminé"
    ;;
  bitlocker)
    ADPASS=$(sed -n 's/^AD_ADMIN_PASS="\{0,1\}\([^"]*\)"\{0,1\}/\1/p' /etc/proxyfibre/ad.env 2>/dev/null || true)
    case "$sub" in
      deploy)
        # GPO « Bastion — Chiffrement BitLocker » : séquestre AD + script de démarrage.
        # $a = mode (tpm|tpmpin) ; $b = PIN de service commun optionnel (6-20 chiffres).
        name="Bastion — Chiffrement BitLocker"
        case "$a" in
          ''|tpm) modearg="tpm" ;;
          tpmpin)
            if [ -n "$b" ]; then
              case "$b" in *[!0-9]*) echo "ERROR: PIN non numerique" >&2; exit 2 ;; esac
              { [ "${#b}" -ge 6 ] && [ "${#b}" -le 20 ]; } || { echo "ERROR: PIN de 6 a 20 chiffres" >&2; exit 2; }
              modearg="tpmpin:$b"
            else
              modearg="tpmpin"
            fi ;;
          *) echo "ERROR: mode BitLocker inconnu (tpm|tpmpin)" >&2; exit 2 ;;
        esac
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-bitlocker "$guid" "$modearg" >/dev/null 2>&1 \
          || { echo "ERROR: generation GPO BitLocker echouee ($guid)" >&2; exit 1; }
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        "$ST" ntacl sysvolreset >/dev/null 2>&1 || true
        echo "$guid bitlocker deployee ($modearg)"
        ;;
      keys)
        # Clés de récupération BitLocker séquestrées dans l'AD : « poste TAB mot-de-passe TAB date ».
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        python3 - "$dn" <<'PY' 2>/dev/null
import sys, ldb
from samba.samdb import SamDB
from samba.auth import system_session
from samba.param import LoadParm
lp = LoadParm(); lp.load_default()
db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
res = db.search(base=sys.argv[1], expression='(objectClass=msFVE-RecoveryInformation)',
                attrs=['msFVE-RecoveryPassword', 'whenCreated'])
for e in res:
    pw = str(e['msFVE-RecoveryPassword'][0]) if 'msFVE-RecoveryPassword' in e else ''
    when = (str(e['whenCreated'][0])[:8] if 'whenCreated' in e else '')
    parts = str(e.dn).split(',')
    comp = parts[1][3:] if (len(parts) > 1 and parts[1][:3].upper() == 'CN=') else ''
    if pw:
        print((comp or '?') + '\t' + pw + '\t' + when)
PY
        ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  share)
    SHFILE=/etc/samba/shares.conf
    # Chemin (« path = … ») d'une section de partage, ou vide si la section n'existe pas.
    share_path() {
      awk -v s="[$1]" '
        $0==s {insec=1; next}
        insec && /^[[:space:]]*\[/ {insec=0}
        insec && /^[[:space:]]*path[[:space:]]*=/ { sub(/^[^=]*=[[:space:]]*/,""); print; exit }
      ' "$SHFILE" 2>/dev/null
    }
    # Les partages qui servent le déploiement PXE (source Windows, images master) pointent vers
    # /srv/pxe et sont gérés par l'installation PXE : les modifier ou les retirer depuis cette
    # console casserait le déploiement réseau. On les protège.
    share_protege() { case "$(share_path "$1")" in /srv/pxe*) return 0 ;; *) return 1 ;; esac; }
    case "$sub" in
      list) cat "$SHFILE" 2>/dev/null || true ;;
      create)
        name=$(printf '%s' "$a" | tr -cd 'A-Za-z0-9_-')
        [ -n "$name" ] || { echo "nom invalide" >&2; exit 2; }
        mkdir -p "/srv/partage/$name"
        chmod 2770 "/srv/partage/$name" 2>/dev/null || true
        if ! grep -q "^\[$name\]" "$SHFILE" 2>/dev/null; then
          printf '\n[%s]\n   path = /srv/partage/%s\n   read only = no\n   browseable = yes\n   dfree command = /usr/local/sbin/proxyfibre-share-dfree\n' \
            "$name" "$name" >> "$SHFILE"
          smbcontrol all reload-config >/dev/null 2>&1 || true
        fi
        echo "partage $name cree" ;;
      delete)
        # SUPPRIME UNIQUEMENT LA DÉFINITION DU PARTAGE — jamais le dossier de données (le retrait
        # d'un partage ne doit pas détruire les fichiers des agents : c'est réversible en le recréant).
        name=$(printf '%s' "$a" | tr -cd 'A-Za-z0-9_-')
        [ -n "$name" ] || { echo "nom invalide" >&2; exit 2; }
        grep -q "^\[$name\]" "$SHFILE" 2>/dev/null || { echo "ERROR: partage inconnu" >&2; exit 1; }
        share_protege "$name" && { echo "ERROR: partage systeme (PXE) protege" >&2; exit 3; }
        # Retire la section [name] et ses lignes, jusqu'à la section suivante (ou la fin du fichier).
        awk -v s="[$name]" '
          $0==s {skip=1; next}
          skip && /^[[:space:]]*\[/ {skip=0}
          !skip {print}
        ' "$SHFILE" > "$SHFILE.tmp" && mv "$SHFILE.tmp" "$SHFILE"
        smbcontrol all reload-config >/dev/null 2>&1 || true
        echo "partage $name retire (dossier de donnees conserve)" ;;
      set)
        # Modifie les drapeaux d'un partage : $a=nom  $b=lecture-seule(0|1)  $c=visible(0|1).
        name=$(printf '%s' "$a" | tr -cd 'A-Za-z0-9_-')
        ro=$(printf '%s'  "$b" | tr -cd '01'); br=$(printf '%s' "$c" | tr -cd '01')
        [ -n "$name" ] || { echo "nom invalide" >&2; exit 2; }
        grep -q "^\[$name\]" "$SHFILE" 2>/dev/null || { echo "ERROR: partage inconnu" >&2; exit 1; }
        share_protege "$name" && { echo "ERROR: partage systeme (PXE) protege" >&2; exit 3; }
        roval=no;  [ "$ro" = "1" ] && roval=yes
        brval=yes; [ "$br" = "0" ] && brval=no
        # Réécrit « read only » et « browseable » DANS la bonne section ; ajoute les directives
        # si elles manquaient. Les autres lignes (path, comment, guest ok…) sont préservées.
        awk -v s="[$name]" -v ro="$roval" -v br="$brval" '
          function flush(){ if(insec){ if(!seen_ro) print "   read only = " ro; if(!seen_br) print "   browseable = " br } }
          $0==s { print; insec=1; seen_ro=0; seen_br=0; next }
          insec && /^[[:space:]]*\[/ { flush(); insec=0 }
          insec && /^[[:space:]]*read only[[:space:]]*=/  { print "   read only = " ro;  seen_ro=1; next }
          insec && /^[[:space:]]*browseable[[:space:]]*=/ { print "   browseable = " br; seen_br=1; next }
          { print }
          END { flush() }
        ' "$SHFILE" > "$SHFILE.tmp" && mv "$SHFILE.tmp" "$SHFILE"
        smbcontrol all reload-config >/dev/null 2>&1 || true
        echo "partage $name mis a jour" ;;
      *) echo "sous-action refusee" >&2; exit 2 ;;
    esac ;;
  status)
    printf 'dc=%s\n' "$(systemctl is-active samba-ad-dc 2>/dev/null)"
    "$ST" domain info 127.0.0.1 2>/dev/null || true ;;
  domaininfo)
    _rl="$(testparm -s --parameter-name=realm 2>/dev/null || true)"
    _nb="$(testparm -s --parameter-name='netbios name' 2>/dev/null || true)"
    printf 'realm=%s\n'     "$_rl"
    printf 'workgroup=%s\n' "$(testparm -s --parameter-name=workgroup 2>/dev/null || true)"
    printf 'netbios=%s\n'   "$_nb"
    # FQDN du DC : les partages ordinaires s'atteignent par le NOM DE SERVEUR, pas le nom de domaine.
    printf 'dcfqdn=%s\n'    "$(printf '%s.%s' "$_nb" "$_rl" | tr 'A-Z' 'a-z')" ;;
  authlog)
    # Authentifications réussies : poste (workstation) TAB utilisateur TAB IP TAB horodatage.
    f=/var/log/samba/auth_audit.log
    [ -f "$f" ] || exit 0
    tail -n 4000 "$f" 2>/dev/null | jq -rR 'fromjson? | select(.type=="Authentication" and .Authentication.status=="NT_STATUS_OK") | [(.Authentication.workstation // ""), (.Authentication.clientAccount // ""), (.Authentication.remoteAddress // ""), .timestamp] | @tsv' 2>/dev/null ;;
  reprovision)
    # (RE)crée le domaine avec le nom configuré (DESTRUCTIF). Détaché (opération longue).
    [ -x /usr/local/sbin/proxyfibre-setup-ad ] || { echo "installeur absent" >&2; exit 2; }
    setsid sh -c '/usr/local/sbin/proxyfibre-setup-ad > /var/log/proxyfibre-ad-provision.log 2>&1' >/dev/null 2>&1 &
    echo "provisioning lance" ;;
  warm)
    # Rafraîchit les 6 listes EN PARALLÈLE dans le cache (accélère la page admin).
    D=/dev/shm
    "$ST" user list      > "$D/pf-ad-users.cache"     2>/dev/null &
    "$ST" computer list  > "$D/pf-ad-computers.cache" 2>/dev/null &
    "$ST" group list     > "$D/pf-ad-groups.cache"    2>/dev/null &
    "$ST" ou list        > "$D/pf-ad-ous.cache"       2>/dev/null &
    "$ST" gpo listall    > "$D/pf-ad-gpos.cache"      2>/dev/null &
    cat /etc/samba/shares.conf > "$D/pf-ad-shares.cache" 2>/dev/null &
    wait
    chmod 644 "$D"/pf-ad-*.cache 2>/dev/null || true
    echo "warmed" ;;
  *) echo "categorie refusee" >&2; exit 2 ;;
esac
