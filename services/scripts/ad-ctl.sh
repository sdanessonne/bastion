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
d="${6:-}"

case "$cat" in
  user)
    case "$sub" in
      list)        exec "$ST" user list ;;
      show)        exec "$ST" user show "$a" ;;
      create)      exec "$ST" user create "$a" "$b" ;;
      delete)      exec "$ST" user delete "$a" ;;
      setpassword) exec "$ST" user setpassword "$a" --newpassword="$b" ;;
      photo)
        # Photo de l'agent dans l'annuaire : $a=identifiant  $b=fichier image (ou "-" pour retirer).
        # L'attribut « thumbnailPhoto » est celui que lisent Outlook, Teams et les annuaires ;
        # il est plafonné à 100 Ko par convention Active Directory (on refuse au-delà plutôt que
        # d'écrire une valeur que les clients ignoreraient).
        [ -n "$a" ] || { echo "ERROR: identifiant requis" >&2; exit 2; }
        python3 - "$a" "$b" <<'PY'
import sys, os, ldb
from samba.samdb import SamDB
from samba.auth import system_session
from samba.param import LoadParm
login, img = sys.argv[1], sys.argv[2]
lp = LoadParm(); lp.load_default()
db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
esc = login.replace('\\', '\\5c').replace('(', '\\28').replace(')', '\\29').replace('*', '\\2a')
res = db.search(expression='(sAMAccountName=%s)' % esc, attrs=['dn'])
if not res:
    print('ERROR: compte introuvable', file=sys.stderr); sys.exit(1)
m = ldb.Message(); m.dn = res[0].dn
if img in ('-', '') or not os.path.isfile(img):
    m['thumbnailPhoto'] = ldb.MessageElement([], ldb.FLAG_MOD_REPLACE, 'thumbnailPhoto')
    db.modify(m); print('photo retiree'); sys.exit(0)
