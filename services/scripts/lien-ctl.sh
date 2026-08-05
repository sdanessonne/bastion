#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Liaison inter-sites : rattache cette passerelle au concentrateur de la flotte.
#
# ── POURQUOI UN TUNNEL SORTANT ────────────────────────────────────────────────
# Chaque commissariat est derrière une box opérateur : pas d'adresse publique
# stable, et souvent pas d'adresse publique du tout. La console de flotte, elle,
# interroge chaque passerelle sur son port 8443. Sans tunnel, il faudrait ouvrir
# un port sur chaque box — c'est-à-dire publier la console d'administration sur
# Internet, exactement ce qu'on refuse par ailleurs.
#
# Le tunnel part donc DU commissariat VERS le concentrateur, jamais l'inverse.
# Aucune box à configurer, une adresse dynamique n'y change rien, et un seul
# point public existe dans toute la flotte : le concentrateur.
#
# ── CE QUI PASSE DANS LE TUNNEL, ET RIEN D'AUTRE ──────────────────────────────
# « AllowedIPs » se limite au réseau de gestion (10.90.0.0/24). La navigation des
# agents ne l'emprunte pas, et la route par défaut n'est pas touchée : une panne
# du concentrateur ne coupe pas Internet au commissariat. C'est aussi ce qui le
# distingue du VPN de sortie (« bastionvpn »), qui a un autre rôle et une autre
# interface — les deux coexistent sans se gêner.
#
# ── DEUX RÔLES, UNE SEULE INTERFACE ───────────────────────────────────────────
# « site »      : le cas courant. Compose vers le principal du département.
# « principal » : UN serveur par département. Il écoute, porte l'adresse 10.90.0.1
#                 et connaît la clé publique de chaque site rattaché. C'est le seul
#                 de la flotte à avoir besoin d'un point de contact public.
#
# Usage : lien-ctl.sh state | init | role <site|principal> | config | hub-config
#                   | up | down | check
#         « config » et « hub-config » lisent leurs paramètres sur l'ENTRÉE
#         STANDARD : une clé publique passée en argument se retrouverait dans la
#         liste des processus et dans l'historique du shell.
set -uo pipefail

IF=bastionlink
CONF="/etc/wireguard/${IF}.conf"
KEYDIR=/etc/proxyfibre/lien
PRIV="$KEYDIR/prive.key"
PUB="$KEYDIR/publique.key"
ROLEF="$KEYDIR/role"
RESEAU="10.90.0.0/24"
HUB_ADDR="10.90.0.1"

role_actuel() { [ -s "$ROLEF" ] && cat "$ROLEF" || echo site; }

[ "$(id -u)" = "0" ] || { echo "ERREUR : à lancer en root." >&2; exit 1; }
command -v wg >/dev/null 2>&1 || { echo "ERREUR : wireguard-tools absent (apt install wireguard-tools)." >&2; exit 2; }

json_esc() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

# Adresse de gestion valide ? On refuse tout ce qui sort du réseau prévu : une
# adresse hors plage donnerait un tunnel qui monte et ne mène nulle part.
adresse_ok() {
    case "$1" in
        10.90.0.*) ;;
        *) return 1 ;;
    esac
    local d=${1##*.}
    case "$d" in ''|*[!0-9]*) return 1 ;; esac
    [ "$d" -ge 2 ] && [ "$d" -le 254 ]   # .1 est réservé au concentrateur
}

cle_ok() {   # une clé WireGuard : 44 caractères base64 finissant par « = »
    printf '%s' "$1" | grep -qE '^[A-Za-z0-9+/]{43}=$'
}

