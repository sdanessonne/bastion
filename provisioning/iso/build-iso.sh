#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Fabrique l'ISO d'installation MUETTE de Bastion : Debian netinst + préréglage + amorçage
# automatique. Le poste installe Debian sur un disque CHIFFRÉ, puis déploie Bastion depuis
# le dépôt Git, sans aucune question.
#
# ── OÙ SONT LES SECRETS ──────────────────────────────────────────────────────
# Ils ne sont PAS dans le dépôt. Ils vivent dans « iso-secrets.env », à côté de ce script,
# ignoré par Git (voir .gitignore). Ce script le crée au premier lancement avec les valeurs
# retenues pour ce commissariat, et vous pouvez l'éditer ensuite.
#
# L'ISO FABRIQUÉE, ELLE, CONTIENT CES SECRETS EN CLAIR : c'est la contrepartie inévitable
# d'une installation sans aucune saisie. Conservez-la comme une clé.
#
# Usage : ./build-iso.sh [chemin/vers/debian-netinst.iso]
set -euo pipefail

ICI=$(cd "$(dirname "$0")" && pwd)
SECRETS="$ICI/iso-secrets.env"
SORTIE="${SORTIE:-$ICI/bastion-installation.iso}"
DEPOT_DEFAUT="https://github.com/sdanessonne/bastion.git"

# ── Secrets (hors dépôt) ────────────────────────────────────────────────────
if [ ! -f "$SECRETS" ]; then
    echo
    echo "  Première fabrication : les secrets sont demandés une seule fois, puis conservés"
    echo "  dans $SECRETS (permissions 600, HORS DÉPÔT — ils ne partiront jamais sur Git)."
    echo
    printf "  Mot de passe du compte d'administration (proxyfibre) : "; read -rs _mdp; echo
    [ -n "$_mdp" ] || { echo "  ERREUR : mot de passe vide."; exit 1; }
    printf "  Phrase secrète du disque chiffré (Entrée = identique) : "; read -rs _luks; echo
    [ -n "$_luks" ] || _luks="$_mdp"
    # Cette invite-ci N'EST PAS masquée, contrairement aux deux précédentes : on y voit
    # ce qu'on tape. Un mot de passe saisi ici par inadvertance serait donc affiché à
    # l'écran ET enregistré comme adresse de dépôt — c'est arrivé, et le serveur
    # installé n'aurait rien pu récupérer. On refuse donc ce qui n'est pas une adresse.
    while :; do
        printf "  Dépôt Git [%s] : " "$DEPOT_DEFAUT"; read -r _dep
        [ -n "$_dep" ] && break
        _dep="$DEPOT_DEFAUT"; break
    done
    case "$_dep" in
        http://*|https://*|git@*|ssh://*|file://*|/*) ;;
        *)  echo
            echo "  « $_dep » n'est pas une adresse de dépôt."
            echo "  Attendu : https://…, git@…, ssh://… ou un chemin absolu."
            echo
            echo "  ATTENTION : si vous venez de saisir un MOT DE PASSE ici, il s'est"
            echo "  affiché en clair à l'écran. Considérez-le comme divulgué et changez-le."
            exit 1 ;;
    esac
    umask 077
    {   echo "# Bastion — secrets de fabrication de l'ISO. FICHIER NON VERSIONNÉ."
        echo "# Conservez-le en lieu sûr ; supprimez-le si vous n'en avez plus besoin."
        printf 'MDP_BASTION=%q\n' "$_mdp"
        printf 'MDP_ROOT=%q\n'    "$_mdp"
        printf 'LUKS=%q\n'        "$_luks"
        printf 'DEPOT=%q\n'       "$_dep"
    } > "$SECRETS"
    chmod 600 "$SECRETS"
    unset _mdp _luks _dep
    echo "  → Secrets enregistrés."
fi
# shellcheck disable=SC1090
. "$SECRETS"
: "${MDP_BASTION:?mot de passe du compte BASTION manquant}"
: "${LUKS:?phrase secrète du disque manquante}"
DEPOT="${DEPOT:-$DEPOT_DEFAUT}"