data = open(img, 'rb').read()
if len(data) > 100 * 1024:
    print('ERROR: photo trop lourde (%d Ko, maximum 100 Ko)' % (len(data) // 1024), file=sys.stderr); sys.exit(2)
m['thumbnailPhoto'] = ldb.MessageElement([data], ldb.FLAG_MOD_REPLACE, 'thumbnailPhoto')
db.modify(m)
print('photo publiee (%d Ko)' % (len(data) // 1024))
PY
        ;;
      identity)
        # Identité affichée par Windows : $a=identifiant  $b=prénom  $c=nom  $d=service.
        # Sans « displayName », l'écran de session et le menu Démarrer affichent le MATRICULE
        # (sAMAccountName) — d'où des postes où l'agent ne voit que « 0110480 ».
        # « samba-tool user edit » ouvre un éditeur : on passe donc par une modification LDAP.
        [ -n "$a" ] || { echo "ERROR: identifiant requis" >&2; exit 2; }
        python3 - "$a" "$b" "$c" "$d" <<'PY'
import sys, ldb
from samba.samdb import SamDB
from samba.auth import system_session
from samba.param import LoadParm
login, prenom, nom, service = sys.argv[1], sys.argv[2].strip(), sys.argv[3].strip(), sys.argv[4].strip()
lp = LoadParm(); lp.load_default()
db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
esc = login.replace('\\', '\\5c').replace('(', '\\28').replace(')', '\\29').replace('*', '\\2a')
res = db.search(expression='(sAMAccountName=%s)' % esc, attrs=['dn'])
if not res:
    print('ERROR: compte introuvable', file=sys.stderr); sys.exit(1)
dn = res[0].dn
# Convention retenue : « NOM Prénom », comme dans le reste de la console.
disp = ' '.join(x for x in (nom.upper(), prenom) if x)
m = ldb.Message(); m.dn = dn
def put(attr, val):
    if val:
        m[attr] = ldb.MessageElement(val, ldb.FLAG_MOD_REPLACE, attr)
    else:   # champ vidé côté console → on retire l'attribut plutôt que d'écrire du vide
        m[attr] = ldb.MessageElement([], ldb.FLAG_MOD_REPLACE, attr)
put('displayName', disp)
put('givenName', prenom)
put('sn', nom)
put('department', service)
db.modify(m)
print('identite mise a jour: %s' % (disp or login))
PY
        ;;
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
  move)
    # Déplace un objet vers une unité d'organisation. $sub = type, $a = nom, $b = OU de destination.
    # La destination est fournie SANS la base du domaine (ex. « OU=CPN EVRY ») : on la complète
    # ici, pour qu'aucun DN arbitraire ne puisse être injecté depuis la console.
    rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
    dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
    [ -n "$a" ] || { echo "ERROR: objet requis" >&2; exit 2; }
    # Destinations autorisees : une OU, ou le conteneur PAR DEFAUT « CN=Users » (celui ou
    # Windows range les comptes). On refuse tout DN libre, et notamment ceux qui portent deja
    # « DC= » : la base du domaine est ajoutee ici, jamais fournie par l'appelant.
    case "$b" in
      ''|Users) dest="CN=Users,$dn" ;;
      *DC=*) echo "ERROR: destination invalide" >&2; exit 2 ;;
      OU=*) dest="$b,$dn" ;;
      *) echo "ERROR: destination invalide" >&2; exit 2 ;;
    esac
    case "$sub" in
      user|computer|group|ou) exec "$ST" "$sub" move "$a" "$dest" ;;
      *) echo "type d'objet refuse" >&2; exit 2 ;;
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
        # $b = 1 : faire monter les postes Professionnel en Entreprise (option de la console).
        python3 /usr/local/sbin/proxyfibre-gpo-kms "$guid" "$a" "$b" >/dev/null 2>&1 \
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
      inventaire)
        # GPO « Bastion — Inventaire du parc » : script d'ouverture de session qui releve les
        # caracteristiques du poste et les transmet a la passerelle. $a = URL, $b = jeton.
        name="Bastion — Inventaire du parc"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        python3 /usr/local/sbin/proxyfibre-gpo-inventory "$guid" "$a" "$b" >/dev/null 2>&1           || { echo "ERROR: generation du collecteur echouee ($guid)" >&2; exit 1; }
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn"           || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid inventaire deploye"
        ;;
      wmi)
        # Filtre WMI : restreindre une stratégie aux postes qui remplissent une condition.
        #   $a = list | set | clear     $b = {GUID de la GPO}     $c = clé de filtre
        case "$a" in
          list)  exec python3 /usr/local/sbin/proxyfibre-gpo-wmi list ;;
          status) exec python3 /usr/local/sbin/proxyfibre-gpo-wmi status ;;
          set)   exec python3 /usr/local/sbin/proxyfibre-gpo-wmi set "$b" "$c" ;;
          clear) exec python3 /usr/local/sbin/proxyfibre-gpo-wmi clear "$b" ;;
          *) echo "usage: gpo wmi list|set <guid> <filtre>|clear <guid>" >&2; exit 2 ;;
        esac ;;
      logon)
        # Écran de connexion : GPO « Bastion — Écran de connexion ».
        #   $a = image (facultatif, "-" pour ne pas y toucher)   $b = titre   $c = message
        #   $d = options, lettres cumulables : u=masquer le dernier utilisateur,
        #        d=masquer les details du compte, s=retirer le bouton d'arret,
        #        c=forcer l'image aussi sur les editions Famille/Professionnel (PersonalizationCSP).
        name="Bastion — Écran de connexion"
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$name" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$name" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        rl=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        pol="/var/lib/samba/sysvol/$rl/Policies/$guid"
        [ -d "$pol" ] || { echo "ERROR: SYSVOL GPO introuvable" >&2; exit 1; }

        unc=""
        if [ -n "$a" ] && [ "$a" != "-" ] && [ -f "$a" ]; then
          # Comme pour le fond d'écran, Windows ne rend fiablement que le JPG.
          mdir="$pol/Machine"; mkdir -p "$mdir"
          rm -f "$mdir"/bastion-logon.* 2>/dev/null || true
          img="$mdir/bastion-logon.jpg"
          if command -v convert >/dev/null 2>&1 && convert "${a}[0]" -background black -flatten -quality 90 "$img" 2>/dev/null && [ -s "$img" ]; then
            :
          else
            cp "$a" "$img"
          fi
          chmod 644 "$img"
          ref="$pol/GPT.INI"
          if [ -f "$ref" ]; then
            v=$(getfattr --absolute-names -n security.NTACL -e hex "$ref" 2>/dev/null | sed -n 's/^security.NTACL=//p')
            if [ -n "$v" ]; then
              setfattr -n security.NTACL -v "$v" "$mdir" 2>/dev/null || true
              setfattr -n security.NTACL -v "$v" "$img"  2>/dev/null || true
            fi
          fi
          unc="\\\\${rl}\\SysVol\\${rl}\\Policies\\${guid}\\Machine\\bastion-logon.jpg"
        elif [ -f "$pol/Machine/bastion-logon.jpg" ]; then
          unc="\\\\${rl}\\SysVol\\${rl}\\Policies\\${guid}\\Machine\\bastion-logon.jpg"
        fi

        tmpj=$(mktemp)
        python3 - "$unc" "$b" "$c" "$d" > "$tmpj" <<'PY'