point_ok() { # hôte:port — nom ou adresse, port 1-65535
    printf '%s' "$1" | grep -qE '^[A-Za-z0-9._-]+:[0-9]{1,5}$' || return 1
    local p=${1##*:}
    [ "$p" -ge 1 ] && [ "$p" -le 65535 ]
}

case "${1:-state}" in

init)
    # Paire de clés. La PRIVÉE ne quitte jamais cette machine et n'est jamais
    # affichée ; seule la publique est communiquée au concentrateur.
    install -d -m 700 "$KEYDIR"
    if [ ! -s "$PRIV" ]; then
        umask 077
        wg genkey > "$PRIV" || { echo "ECHEC: generation de la cle"; exit 3; }
        chmod 600 "$PRIV"
        wg pubkey < "$PRIV" > "$PUB" || { echo "ECHEC: derivation de la cle publique"; exit 3; }
        chmod 644 "$PUB"
        echo "paire de cles creee"
    else
        # Réparation : une clé publique absente ou incohérente se redérive sans
        # toucher à la privée — regénérer la paire couperait le site de la flotte.
        wg pubkey < "$PRIV" > "$PUB"; chmod 644 "$PUB"
        echo "paire de cles deja presente"
    fi
    cat "$PUB"
    ;;

role)
    r="${2:-}"
    case "$r" in
        site|principal) ;;
        *) echo "ECHEC: role attendu « site » ou « principal »"; exit 4 ;;
    esac
    install -d -m 700 "$KEYDIR"
    ancien=$(role_actuel)
    printf '%s\n' "$r" > "$ROLEF"; chmod 644 "$ROLEF"
    # Changer de rôle rend l'ancienne configuration caduque : un serveur qui devient
    # principal ne doit pas garder le tunnel sortant qu'il avait comme site, et
    # inversement. On la retire plutôt que de la laisser induire en erreur.
    if [ "$ancien" != "$r" ] && [ -s "$CONF" ]; then
        wg-quick down "$IF" >/dev/null 2>&1 || true
        systemctl disable "wg-quick@${IF}" >/dev/null 2>&1 || true
        rm -f "$CONF"
        echo "OK: role change en « $r » — l'ancienne configuration a ete retiree, a refaire"
    else
        echo "OK: role « $r »"
    fi
    ;;