# ── Outils ──────────────────────────────────────────────────────────────────
for o in xorriso cpio gzip; do
    command -v "$o" >/dev/null || { echo "ERREUR : « $o » manquant. Installez : sudo apt install xorriso cpio"; exit 1; }
done

# ── Image Debian de départ ──────────────────────────────────────────────────
SRC="${1:-}"
if [ -z "$SRC" ]; then
    SRC="$ICI/debian-netinst.iso"
    if [ ! -f "$SRC" ]; then
        # Le NOM du fichier contient le numéro de version, qui change à chaque publication :
        # le figer garantit un 404 tôt ou tard — c'est arrivé dès la première fabrication.
        # On le découvre donc dans le fichier de sommes de contrôle, dont le nom est stable.
        BASE="https://cdimage.debian.org/debian-cd/current/amd64/iso-cd"
        echo "→ Recherche de l'image Debian netinst la plus récente…"
        SUMS=$(curl -fsSL "$BASE/SHA256SUMS" 2>/dev/null) || SUMS=""
        NOM=$(printf '%s\n' "$SUMS" | awk '$2 ~ /netinst\.iso$/ {print $2; exit}')
        [ -n "$NOM" ] || { echo "ERREUR : image Debian introuvable en ligne. Fournissez l'ISO en argument."; exit 1; }
        echo "   $NOM"
        curl -fL --progress-bar -o "$SRC" "$BASE/$NOM" \
            || { echo "ERREUR : téléchargement impossible. Fournissez l'ISO en argument."; exit 1; }
        # Intégrité : on ne fabrique pas un support d'installation système à partir d'un
        # fichier non contrôlé.
        ATT=$(printf '%s\n' "$SUMS" | awk -v n="$NOM" '$2==n {print $1}')
        if [ -n "$ATT" ]; then
            OBT=$(sha256sum "$SRC" | awk '{print $1}')
            [ "$ATT" = "$OBT" ] || { echo "ERREUR : somme de contrôle incorrecte — image corrompue ou altérée."; rm -f "$SRC"; exit 1; }
            echo "   somme de contrôle vérifiée."
        fi
    fi
fi
[ -f "$SRC" ] || { echo "ERREUR : image introuvable ($SRC)"; exit 1; }

# ── Répertoire de travail ───────────────────────────────────────────────────
# PAS /tmp par défaut : fabriquer une image COMPLÈTE demande de la place pour
# l'arborescence extraite, les médias embarqués et l'image finale — près de 20 Go
# quand la source Windows est incluse. Sur cette passerelle, / n'a que 8 Go libres
# alors que /srv/pxe en a 31. On choisit donc le point de montage le plus large,
# sauf indication contraire par la variable TRAVAIL.
if [ -z "${TRAVAIL:-}" ]; then
    TRAVAIL=/tmp
    for c in /srv/pxe /var/tmp /tmp; do
        [ -d "$c" ] || continue
        libre=$(df -B1 --output=avail "$c" 2>/dev/null | tail -1)
        actuel=$(df -B1 --output=avail "$TRAVAIL" 2>/dev/null | tail -1)
        [ "${libre:-0}" -gt "${actuel:-0}" ] && TRAVAIL="$c"
    done
fi
mkdir -p "$TRAVAIL"
echo "→ Répertoire de travail : $TRAVAIL ($(df -h --output=avail "$TRAVAIL" | tail -1 | tr -d ' ') libres)"
TRAV=$(mktemp -d -p "$TRAVAIL"); trap 'rm -rf "$TRAV"' EXIT
echo "→ Extraction de l'image…"
xorriso -osirrox on -indev "$SRC" -extract / "$TRAV/iso" >/dev/null 2>&1
chmod -R u+w "$TRAV/iso"

# ── Préréglage, avec les secrets injectés ───────────────────────────────────
echo "→ Injection du préréglage…"
esc() { printf '%s' "$1" | sed -e 's/[\/&|]/\\&/g'; }
sed -e "s|__MDP_BASTION__|$(esc "$MDP_BASTION")|g" \
    -e "s|__MDP_ROOT__|$(esc "${MDP_ROOT:-$MDP_BASTION}")|g" \
    -e "s|__LUKS__|$(esc "$LUKS")|g" \
    -e "s|__DEPOT__|$(esc "$DEPOT")|g" \
    "$ICI/preseed.cfg" > "$TRAV/preseed.cfg"