import json, sys
unc, cap, txt, flags = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
SYS  = "Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\System"
PERS = "Software\\Policies\\Microsoft\\Windows\\Personalization"
WSYS = "Software\\Policies\\Microsoft\\Windows\\System"
CSP  = "Software\\Microsoft\\Windows\\CurrentVersion\\PersonalizationCSP"
p = []
if unc:
    # Image de l'écran de verrouillage/connexion. Stratégie officielle : éditions
    # Entreprise/Éducation. Sur l'édition Famille/Pro, Windows l'ignore (voir l'aide).
    p.append({"keyname": PERS, "valuename": "LockScreenImage", "class": "MACHINE", "type": "REG_SZ", "data": unc})
# Repli pour les éditions Famille/Professionnel : PersonalizationCSP applique l'image là où la
# stratégie officielle est ignorée. Ce ne sont PAS des clés de stratégie : elles restent inscrites
# sur le poste après le retrait de la GPO (« tatouage ») — d'où le nettoyage explicite ci-dessous
# quand l'option est décochée, faute de quoi l'image ne pourrait plus jamais être retirée.
if "c" in flags and unc:
    p.append({"keyname": CSP, "valuename": "LockScreenImagePath",   "class": "MACHINE", "type": "REG_SZ", "data": unc})
    p.append({"keyname": CSP, "valuename": "LockScreenImageUrl",    "class": "MACHINE", "type": "REG_SZ", "data": unc})
    p.append({"keyname": CSP, "valuename": "LockScreenImageStatus", "class": "MACHINE", "type": "REG_DWORD", "data": 1})
else:
    p.append({"keyname": CSP, "valuename": "LockScreenImageStatus", "class": "MACHINE", "type": "REG_DWORD", "data": 0})
    p.append({"keyname": CSP, "valuename": "LockScreenImagePath",   "class": "MACHINE", "type": "REG_SZ", "data": ""})
    p.append({"keyname": CSP, "valuename": "LockScreenImageUrl",    "class": "MACHINE", "type": "REG_SZ", "data": ""})
# Titre + message affichés AVANT la saisie du mot de passe. Vides = pas de bannière.
p.append({"keyname": SYS, "valuename": "legalnoticecaption", "class": "MACHINE", "type": "REG_SZ", "data": cap})
p.append({"keyname": SYS, "valuename": "legalnoticetext",    "class": "MACHINE", "type": "REG_SZ", "data": txt})
p.append({"keyname": SYS, "valuename": "DontDisplayLastUserName", "class": "MACHINE", "type": "REG_DWORD",
          "data": 1 if "u" in flags else 0})
# « shutdownwithoutlogon » = 0 retire le bouton d'arrêt de l'écran de connexion.
p.append({"keyname": SYS, "valuename": "shutdownwithoutlogon", "class": "MACHINE", "type": "REG_DWORD",
          "data": 0 if "s" in flags else 1})
p.append({"keyname": WSYS, "valuename": "BlockUserFromShowingAccountDetailsOnSignin", "class": "MACHINE",
          "type": "REG_DWORD", "data": 1 if "d" in flags else 0})