hub-config)
    # Configuration du PRINCIPAL. Entrée standard :
    #   ligne 1 : port d'écoute
    #   lignes suivantes : <clé publique>|<adresse 10.90.0.N>|<nom du site>
    # La liste est REÉCRITE en entier à chaque fois : la console est la seule source
    # de vérité, et une suppression doit disparaître d'ici aussi.
    [ -s "$PRIV" ] || { echo "ECHEC: aucune cle — lancez « init » d'abord"; exit 5; }
    read -r port || true
    port=$(printf '%s' "${port:-}" | tr -cd '0-9')
    { [ -n "$port" ] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ]; } \
        || { echo "ECHEC: port d'ecoute invalide"; exit 4; }

    peers=""; n=0
    while IFS= read -r ligne; do
        ligne=$(printf '%s' "$ligne" | tr -d '\r')
        [ -n "$ligne" ] || continue
        pk=${ligne%%|*}; reste=${ligne#*|}
        ad=${reste%%|*}; nom=${reste#*|}
        pk=$(printf '%s' "$pk" | tr -d ' ')
        ad=$(printf '%s' "$ad" | tr -d ' ')
        # Un site mal saisi est IGNORÉ et signalé : l'accepter donnerait un
        # concentrateur qui refuse silencieusement le tunnel de ce commissariat.
        cle_ok "$pk"     || { echo "IGNORE: cle invalide pour « $nom »"; continue; }
        adresse_ok "$ad" || { echo "IGNORE: adresse invalide pour « $nom »"; continue; }
        # Le nom ne sert qu'au commentaire : on le réduit à de l'imprimable simple.
        nom=$(printf '%s' "$nom" | tr -cd 'A-Za-z0-9 ._-' | cut -c1-48)
        peers="${peers}
# ${nom:-site}
[Peer]
PublicKey = ${pk}
AllowedIPs = ${ad}/32
"
        n=$((n+1))
    done

    install -d -m 700 /etc/wireguard
    tmp=$(mktemp /etc/wireguard/.lien.XXXXXX) || exit 6
    chmod 600 "$tmp"
    {
        echo "# Bastion — concentrateur de département. Écrit par lien-ctl.sh, ne pas modifier à la main."
        echo "[Interface]"
        echo "PrivateKey = $(cat "$PRIV")"
        echo "Address = ${HUB_ADDR}/24"
        echo "ListenPort = ${port}"
        printf '%s' "$peers"
    } > "$tmp"
    mv -f "$tmp" "$CONF"
    chmod 600 "$CONF"
    printf '%s\n' principal > "$ROLEF"; chmod 644 "$ROLEF"
    echo "OK: concentrateur configure sur le port ${port}, ${n} site(s) rattache(s)"
    ;;

config)
    # Paramètres sur l'entrée standard, une valeur par ligne :
    #   1. clé publique du concentrateur
    #   2. point de contact du concentrateur (hôte:port)
    #   3. adresse de CETTE passerelle dans le tunnel (10.90.0.N)
    read -r hub_pub || true
    read -r hub_pt  || true
    read -r moi     || true
    hub_pub=$(printf '%s' "${hub_pub:-}" | tr -d '\r\n ')
    hub_pt=$(printf '%s'  "${hub_pt:-}"  | tr -d '\r\n ')
    moi=$(printf '%s'     "${moi:-}"     | tr -d '\r\n ')

    cle_ok "$hub_pub"   || { echo "ECHEC: cle publique du concentrateur invalide"; exit 4; }
    point_ok "$hub_pt"  || { echo "ECHEC: point de contact invalide (attendu hote:port)"; exit 4; }
    adresse_ok "$moi"   || { echo "ECHEC: adresse de gestion invalide (attendu 10.90.0.2 a 10.90.0.254)"; exit 4; }

    [ -s "$PRIV" ] || { echo "ECHEC: aucune cle — lancez « init » d'abord"; exit 5; }

    install -d -m 700 /etc/wireguard
    tmp=$(mktemp /etc/wireguard/.lien.XXXXXX) || exit 6
    chmod 600 "$tmp"
    {
        echo "# Bastion — liaison inter-sites. Écrit par lien-ctl.sh, ne pas modifier à la main."
        echo "[Interface]"
        echo "PrivateKey = $(cat "$PRIV")"
        echo "Address = ${moi}/24"
        # PAS de ligne « DNS = » : wg-quick réécrirait le resolv.conf du serveur,
        # et la passerelle perdrait sa propre résolution — constaté sur ce projet
        # avec le VPN de sortie.
        echo
        echo "[Peer]"
        echo "PublicKey = ${hub_pub}"
        # Le tunnel ne transporte QUE le réseau de gestion. Sans cette borne, la
        # navigation de tout le commissariat y passerait.
        echo "AllowedIPs = ${RESEAU}"
        echo "Endpoint = ${hub_pt}"
        # Indispensable derrière une box : sans trafic régulier, la traduction
        # d'adresses de la box oublie la session et le concentrateur ne peut plus
        # joindre le site — le tunnel paraîtrait monté et ne répondrait plus.
        echo "PersistentKeepalive = 25"
    } > "$tmp"
    mv -f "$tmp" "$CONF"
    chmod 600 "$CONF"
    echo "OK: liaison configuree vers ${hub_pt}, adresse ${moi}"
    ;;

up)
    [ -s "$CONF" ] || { echo "ECHEC: liaison non configuree"; exit 7; }
    wg-quick up "$IF" 2>&1 | sed 's/^/  /'
    systemctl enable "wg-quick@${IF}" >/dev/null 2>&1 || true
    ip -o addr show dev "$IF" >/dev/null 2>&1 && echo "OK: liaison montee" || { echo "ECHEC: interface absente apres montage"; exit 8; }
    ;;

down)
    systemctl disable "wg-quick@${IF}" >/dev/null 2>&1 || true
    wg-quick down "$IF" 2>&1 | sed 's/^/  /' || true
    echo "OK: liaison arretee"
    ;;

check)
    ip -o addr show dev "$IF" >/dev/null 2>&1 || { echo "ECHEC: liaison non montee"; exit 9; }

    if [ "$(role_actuel)" = "principal" ]; then
        # Sur le concentrateur, la question n'est pas « est-ce que je joins
        # quelqu'un » mais « qui me joint ». Un site déclaré qui n'a jamais
        # échangé est le cas à voir : la déclaration seule ne prouve rien.
        tot=0; vus=0; muets=""
        while read -r pk hs; do
            [ -n "$pk" ] || continue
            tot=$((tot+1))
            if [ "${hs:-0}" -gt 0 ] 2>/dev/null; then vus=$((vus+1))
            else muets="$muets $(printf '%s' "$pk" | cut -c1-8)…"; fi
        done <<EOF2