# Le préréglage est glissé DANS l'initrd de l'installateur : c'est la seule méthode qui
# fonctionne sans aucune question, y compris avant la configuration réseau.
echo "→ Insertion dans l'initrd…"
mkdir -p "$TRAV/ird" && cd "$TRAV/ird"
gzip -d < "$TRAV/iso/install.amd/initrd.gz" | cpio -id --quiet
cp "$TRAV/preseed.cfg" preseed.cfg
find . | cpio -o -H newc --quiet | gzip -9 > "$TRAV/iso/install.amd/initrd.gz"
cd "$ICI"

# ── Menu d'amorçage : démarrage automatique, sans intervention ──────────────
echo "→ Configuration de l'amorçage automatique…"
cat > "$TRAV/iso/isolinux/txt.cfg" <<'EOF'
default bastion
label bastion
	menu label ^Installer Bastion (automatique)
	kernel /install.amd/vmlinuz
	append auto=true priority=critical vga=788 initrd=/install.amd/initrd.gz preseed/file=/preseed.cfg --- quiet
EOF
sed -i 's/^timeout .*/timeout 30/' "$TRAV/iso/isolinux/isolinux.cfg" 2>/dev/null || true
# Même chose pour l'amorçage UEFI (GRUB).
if [ -f "$TRAV/iso/boot/grub/grub.cfg" ]; then
    cat > "$TRAV/iso/boot/grub/grub.cfg" <<'EOF'
set default=0
set timeout=3
menuentry "Installer Bastion (automatique)" {
    linux /install.amd/vmlinuz auto=true priority=critical preseed/file=/preseed.cfg --- quiet
    initrd /install.amd/initrd.gz
}
EOF
fi

# ── Médias embarqués : l'image « complète » ─────────────────────────────────
# Par défaut Bastion ne fournit QUE son propre logiciel : la source d'installation
# Windows appartient au service, elle n'a pas à être redistribuée. Mais pour un pack
# remis clé en main à un commissariat qui dispose de sa licence en volume, tout
# réunir sur un seul support évite une manipulation et une clé à ne pas oublier.
#
# Les fichiers présents sont copiés dans /bastion/ de l'image, et récupérés PENDANT
# l'installation (voir copier.sh ci-dessous), avant même le premier démarrage.
#   Par défaut on n'embarque QUE la source Windows : c'est elle qui rend le parc
#   déployable, et elle seule justifie le poids. Y ajouter l'image Ubuntu ferait
#   passer le support de 9 à 15 Go pour un amorçage secondaire, et demanderait
#   30 Go d'espace de travail. Pour tout embarquer :
#       MEDIAS="/srv/pxe/iso/win11.iso /srv/pxe/iso/ubuntu.iso /srv/pxe/images/master.wim" ./build-iso.sh
#   Pour n'embarquer RIEN (image légère, médias apportés sur une clé à part) :
#       MEDIAS=" " ./build-iso.sh
COMPLET=0
mkdir -p "$TRAV/iso/bastion"

# ── Le code de Bastion, embarqué ────────────────────────────────────────────
# Le préréglage récupérait le dépôt par « git clone » au premier démarrage. Sur un
# dépôt PRIVÉ — c'est le cas — cela demande une authentification que le serveur
# fraîchement installé n'a pas : il se serait installé, puis n'aurait rien déployé,
# sans que rien ne l'annonce avant la fin.
# Le code pèse 3 Mo : on l'embarque. Plus de dépendance à GitHub, plus de jeton à
# glisser dans l'image, et la version livrée est exactement celle qu'on a testée.
DEPOT_LOCAL=$(cd "$ICI/../.." && pwd)
if [ -d "$DEPOT_LOCAL/.git" ] && git -C "$DEPOT_LOCAL" rev-parse HEAD >/dev/null 2>&1; then
    VER=$(git -C "$DEPOT_LOCAL" rev-parse --short HEAD)
    echo "→ Code Bastion embarqué : version $VER"
    git -C "$DEPOT_LOCAL" archive --format=tar --prefix=proxyFibre/ HEAD \
        > "$TRAV/iso/bastion/source.tar"
    printf '%s\n' "$VER" > "$TRAV/iso/bastion/source.version"
    COMPLET=1
