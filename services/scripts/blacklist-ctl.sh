#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Listes noires de l'université de Toulouse — filtrage de contenu par catégories.
#
# POURQUOI CETTE SOURCE. Les catégories livrées avec Bastion comptaient 18 domaines
# pour « adulte », 4 pour « streaming », 2 pour « malware ». Vingt-quatre au total :
# un échantillon, pas un filtre. Les activer aurait affiché « Actif » dans la console
# sur un réseau qui ne filtrait rien — le pire des affichages.
# La base de Toulouse (dsi.ut-capitole.fr) est la référence française, celle
# qu'utilise Alcasar, maintenue, catégorisée finement, et HÉBERGÉE EN FRANCE — ce qui
# compte ici, contrairement à la liste publicitaire qui vient de GitHub.
#
# COMMENT ON SAIT QU'IL Y A DU NOUVEAU. Le site ne publie aucun fichier d'horodatage :
# « blacklists.tar.gz.md5 » et « update » renvoient tous deux 404 (vérifié). On se
# fonde donc sur l'en-tête « Last-Modified » de l'archive, relevé par une requête HEAD
# — quelques octets, contre 25 Mo pour un téléchargement.
set -u

BASE=/var/lib/bastion/blacklist
ARCHIVE="$BASE/blacklists.tar.gz"
EXTRAIT="$BASE/extrait"
ETAT="$BASE/etat.env"
PROG="$BASE/progression.env"
SORTIE=/etc/dnsmasq.d/proxyfibre-blacklist.conf
URL="${BLACKLIST_SOURCE:-https://dsi.ut-capitole.fr/blacklists/download/blacklists.tar.gz}"

mkdir -p "$BASE" 2>/dev/null

# IP vers laquelle un domaine bloqué est renvoyé : la passerelle, qui sert la page
# d'information. Lue dans la configuration réelle, jamais codée en dur.
LAN_IP=$(sed -n 's/^LAN_IP="\{0,1\}\([^"]*\)"\{0,1\}/\1/p' /etc/proxyfibre/net.env 2>/dev/null | head -1)
[ -n "$LAN_IP" ] || LAN_IP=192.168.182.1

progression() { # etape pct message
    { echo "etape=$1"; echo "pct=$2"; echo "message=$3"; echo "horodatage=$(date +%s)"; } > "$PROG" 2>/dev/null
    chmod 644 "$PROG" 2>/dev/null
}

json_echap() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

# Catégories retenues, dans pf_settings. Vide = toutes.
categories_choisies() {
    mysql -N radius -e "SELECT v FROM pf_settings WHERE k='blacklist_cats' LIMIT 1" 2>/dev/null | head -1
}

# Construit la liste dnsmasq à partir de l'archive DÉJÀ extraite, puis recharge le
# résolveur. Appelée par « update » et par « rebuild ».
#
# ── CE QUI EST APPRIS ICI, ET QUI A COÛTÉ UNE PANNE ─────────────────────────
# La première version prenait TOUTES les catégories. L'archive de Toulouse n'est pas
# une liste de choses à bloquer : c'est une CLASSIFICATION. On y trouve « press »,
# « bank », « mail », « jobsearch »… et « update », les serveurs de mise à jour
# Windows et Debian. Pire, « liste_blanche » est une liste d'EXCEPTIONS, qui se
# retrouvait employée comme liste de blocage.
# Résultat mesuré sur le serveur : 10 477 907 domaines, 489 Mo de configuration,
# dnsmasq passé de 11 Mo à 1 032 Mo — et service-public.fr comme debian.org
# renvoyés vers la page de blocage.
# On ne prend donc QUE les catégories choisies, et l'on refuse explicitement celles
# qui n'ont rien à faire dans un filtre.
JAMAIS="liste_blanche exceptions_liste_bu liste_bu update reaffected special"