$(wg show "$IF" latest-handshakes 2>/dev/null)
EOF2
        if [ "$tot" -eq 0 ]; then
            echo "PARTIEL: concentrateur monte, aucun site declare"
        elif [ "$vus" -eq "$tot" ]; then
            echo "OK: $vus site(s) sur $tot ont echange avec le concentrateur"
        else
            echo "PARTIEL: $vus site(s) sur $tot ont echange — jamais vus :$muets"
            exit 10
        fi
        exit 0
    fi

    # Côté site : le tunnel n'est utile que si le concentrateur RÉPOND. Une
    # interface montée ne prouve rien — WireGuard n'établit pas de session tant
    # qu'aucun paquet n'a circulé, et un pare-feu en face laisse tout paraître normal.
    if ping -c 2 -W 3 -I "$IF" "$HUB_ADDR" >/dev/null 2>&1; then
        echo "OK: le concentrateur repond (10.90.0.1)"
    else
        hs=$(wg show "$IF" latest-handshakes 2>/dev/null | awk '{print $2}' | head -1)
        if [ -n "${hs:-}" ] && [ "$hs" -gt 0 ] 2>/dev/null; then
            echo "PARTIEL: session etablie mais 10.90.0.1 ne repond pas au ping (filtrage ?)"
        else
            echo "ECHEC: aucune session avec le concentrateur — verifiez le point de contact et la cle"
        fi
        exit 10
    fi
    ;;

state)
    configuree=false; [ -s "$CONF" ] && configuree=true
    montee=false;     ip -o addr show dev "$IF" >/dev/null 2>&1 && montee=true
    pub=""; [ -s "$PUB" ] && pub=$(cat "$PUB")
    hub=""; moi=""
    if $configuree; then
        hub=$(sed -n 's/^Endpoint *= *//p' "$CONF" | head -1)
        moi=$(sed -n 's#^Address *= *\([0-9.]*\)/.*#\1#p' "$CONF" | head -1)
    fi
    hs=0; rx=0; tx=0
    if $montee; then
        hs=$(wg show "$IF" latest-handshakes 2>/dev/null | awk '{print $2}' | head -1)
        set -- $(wg show "$IF" transfer 2>/dev/null | head -1)
        rx=${2:-0}; tx=${3:-0}
    fi
    role=$(role_actuel)
    port=""
    [ "$role" = "principal" ] && { port=$(sed -n 's/^ListenPort *= *//p' "$CONF" 2>/dev/null | head -1); moi="$HUB_ADDR"; }
    # Sur le concentrateur, l'état utile est site par site : « clé publique, dernier
    # échange ». Une liste de sites déclarés sans horodatage ne dirait pas lesquels
    # sont réellement joignables.
    pairs=""
    if $montee && [ "$role" = "principal" ]; then
        sep=""
        while read -r pk h; do
            [ -n "$pk" ] || continue
            pairs="${pairs}${sep}{\"cle\":\"$(json_esc "$pk")\",\"poignee\":${h:-0}}"
            sep=","
        done <<EOF3
$(wg show "$IF" latest-handshakes 2>/dev/null)
EOF3
    fi
    printf '{"role":"%s","configuree":%s,"montee":%s,"publique":"%s","concentrateur":"%s","adresse":"%s","port":"%s","poignee":%s,"recu":%s,"emis":%s,"pairs":[%s]}\n' \
        "$role" "$configuree" "$montee" "$(json_esc "$pub")" "$(json_esc "$hub")" "$(json_esc "$moi")" \
        "$(json_esc "${port:-}")" "${hs:-0}" "${rx:-0}" "${tx:-0}" "$pairs"
    ;;

*)
    echo "usage: lien-ctl.sh state | init | config | up | down | check" >&2; exit 2 ;;
esac