else
    echo "→ ATTENTION : dépôt Git introuvable, le code ne sera PAS embarqué."
    echo "  Le serveur installé tentera un « git clone » — impossible sur un dépôt privé."
fi
for src in "${MEDIAS-/srv/pxe/iso/win11.iso}"; do
    for f in $src; do
        [ -f "$f" ] || continue
        echo "→ Média embarqué : $(basename "$f") ($(du -h "$f" | cut -f1))"
        cp "$f" "$TRAV/iso/bastion/$(basename "$f")"
        COMPLET=1
    done
done

# Ces deux scripts sont embarqués DANS TOUS LES CAS, même sans média ni code : ce
# sont eux qui écrivent le journal d'installation. Les omettre quand il n'y a rien
# à copier, c'est retirer la trace au moment précis où elle est indispensable.
if true; then
    # Récupération PENDANT l'installation : le support est encore monté sur /cdrom,
    # et le système cible est accessible sous /target. Au premier démarrage le
    # support pourrait déjà avoir été retiré — on ne compte donc pas dessus.
    # Ce script est appelé par le préréglage ; l'écrire ici plutôt que dans le
    # préréglage évite d'y empiler des guillemets et des échappements fragiles.
    cat > "$TRAV/iso/bastion/copier.sh" <<'CPEOF'
#!/bin/sh
# Recopie les médias du support d'installation vers le système en cours d'installation.
#
# ── POURQUOI CETTE RECHERCHE, ET PAS UN SEUL CHEMIN ─────────────────────────
# La première version ne regardait qu'à deux endroits, /cdrom et /media. À
# l'installation réelle, depuis une CLÉ USB, le support n'y était pas : rien n'a
# été recopié, le script de premier démarrage s'est rabattu sur « git clone », le
# dépôt est privé, et le service a échoué EN UNE SECONDE. Le serveur a démarré sur
# un écran Debian nu, sans le moindre indice.
# On cherche donc partout où l'installateur peut avoir monté le support, puis on
# monte soi-même les partitions si nécessaire. Et surtout : on ÉCRIT ce qu'on a
# trouvé, pour qu'un échec se lise au lieu de se deviner.
set -u
J=/target/var/log/bastion-install.log
mkdir -p /target/var/log 2>/dev/null
note() { echo "[copier.sh] $*" >> "$J" 2>/dev/null; echo "[copier.sh] $*"; }

SRC=""
for d in /cdrom /media /media/cdrom /mnt /hd-media /run/media /srv; do
    if [ -f "$d/bastion/source.tar" ] || [ -f "$d/bastion/win11.iso" ]; then
        SRC="$d/bastion"; note "support trouvé : $SRC"; break
    fi
done

# Rien aux emplacements habituels : on inspecte les systèmes de fichiers déjà montés,
# puis on tente de monter chaque partition. L'installateur ne monte pas toujours la
# clé, et c'est précisément ce cas qui a fait échouer la première installation.
if [ -z "$SRC" ]; then
    note "pas de support aux emplacements habituels, recherche élargie…"
    while read -r _dev pt _rest; do
        case "$pt" in /|/target|/proc|/sys|/dev*) continue ;; esac
        if [ -f "$pt/bastion/source.tar" ]; then SRC="$pt/bastion"; note "trouvé sur $pt"; break; fi
    done < /proc/mounts
fi
if [ -z "$SRC" ]; then
    mkdir -p /tmp/bsrch 2>/dev/null
    for dev in /dev/sd?? /dev/nvme?n?p? /dev/vd??; do
        [ -b "$dev" ] || continue
        mount -o ro "$dev" /tmp/bsrch 2>/dev/null || continue
        if [ -f /tmp/bsrch/bastion/source.tar ]; then
            SRC=/tmp/bsrch/bastion; note "trouvé en montant $dev"; break
        fi
        umount /tmp/bsrch 2>/dev/null
    done
