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
    case "$sub" in
      list)   exec "$ST" gpo listall ;;
      create) exec "$ST" gpo create "$a" -U "Administrator%${ADPASS}" ;;
      deploy)
        # Déploiement catalogue : (ré)écrit les stratégies registre du JSON $b dans la GPO
        # nommée $a, puis lie la GPO à la racine du domaine.
        # IDEMPOTENT PAR NOM : si une GPO du même nom d'affichage existe déjà, on RÉUTILISE
        # son GUID au lieu d'en créer une seconde. Sans cela, re-déployer une GPO du catalogue
        # (ex. corriger la stratégie de temps) laissait un DOUBLON : l'ancienne version,
        # incomplète et toujours liée, continuait de s'appliquer à côté de la nouvelle.
        [ -f "$b" ] || { echo "ERROR: json absent" >&2; exit 2; }
        guid=$("$ST" gpo listall 2>/dev/null | awk -v n="$a" '
            /^GPO/ {g=$3} /display name/ {sub(/^[^:]*: */,""); if ($0==n) {print g; exit}}')
        if [ -z "$guid" ]; then
          guid=$("$ST" gpo create "$a" -U "Administrator%${ADPASS}" 2>&1 | grep -oiE '\{[0-9A-Fa-f-]+\}' | head -1)
          [ -n "$guid" ] || { echo "ERROR: creation GPO echouee" >&2; exit 1; }
        fi
        # Écriture directe du Registry.pol sur le SYSVOL local (l'écriture SMB de
        # `gpo load` est refusée ; `gpo create` a déjà posé l'arborescence en local).
        python3 /usr/local/sbin/proxyfibre-gpo-apply "$guid" "$b" >/dev/null 2>&1 \
          || { echo "ERROR: application des strategies echouee ($guid)" >&2; exit 1; }
        realm=$(testparm -s --parameter-name=realm 2>/dev/null | tr 'A-Z' 'a-z')
        dn=$(printf '%s' "$realm" | awk -F. '{o="";for(i=1;i<=NF;i++){o=o (i>1?",":"") "DC=" $i} print o}')
        # Lien au domaine : posé seulement s'il n'existe pas déjà (re-déploiement).
        "$ST" gpo listcontainers "$guid" -U "Administrator%${ADPASS}" 2>/dev/null | grep -qi "$dn" \
          || "$ST" gpo setlink "$dn" "$guid" -U "Administrator%${ADPASS}" >/dev/null 2>&1 \
          || echo "ATTENTION: GPO prete mais lien au domaine echoue"
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
        list|create|sysvolreset) : ;;
        *) "$ST" ntacl sysvolreset >/dev/null 2>&1 || true ;;
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
          printf '\n[%s]\n   path = /srv/partage/%s\n   read only = no\n   browseable = yes\n' \
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
    printf 'realm=%s\n'     "$(testparm -s --parameter-name=realm 2>/dev/null || true)"
    printf 'workgroup=%s\n' "$(testparm -s --parameter-name=workgroup 2>/dev/null || true)" ;;
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