print(json.dumps(p))
PY
        python3 /usr/local/sbin/proxyfibre-gpo-apply "$guid" "$tmpj" >/dev/null 2>&1; rc=$?
        rm -f "$tmpj"
        [ "$rc" -eq 0 ] || { echo "ERROR: application de l'ecran de connexion echouee ($guid)" >&2; exit 1; }
        dn=$(printf '%s' "$rl" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 || true
        echo "$guid ecran de connexion deploye"
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
    # Droits d'un dossier de partage : le POSIX doit seulement PERMETTRE LA TRAVERSÉE, la politique
    # d'accès est portée par le NT ACL (xattr, vu par Windows). Historiquement on posait « chmod 2770 »
    # sur un dossier créé par root → drwxrws--- root root → AUCUN agent ne pouvait entrer
    # (smbd : « vfs_ChDir(...) failed: Permission non accordée, uid=3000016, gid=100 »).
    # Les ACL POSIX sont PURGÉES : une entrée de groupe (ex. « group:users:--- » posée par
    # samba-tool ntacl set) l'emporte sur « other » et bloque l'accès même en 1777.
    # 1777 = sticky bit : chacun écrit, mais nul ne supprime les fichiers d'autrui.
    # RÉCURSIF : le contenu déjà déposé garde sinon ses anciens droits (fichiers créés en 0644
    # avant l'ajout des masques → un collègue ne peut pas les modifier).
    share_perms() {
      [ -d "$1" ] || return 0
      setfacl -Rb "$1" 2>/dev/null || true
      setfacl -Rk "$1" 2>/dev/null || true
      chmod 1777 "$1" 2>/dev/null || true
      find "$1" -mindepth 1 -type d -exec chmod 1777 {} + 2>/dev/null || true
      find "$1" -mindepth 1 -type f -exec chmod 0666 {} + 2>/dev/null || true
    }
    # Masques de création : sans eux Samba applique 0744/0755 et un fichier déposé par un agent
    # n'est PAS modifiable par ses collègues (et un sous-dossier leur est fermé en écriture).
    # « map archive = No » et « store dos attributes = Yes » sur cette install → 0666/0777 est correct.
    SH_MASKS='   create mask = 0666
   force create mode = 0666
   directory mask = 0777
   force directory mode = 0777'
    # Jetons d'un groupe pour valid users / write list. On émet les DEUX formes : sans préfixe
    # (résolution par le SAM interne du DC) et « @ » (résolution NSS/winbind). Un jeton qui ne
    # résout pas est simplement ignoré par Samba.
    share_tok() { printf '"%s\\%s", @"%s\\%s"' "$SH_WG" "$1" "$SH_WG" "$1"; }
    SH_WG=$(testparm -s --parameter-name=workgroup 2>/dev/null | tr -d '\r')
    [ -n "$SH_WG" ] || SH_WG=BASTION
    case "$sub" in
      list) cat "$SHFILE" 2>/dev/null || true ;;
      repair)
        # Réapplique des droits corrects à TOUS les partages de données (jamais /srv/pxe, protégé) :
        # répare les partages créés avant le correctif (inaccessibles aux agents) ET pose les
        # masques de création manquants (sans quoi un fichier déposé n'est pas modifiable par les
        # collègues). Ne touche JAMAIS aux listes de droits par groupe.
        n=0
        for p in /srv/partage/*; do
          [ -d "$p" ] || continue
          share_perms "$p"; n=$((n+1))
        done
        cp -f "$SHFILE" "$SHFILE.bak-repair" 2>/dev/null || true
        awk -v masks="$SH_MASKS" '
          function flush(){ if (insec && !seen && !pxe) print masks }
          /^[[:space:]]*\[/ { flush(); insec=0; seen=0; pxe=0 }
          /^[[:space:]]*\[/ && $0 !~ /^\[(sysvol|netlogon|global|homes|printers)\]/ { insec=1 }
          /^[[:space:]]*path[[:space:]]*=[[:space:]]*\/srv\/pxe/ { pxe=1 }
          /^[[:space:]]*create mask[[:space:]]*=/ { seen=1 }
          { print }
          END { flush() }
        ' "$SHFILE" > "$SHFILE.tmp" 2>/dev/null && mv "$SHFILE.tmp" "$SHFILE"
        testparm -s >/dev/null 2>&1 || cp -f "$SHFILE.bak-repair" "$SHFILE" 2>/dev/null || true
        smbcontrol all reload-config >/dev/null 2>&1 || true
        echo "$n partage(s) repare(s) (droits du contenu + masques de creation)" ;;
      create)
        name=$(printf '%s' "$a" | tr -cd 'A-Za-z0-9_-')
        [ -n "$name" ] || { echo "nom invalide" >&2; exit 2; }
        mkdir -p "/srv/partage/$name"
        share_perms "/srv/partage/$name"
        if ! grep -q "^\[$name\]" "$SHFILE" 2>/dev/null; then
          printf '\n[%s]\n   path = /srv/partage/%s\n   read only = no\n   browseable = yes\n%s\n   dfree command = /usr/local/sbin/proxyfibre-share-dfree\n' \
            "$name" "$name" "$SH_MASKS" >> "$SHFILE"
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
      acl)
        # Droits par GROUPE AD : $a=nom  $b=groupes en LECTURE SEULE  $c=groupes en LECTURE-ÉCRITURE
        # (listes séparées par des virgules). Cas particuliers :
        #   b="" c=""   → tous les agents, lecture-écriture (état par défaut)
        #   b="*" c=""  → tous les agents, lecture seule
        # « Aucun accès » = ABSENCE des listes (jamais d'« invalid users », jamais d'ACE de refus).
        # La politique vit dans shares.conf : c'est la seule source de vérité relue par smbd, et
        # elle est SAUVEGARDÉE (contrairement aux NT ACL, absents des sauvegardes).
        name=$(printf '%s' "$a" | tr -cd 'A-Za-z0-9_-')
        [ -n "$name" ] || { echo "nom invalide" >&2; exit 2; }
        grep -q "^\[$name\]" "$SHFILE" 2>/dev/null || { echo "ERROR: partage inconnu" >&2; exit 1; }
        share_protege "$name" && { echo "ERROR: partage systeme (PXE) protege" >&2; exit 3; }

        adm=$(share_tok "Domain Admins")      # injecté ICI, jamais depuis la console : garantit
        vu=""; wl=""; roval=no                 # qu'un partage ne devienne jamais inadministrable.
        # Découpage sur la VIRGULE uniquement : un nom de groupe contient souvent des espaces
        # (« Domain Users », « SALLE INFORMATIQUE ») — un découpage par défaut le casserait en deux.
        _oifs=$IFS
        if [ "$b" = "*" ] && [ -z "$c" ]; then
          roval=yes; wl="$adm"                                   # tous : lecture seule
        elif [ -n "$b" ] || [ -n "$c" ]; then
          roval=yes; vu="$adm"; wl="$adm"                        # groupes désignés
          IFS=','
          for g in $b; do
            g=$(printf '%s' "$g" | tr -cd 'A-Za-z0-9 ._-'); [ -n "$g" ] || continue
            vu="$vu, $(share_tok "$g")"
          done
          for g in $c; do
            g=$(printf '%s' "$g" | tr -cd 'A-Za-z0-9 ._-'); [ -n "$g" ] || continue
            # Tout groupe en écriture doit AUSSI être dans valid users (valid users prime).
            vu="$vu, $(share_tok "$g")"; wl="$wl, $(share_tok "$g")"
          done
          IFS=$_oifs
        fi

        cp -f "$SHFILE" "$SHFILE.bak-acl" 2>/dev/null || true
        # Réécrit la section visée : purge les directives de droits puis réinjecte le bloc canonique.
        # Toutes les autres lignes (path, comment, dfree command…) sont recopiées telles quelles.
        # Les listes transitent par l'ENVIRONNEMENT et non par « awk -v » : ce dernier interprète
        # les séquences d'échappement et mangerait l'antislash de « BASTION\Groupe ».
        SH_RO="$roval" SH_VU="$vu" SH_WL="$wl" SH_MK="$SH_MASKS" \
        awk -v s="[$name]" '
          function emit(  ro, vu, wl) {
            ro = ENVIRON["SH_RO"]; vu = ENVIRON["SH_VU"]; wl = ENVIRON["SH_WL"]
            print "   read only = " ro
            if (vu != "") print "   valid users = " vu
            if (wl != "") print "   write list = " wl
            if (vu != "") print "   access based share enum = yes"
            print ENVIRON["SH_MK"]
          }
          $0==s { print; insec=1; next }
          insec && /^[[:space:]]*\[/ { emit(); insec=0 }
          insec && /^[[:space:]]*(read only|writeable|writable|valid users|invalid users|read list|write list|admin users|create mask|force create mode|directory mask|force directory mode|access based share enum)[[:space:]]*=/ { next }
          { print }
          END { if (insec) emit() }
        ' "$SHFILE" > "$SHFILE.tmp" && mv "$SHFILE.tmp" "$SHFILE"

        # Garde-fou : une seule ligne fautive invaliderait l'include et ferait disparaître
        # TOUS les partages. On valide avant de recharger, et on restaure sinon.
        if ! testparm -s >/dev/null 2>&1 || [ -z "$(testparm -s --section-name="$name" --parameter-name=path 2>/dev/null)" ]; then
          cp -f "$SHFILE.bak-acl" "$SHFILE" 2>/dev/null || true
          echo "ERROR: configuration invalide, modification annulee" >&2; exit 1
        fi
        smbcontrol all reload-config >/dev/null 2>&1 || true
        # Force la réévaluation : les droits ne sont testés qu'à la connexion au partage.
        smbcontrol all close-share "$name" >/dev/null 2>&1 || true
        if [ -n "$vu" ];        then echo "droits du partage $name : groupes designes"
        elif [ "$roval" = yes ]; then echo "droits du partage $name : tous les agents, lecture seule"
        else                          echo "droits du partage $name : tous les agents, lecture-ecriture"; fi ;;
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