fi

if [ -z "$SRC" ]; then
    note "AUCUN support de médias trouvé. Le code Bastion n'a PAS été déposé."
    note "Le premier démarrage tentera un « git clone » — impossible sur un dépôt privé."
    exit 0
fi

# Le code de Bastion. On le pose AVANT le premier démarrage, pour que le script de
# démarrage n'ait pas à cloner un dépôt privé auquel il n'a pas accès.
if [ -f "$SRC/source.tar" ]; then
    mkdir -p /target/home/proxyfibre
    if tar -xf "$SRC/source.tar" -C /target/home/proxyfibre; then
        [ -f "$SRC/source.version" ] && cp "$SRC/source.version" /target/home/proxyfibre/proxyFibre/.version-iso
        note "code Bastion déposé dans /home/proxyfibre/proxyFibre"
    else
        note "ÉCHEC de l'extraction du code Bastion."
    fi
else
    note "pas de source.tar sur le support."
fi

mkdir -p /target/srv/pxe/iso /target/srv/pxe/images
for n in win11.iso ubuntu.iso; do
    [ -f "$SRC/$n" ] || continue
    if cp "$SRC/$n" "/target/srv/pxe/iso/$n.part"; then
        mv "/target/srv/pxe/iso/$n.part" "/target/srv/pxe/iso/$n"; note "$n recopié"
    else
        rm -f "/target/srv/pxe/iso/$n.part"; note "ÉCHEC de la recopie de $n"
    fi
done
if [ -f "$SRC/master.wim" ]; then
    cp "$SRC/master.wim" /target/srv/pxe/images/master.wim.part       && mv /target/srv/pxe/images/master.wim.part /target/srv/pxe/images/master.wim       && note "master.wim recopié"
fi
# Le script de premier démarrage, déposé à sa place définitive.
if [ -f "$SRC/init.sh" ]; then
    mkdir -p /target/opt/bastion-init
    cp "$SRC/init.sh" /target/opt/bastion-init/run.sh && chmod +x /target/opt/bastion-init/run.sh       && note "script de premier démarrage déposé"
fi
note "terminé."
exit 0
CPEOF
    chmod +x "$TRAV/iso/bastion/copier.sh"

    # ── Script de PREMIER DÉMARRAGE, écrit comme un vrai fichier ────────────
    # Il était auparavant fabriqué à coups de « printf » dans le préréglage :
    # illisible, et impossible à faire évoluer sans casser un échappement.
    # Ici c'est du shell ordinaire, relisible et modifiable.
    cat > "$TRAV/iso/bastion/init.sh" <<INITEOF
#!/bin/sh
# Bastion — déploiement initial, exécuté UNE FOIS au premier démarrage.
#
# PAS de « set -e » : la première version en avait un, et le script est mort en
# UNE SECONDE sur un « git clone » impossible, sans écrire une seule ligne. Le
# serveur a démarré sur un écran Debian nu. Ici, chaque étape est tracée, et un
# échec s'affiche sur l'écran de connexion au lieu de rester invisible.
L=/var/log/bastion-install.log
R=/home/proxyfibre/proxyFibre
n() { echo "[init] \$(date '+%H:%M:%S') \$*" >> "\$L"; }
echouer() {
    n "ECHEC : \$1"
    printf '\n  BASTION : le deploiement a ECHOUE.\n  %s\n  Journal : %s\n\n' "\$1" "\$L" > /etc/issue
    exit 1
}

n "démarrage du déploiement initial"

if [ ! -d "\$R" ]; then
    n "code absent du disque — le support ne l'a pas déposé ; tentative de récupération"
    git clone --depth 1 '$DEPOT' "\$R" >> "\$L" 2>&1 || n "git clone impossible (dépôt privé ?)"
fi
[ -d "\$R" ] || echouer "aucun code Bastion n'a pu etre obtenu"

n "code présent (version \$(cat "\$R/.version-iso" 2>/dev/null || echo inconnue))"
chown -R proxyfibre:proxyfibre "\$R" 2>/dev/null || true