construire() { # date_distante taille_distante
    _dist="$1"; _taille="$2"
    cats=$(categories_choisies)
    racine=$(find "$EXTRAIT" -maxdepth 2 -name blacklists -type d 2>/dev/null | head -1)
    [ -n "$racine" ] || racine="$EXTRAIT"

    tmp=$(mktemp)
    {
      echo "# Bastion — listes noires (université de Toulouse), généré automatiquement."
      echo "# Ne pas éditer : régénéré à chaque mise à jour depuis la console."
    } > "$tmp"
    total=0; retenues=""
    for d in "$racine"/*/; do
        [ -d "$d" ] || continue
        c=$(basename "$d")
        [ -f "$d/domains" ] || continue
        # Catégories interdites de filtrage, quoi qu'on ait coché.
        printf '%s\n' $JAMAIS | grep -qx "$c" && continue
        if [ -n "$cats" ]; then
            printf '%s' "$cats" | tr ',' '\n' | grep -qx "$c" || continue
        fi
        # « address=/x/IP » et non un fichier hosts : cette forme couvre AUSSI les
        # sous-domaines. Un fichier hosts ne bloquerait que « exemple.fr » en laissant
        # passer « www.exemple.fr » — autant ne rien filtrer.
        n=$(sed -e 's/[[:space:]]//g' -e '/^#/d' -e '/^$/d' "$d/domains" 2>/dev/null \
            | awk -v ip="$LAN_IP" '/^[a-z0-9]/{print "address=/" $0 "/" ip}' | tee -a "$tmp" | wc -l)
        total=$(( total + n ))
        retenues="$retenues$c:$n,"
    done

    progression cours 88 "Rechargement du résolveur…"
    install -m644 "$tmp" "$SORTIE"; rm -f "$tmp"
    if systemctl restart dnsmasq >/dev/null 2>&1 && sleep 3 && systemctl is-active --quiet dnsmasq; then
        # On ne se contente pas de « le service tourne » : on VÉRIFIE qu'un domaine
        # légitime résout encore. Une liste trop large peut laisser dnsmasq actif tout
        # en rendant le réseau inutilisable — c'est exactement ce qui est arrivé.
        if ! dig +short +time=4 +tries=1 @"$LAN_IP" debian.org 2>/dev/null | grep -qE '^[0-9]'; then
            rm -f "$SORTIE"; systemctl restart dnsmasq >/dev/null 2>&1
            progression echec 0 "Liste retiree : la resolution normale ne repondait plus"
            return 1
        fi
        ts=$(date -d "$_dist" +%s 2>/dev/null || date +%s)
        { echo "INSTALLE_DATE=$ts"; echo "INSTALLE_LE=$(date +%s)"
          echo "DOMAINES=$total"; echo "CATEGORIES=$retenues"
          echo "DISTANT_DATE=$ts"; echo "DISTANT_TAILLE=$_taille"; } > "$ETAT"
        chmod 644 "$ETAT"
        progression fini 100 "$total domaines filtrés"
    else
        # dnsmasq refuse de démarrer avec cette liste : on la retire et on remet le
        # résolveur en marche. Un filtrage absent vaut mieux qu'un réseau sans DNS.
        rm -f "$SORTIE"; systemctl restart dnsmasq >/dev/null 2>&1
        progression echec 0 "dnsmasq a refusé la liste — filtrage retiré, résolution rétablie"
        return 1
    fi
}

case "${1:-}" in

  check)
    # Requête HEAD seule : on veut la date, pas les 25 Mo.
    entetes=$(curl -fsSLI --max-time 30 "$URL" 2>/dev/null)
    dist=$(printf '%s' "$entetes" | sed -n 's/^[Ll]ast-[Mm]odified: *//p' | tr -d '\r' | tail -1)
    taille=$(printf '%s' "$entetes" | sed -n 's/^[Cc]ontent-[Ll]ength: *//p' | tr -d '\r' | tail -1)
    [ -n "$dist" ] || { echo "source injoignable"; exit 1; }
    ts=$(date -d "$dist" +%s 2>/dev/null || echo 0)
    { grep -v '^DISTANT_' "$ETAT" 2>/dev/null
      echo "DISTANT_DATE=$ts"; echo "DISTANT_TAILLE=${taille:-0}"; } > "$ETAT.tmp"
    mv "$ETAT.tmp" "$ETAT"; chmod 644 "$ETAT"
    echo "distant: $dist (${taille:-0} octets)"
    ;;

  update)
    [ -f "$PROG" ] && grep -q '^etape=cours' "$PROG" 2>/dev/null && { echo "deja en cours"; exit 1; }
    progression cours 1 "Interrogation de la source…"

    entetes=$(curl -fsSLI --max-time 30 "$URL" 2>/dev/null)
    attendu=$(printf '%s' "$entetes" | sed -n 's/^[Cc]ontent-[Ll]ength: *//p' | tr -d '\r' | tail -1)
    dist=$(printf '%s' "$entetes" | sed -n 's/^[Ll]ast-[Mm]odified: *//p' | tr -d '\r' | tail -1)
    [ -n "$attendu" ] || { progression echec 0 "Source injoignable"; exit 1; }

    # TÉLÉCHARGEMENT AVEC PROGRESSION RÉELLE. On ne lit pas la barre de curl — son
    # format varie et se parse mal — on compare la TAILLE DU FICHIER à celle annoncée
    # par l'en-tête. C'est la seule mesure qui ne mente pas, et elle marche quel que
    # soit l'outil de téléchargement.
    rm -f "$ARCHIVE.part"
    curl -fsSL --max-time 900 "$URL" -o "$ARCHIVE.part" &
    pid=$!
    while kill -0 "$pid" 2>/dev/null; do
        recu=$(stat -c%s "$ARCHIVE.part" 2>/dev/null || echo 0)
        pct=$(( recu * 60 / attendu ))
        [ "$pct" -gt 60 ] && pct=60
        progression cours "$pct" "Téléchargement — $(( recu / 1048576 )) Mo sur $(( attendu / 1048576 )) Mo"
        sleep 1
    done
    wait "$pid" 2>/dev/null || { progression echec 0 "Téléchargement interrompu"; rm -f "$ARCHIVE.part"; exit 1; }
    recu=$(stat -c%s "$ARCHIVE.part" 2>/dev/null || echo 0)
    # Contrôle de taille : une coupure réseau laisse un fichier tronqué que « tar »
    # signalerait mal, et l'on se retrouverait avec un filtrage partiel sans le savoir.
    [ "$recu" -eq "$attendu" ] || { progression echec 0 "Archive incomplète ($recu / $attendu octets)"; rm -f "$ARCHIVE.part"; exit 1; }
    mv "$ARCHIVE.part" "$ARCHIVE"

    progression cours 65 "Extraction de l'archive…"
    rm -rf "$EXTRAIT.new"; mkdir -p "$EXTRAIT.new"
    tar -xzf "$ARCHIVE" -C "$EXTRAIT.new" 2>/dev/null || { progression echec 0 "Archive illisible"; exit 1; }
    rm -rf "$EXTRAIT"; mv "$EXTRAIT.new" "$EXTRAIT"

    construire "$dist" "$attendu"
    ;;

  rebuild)
    # Reconstruire SANS retélécharger : changer de catégories ne justifie pas de
    # reprendre 25 Mo. L'archive extraite est déjà là.
    [ -d "$EXTRAIT" ] || { echo "aucune archive extraite — lancer 'update' d'abord" >&2; exit 1; }
    . "$ETAT" 2>/dev/null || true
    progression cours 70 "Reconstruction depuis l'archive locale…"
    construire "" "${DISTANT_TAILLE:-0}"
    ;;

  state)
    . "$ETAT" 2>/dev/null || true
    etape=""; pct=0; message=""
    [ -f "$PROG" ] && . "$PROG" 2>/dev/null
    maj=0
    [ "${DISTANT_DATE:-0}" -gt "${INSTALLE_DATE:-0}" ] 2>/dev/null && maj=1
    printf '{"installe_date":%s,"installe_le":%s,"domaines":%s,"categories":"%s",' \
        "${INSTALLE_DATE:-0}" "${INSTALLE_LE:-0}" "${DOMAINES:-0}" "$(json_echap "${CATEGORIES:-}")"
    printf '"distant_date":%s,"distant_taille":%s,"maj":%s,' \
        "${DISTANT_DATE:-0}" "${DISTANT_TAILLE:-0}" "$maj"
    printf '"etape":"%s","pct":%s,"message":"%s"}\n' \
        "$(json_echap "${etape:-}")" "${pct:-0}" "$(json_echap "${message:-}")"
    ;;

  categories)
    # Catégories réellement présentes dans l'archive extraite, avec leur volume.
    racine=$(find "$EXTRAIT" -maxdepth 2 -name blacklists -type d 2>/dev/null | head -1)
    [ -n "$racine" ] || racine="$EXTRAIT"
    for d in "$racine"/*/; do
        [ -f "$d/domains" ] || continue
        printf '%s\t%s\n' "$(basename "$d")" "$(wc -l < "$d/domains" 2>/dev/null)"
    done | sort -k2 -rn
    ;;

  *)
    echo "Usage: $0 check|update|rebuild|state|categories" >&2
    exit 1
    ;;
esac