n "lancement de deploy.sh"
cd "\$R" || echouer "repertoire du code inaccessible"
if bash provisioning/deploy.sh >> "\$L" 2>&1; then
    n "deploy.sh terminé"
else
    echouer "deploy.sh a echoue"
fi

bash "\$R/services/scripts/import-media.sh" auto >> "\$L" 2>&1 || n "import des médias : rien à faire"
n "déploiement terminé"
systemctl disable bastion-init.service >/dev/null 2>&1
exit 0
INITEOF
    chmod +x "$TRAV/iso/bastion/init.sh"
fi

# ── Destination : là où il y a la place ─────────────────────────────────────
# La variable TRAVAIL ne déplaçait QUE le répertoire de travail : l'image sortait
# toujours à côté du script, sur une partition de 8 Go. Une fabrication complète y
# échouait — mais SEULEMENT à la toute fin, après avoir recopié 8 Go de médias :
#   « Image size 4618663s exceeds free space on media 4380986s »
# On vérifie donc AVANT, et l'on bascule sur le répertoire de travail si besoin.
BESOIN=$(du -sb "$TRAV/iso" | cut -f1)
MARGE=$((BESOIN + BESOIN / 20))          # 5 % pour les métadonnées de l'image
LIBRE=$(df -B1 --output=avail "$(dirname "$SORTIE")" | tail -1)
if [ "${LIBRE:-0}" -lt "$MARGE" ]; then
    LIBRE_TRAV=$(df -B1 --output=avail "$TRAVAIL" | tail -1)
    if [ "${LIBRE_TRAV:-0}" -ge "$MARGE" ]; then
        echo "→ Place insuffisante dans $(dirname "$SORTIE") ($(numfmt --to=iec "$LIBRE")) :"
        echo "  l'image sortira dans $TRAVAIL, où il reste $(numfmt --to=iec "$LIBRE_TRAV")."
        SORTIE="$TRAVAIL/$(basename "$SORTIE")"
    else
        echo
        echo "  ERREUR : il faut environ $(numfmt --to=iec "$MARGE") pour écrire l'image."
        echo "    $(dirname "$SORTIE") : $(numfmt --to=iec "$LIBRE") libres"
        echo "    $TRAVAIL : $(numfmt --to=iec "$LIBRE_TRAV") libres"
        echo "  Libérez de la place, ou indiquez une destination avec SORTIE=/chemin/image.iso"
        exit 1
    fi
fi

# ── Fabrication ─────────────────────────────────────────────────────────────
# « -iso-level 3 » est INDISPENSABLE dès qu'un fichier dépasse 4 Go : l'ISO 9660 de
# niveau 1 ou 2 ne sait pas le représenter, et il serait tronqué SANS ERREUR. La
# source Windows en fait près de 8. Le niveau 3 le découpe en plusieurs extents, que
# Linux — donc l'installateur Debian, seul lecteur de cette image — recolle.
#
# PAS de « -udf » : l'émulation mkisofs de xorriso ne connaît pas cette option et
# refuse tout net (« Unsupported option '-udf' »). Elle n'apporterait rien ici.
echo "→ Fabrication de l'image…"
cd "$TRAV/iso"
# Les erreurs de xorriso ne sont PAS avalées : elles l'étaient, et une fabrication
# ratée ne laissait qu'un journal s'arrêtant sur « Fabrication de l'image… », sans
# la moindre explication. On garde la sortie, on ne masque que le bavardage normal.
JRN="$TRAV/xorriso.log"
if ! xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
        -iso-level 3 \
        -isohybrid-mbr /usr/lib/ISOLINUX/isohdpfx.bin \
        -c isolinux/boot.cat -b isolinux/isolinux.bin \
        -no-emul-boot -boot-load-size 4 -boot-info-table \
        -eltorito-alt-boot -e boot/grub/efi.img -no-emul-boot \
        -isohybrid-gpt-basdat . > "$JRN" 2>&1; then
    echo "  L'amorçage hybride a échoué, seconde tentative sans lui :"
    grep -iE "FAILURE|SORRY|aborting" "$JRN" | head -5 | sed 's/^/    /'
    if ! xorriso -as mkisofs -r -V "BASTION" -o "$SORTIE" \
            -iso-level 3 \
            -c isolinux/boot.cat -b isolinux/isolinux.bin \
            -no-emul-boot -boot-load-size 4 -boot-info-table . > "$JRN" 2>&1; then
        echo
        echo "  ERREUR : fabrication de l'image impossible."
        grep -iE "FAILURE|SORRY|aborting|No such" "$JRN" | head -10 | sed 's/^/    /'
        cp "$JRN" "$ICI/xorriso-echec.log" 2>/dev/null && \
            echo "    Journal complet : $ICI/xorriso-echec.log"
        exit 1
    fi
fi
[ -f "$SORTIE" ] || { echo "  ERREUR : aucune image produite."; exit 1; }

# ── Rendre le dépôt à son propriétaire ──────────────────────────────────────
# Ce script tourne sous sudo, et tout ce qu'il écrit dans le dépôt appartient donc
# à root. Au « git pull » suivant, l'utilisateur se heurtait à :
#   « propriétaire douteux détecté », puis « .git/FETCH_HEAD : Permission non accordée »
# — le dépôt devenait inutilisable pour son propre propriétaire, et la seule façon
# de s'en sortir était un chown manuel. On le fait donc ici, à la source.
if [ -n "${SUDO_UID:-}" ] && [ -n "${SUDO_GID:-}" ]; then
    DEPOT_RACINE=$(cd "$ICI/../.." && pwd)
    chown -R "$SUDO_UID:$SUDO_GID" "$DEPOT_RACINE" 2>/dev/null || true
    [ -f "$SORTIE" ] && chown "$SUDO_UID:$SUDO_GID" "$SORTIE" 2>/dev/null || true
    # Une exception : le fichier de secrets reste à root. Il contient le mot de
    # passe du serveur et la phrase du disque chiffré, en clair.
    [ -f "$ICI/iso-secrets.env" ] && { chown root:root "$ICI/iso-secrets.env"
                                       chmod 600 "$ICI/iso-secrets.env"; } 2>/dev/null
fi

# CONTRÔLE : le fichier le plus gros de l'image est-il ressorti INTACT ? Un dépassement
# de la limite ISO 9660 tronque sans rien dire ; on le vérifie plutôt que d'y croire.
if [ "$COMPLET" = 1 ]; then
    echo "→ Contrôle des médias embarqués…"
    # « -lsl » donne la taille réelle en 5e champ. Un premier jet lisait « report_lba »
    # et en tirait un chiffre sans rapport : le contrôle criait à la troncature sur une
    # image parfaitement saine. Un contrôle qui se trompe est pire que pas de contrôle.
    for f in "$TRAV/iso/bastion/"*.iso "$TRAV/iso/bastion/"*.wim; do
        [ -f "$f" ] || continue
        n=$(basename "$f"); att=$(stat -c%s "$f")
        obt=$(xorriso -indev "$SORTIE" -cd /bastion -lsl -- 2>/dev/null \
              | awk -v n="'$n'" '$9==n {print $5; exit}')
        obt=${obt:-0}
        if [ "$obt" != "$att" ]; then
            echo "  ERREUR : $n fait $obt octets dans l'image au lieu de $att."
            echo "  L'image est inutilisable — elle n'est pas conservée."
            rm -f "$SORTIE"; exit 1
        fi
        echo "   $n : $att octets, intact."
    done
fi
cd "$ICI"

echo
echo "  ✔ Image prête : $SORTIE"
echo "    $(du -h "$SORTIE" | cut -f1)"
echo
echo "  ATTENTION : cette image contient le mot de passe du compte d'administration"
echo "  secrète du disque, en clair. C'est le prix d'une installation sans aucune saisie."
echo "  Conservez-la comme une clé : coffre ou armoire forte, jamais un partage ouvert."
echo
echo "  Écriture sur clé USB :  sudo dd if=$SORTIE of=/dev/sdX bs=4M status=progress conv=fsync"
