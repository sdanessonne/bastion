#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Sortie Internet par tunnel (WireGuard), réservée à un GROUPE d'agents.
#
# ── LE BESOIN ─────────────────────────────────────────────────────────────────
# Consulter en source ouverte le profil d'un mis en cause, un site surveillé, un
# forum : l'adresse IP du commissariat s'affiche alors dans les journaux du site
# consulté. Ce n'est pas anodin — elle est publique, attribuable, et sa présence
# répétée sur une page renseigne la personne visée.
#
# ── POURQUOI UN GROUPE, ET PAS TOUT LE RÉSEAU ─────────────────────────────────
# Faire sortir TOUT le commissariat par un tunnel casserait le jour même les
# applications métier et les accès ministériels qui filtrent par adresse source.
# Le tunnel est donc réservé aux postes qui en ont l'usage ; les autres sortent
# comme avant, sans rien changer pour eux.
#
# ── LE POINT LE PLUS IMPORTANT : LA PANNE ─────────────────────────────────────
# Si le tunnel tombe, le comportement par défaut du routage serait de reprendre
# la route normale — c'est-à-dire de sortir EN CLAIR, sous l'adresse du
# commissariat, sans que l'agent voie la moindre différence à l'écran. Il
# poursuivrait sa recherche en croyant être couvert. C'est le pire résultat
# possible : pire que pas de tunnel du tout, parce qu'il produit une fausse
# assurance.
#
# Le trafic du groupe est donc BLOQUÉ tant que le tunnel n'est pas établi. Perdre
# l'accès se voit immédiatement ; sortir en clair sans le savoir, non.
#
# Usage :
#   vpn-ctl.sh import <fichier.conf>   installe une configuration WireGuard
#   vpn-ctl.sh up | down               monte / démonte le tunnel
#   vpn-ctl.sh state                   état complet, en JSON
#   vpn-ctl.sh check                   vérifie l'adresse de sortie RÉELLE
#   vpn-ctl.sh client add|del <ip>     bascule un poste dans le tunnel
#   vpn-ctl.sh clients                 liste les postes concernés
set -uo pipefail

IF=bastionvpn
CONF="/etc/wireguard/${IF}.conf"
TABLE=51820          # table de routage dédiée
MARK=0x51820         # marque appliquée aux paquets du groupe
NFT_TABLE="inet bastion_vpn"
SET=vpnclients

json_esc() { printf '%s' "${1:-}" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n'; }
err() { echo "ERREUR: $*" >&2; exit 1; }

# ── Le tunnel est-il RÉELLEMENT opérationnel ? ───────────────────────────────
# « L'interface existe » ne suffit pas : WireGuard crée l'interface même quand le
# pair ne répond pas. Le seul signe fiable est une POIGNÉE DE MAIN récente. Sans
# ce contrôle, un tunnel mort passerait pour actif et le groupe croirait sortir
# par le tunnel alors qu'il serait simplement bloqué — ou pire, en clair.
tunnel_ok() {
    ip link show "$IF" >/dev/null 2>&1 || return 1
    local hs
    hs=$(wg show "$IF" latest-handshakes 2>/dev/null | awk '{print $2; exit}')
    [ -n "${hs:-}" ] && [ "$hs" != "0" ] || return 1
    # WireGuard renouvelle la poignée de main toutes les ~2 min ; au-delà de
    # 5 min sans nouvelle, le pair ne répond plus.
    [ $(( $(date +%s) - hs )) -lt 300 ]
}

handshake_age() {
    local hs
    hs=$(wg show "$IF" latest-handshakes 2>/dev/null | awk '{print $2; exit}')
    if [ -z "${hs:-}" ] || [ "$hs" = "0" ]; then echo -1; else echo $(( $(date +%s) - hs )); fi
}

# ── Règles réseau ────────────────────────────────────────────────────────────
regles_poser() {
    # Table nftables dédiée : elle se supprime d'un bloc, sans toucher aux règles
    # du portail captif ni au repli sur panne général.
    nft list table $NFT_TABLE >/dev/null 2>&1 || nft add table $NFT_TABLE
    nft list set $NFT_TABLE $SET >/dev/null 2>&1 || \
        nft add set $NFT_TABLE $SET '{ type ipv4_addr; flags interval; }'

    nft flush chain $NFT_TABLE marquage 2>/dev/null || \
        nft add chain $NFT_TABLE marquage '{ type route hook output priority mangle; policy accept; }' 2>/dev/null
    nft flush chain $NFT_TABLE prerouting 2>/dev/null || \
        nft add chain $NFT_TABLE prerouting '{ type filter hook prerouting priority mangle; policy accept; }'
    nft add rule $NFT_TABLE prerouting ip saddr @$SET meta mark set $MARK

    # ── LE VERROU ────────────────────────────────────────────────────────────
    # Tant que le tunnel n'est pas établi, le trafic du groupe est REJETÉ. Le
    # rejet est explicite (et non un abandon silencieux) pour que le navigateur
    # affiche une erreur immédiate : un agent doit comprendre en une seconde que
    # sa sortie protégée est indisponible, pas attendre un délai d'expiration en
    # se demandant si la page charge.
    nft flush chain $NFT_TABLE verrou 2>/dev/null || \
        nft add chain $NFT_TABLE verrou '{ type filter hook forward priority filter - 5; policy accept; }'
    if ! tunnel_ok; then
        nft add rule $NFT_TABLE verrou ip saddr @$SET oifname != "$IF" \
            counter reject with icmp type admin-prohibited
    fi

    # Traduction d'adresse à la sortie du tunnel.
    nft list table ip bastion_vpn_nat >/dev/null 2>&1 || nft add table ip bastion_vpn_nat
    nft flush chain ip bastion_vpn_nat post 2>/dev/null || \
        nft add chain ip bastion_vpn_nat post '{ type nat hook postrouting priority srcnat - 5; policy accept; }'
    nft add rule ip bastion_vpn_nat post oifname "$IF" masquerade

    # Routage par marque.
    ip rule show 2>/dev/null | grep -q "fwmark $MARK" || \
        ip rule add fwmark $MARK table $TABLE priority 1000 2>/dev/null || true
    if ip link show "$IF" >/dev/null 2>&1; then
        ip route replace default dev "$IF" table $TABLE 2>/dev/null || true
    fi
}

regles_retirer() {
    nft delete table $NFT_TABLE 2>/dev/null || true
    nft delete table ip bastion_vpn_nat 2>/dev/null || true
    ip rule del fwmark $MARK table $TABLE 2>/dev/null || true
    ip route flush table $TABLE 2>/dev/null || true
}

cmd="${1:-state}"

case "$cmd" in

import)
    # La configuration est fournie par le fournisseur (Proton : « WireGuard
    # configuration »). Elle contient une CLÉ PRIVÉE : le fichier est donc écrit
    # en 600, hors de tout répertoire servi par le web.
    src="${2:-}"

    # ── LE CHEMIN EST BORNÉ, ET CE N'EST PAS UN DÉTAIL ───────────────────────
    # La console appelle cette commande via sudo. Si le chemin était libre,
    # www-data pourrait faire recopier N'IMPORTE QUEL fichier lisible par root
    # vers /etc/wireguard/ — les contrôles de forme ci-dessous limitent les
    # dégâts, mais s'appuyer dessus reviendrait à faire reposer la sécurité sur
    # une expression régulière. Un dépôt web n'a besoin que d'un seul chemin.
    case "$src" in
        /run/bastion/vpn-import.conf) : ;;
        /*) [ -t 0 ] || err "chemin refusé hors dépôt de la console" ;;
        *)  err "chemin absolu requis" ;;
    esac

    [ -r "$src" ] || err "fichier illisible : $src"
    grep -q '^\[Interface\]' "$src" || err "ce fichier n'est pas une configuration WireGuard"
    grep -q '^\s*PrivateKey' "$src"  || err "configuration sans clé privée"
    grep -q '^\[Peer\]' "$src"       || err "configuration sans pair (section [Peer])"
    install -d -m 700 /etc/wireguard
    install -m 600 "$src" "$CONF"
    # « AllowedIPs = 0.0.0.0/0 » ferait installer par wg-quick une route par
    # défaut GLOBALE : tout le commissariat basculerait dans le tunnel, ce que
    # l'on veut précisément éviter. On neutralise donc la gestion de route de
    # wg-quick et on pose nous-mêmes la route dans une table dédiée.
    grep -q '^\s*Table\s*=' "$CONF" || sed -i '/^\[Interface\]/a Table = off' "$CONF"

    # ── LA LIGNE « DNS = » EST RETIRÉE, ET C'EST IMPORTANT ───────────────────
    # Proton place « DNS = 10.2.0.1 » dans ses configurations. wg-quick
    # l'interprète comme un ordre de RÉÉCRIRE LE RÉSOLVEUR DU SYSTÈME : il
    # remplacerait /etc/resolv.conf pour la machine entière.
    #
    # Sur cette passerelle, c'est dnsmasq qui résout pour tout le commissariat,
    # avec le filtrage de contenu et le walled garden. Laisser wg-quick prendre
    # la main casserait la résolution de TOUS les postes — y compris ceux qui
    # n'ont rien à voir avec le tunnel — et le filtrage avec elle. Et si
    # « resolvconf » est absent, wg-quick échoue simplement au montage, sans
    # que le message dise pourquoi.
    #
    # Conséquence assumée, déjà documentée dans la console : les postes du
    # groupe résolvent par le résolveur local. Le tunnel masque la connexion,
    # pas la résolution du nom.
    if grep -qi '^\s*DNS\s*=' "$CONF"; then
        sed -i 's/^\s*DNS\s*=/# DNS (neutralisé par Bastion : dnsmasq reste le résolveur) =/I' "$CONF"
    fi

    # Le fichier déposé par la console est EFFACÉ tout de suite. Il contient la
    # clé privée du tunnel ; le laisser dans un répertoire accessible au serveur
    # web reviendrait à conserver une seconde copie du secret, au même endroit
    # que le code qui l'a reçue.
    case "$src" in /run/bastion/vpn-import.conf) rm -f "$src" ;; esac

    echo "configuration importée ($(grep -c '^\[Peer\]' "$CONF") pair(s))"
    ;;

up)
    [ -r "$CONF" ] || err "aucune configuration : utilisez « import » d'abord"
    command -v wg-quick >/dev/null 2>&1 || err "wireguard-tools absent"
    ip link show "$IF" >/dev/null 2>&1 || wg-quick up "$IF" >/dev/null 2>&1 || err "montage impossible"
    # On attend la première poignée de main : déclarer « actif » sur la seule
    # existence de l'interface reviendrait à promettre une protection avant
    # qu'elle n'existe.
    for _ in $(seq 1 15); do tunnel_ok && break; sleep 1; done
    regles_poser
    if tunnel_ok; then echo "tunnel actif"; else
        echo "ATTENTION: interface montée mais AUCUNE poignée de main — trafic du groupe BLOQUÉ" >&2
        exit 1
    fi
    ;;

down)
    # Les règles sont retirées AVANT l'interface. Dans l'ordre inverse, il
    # existerait un instant où le tunnel est mort et le verrou déjà levé : le
    # trafic du groupe sortirait en clair pendant ce laps de temps.
    regles_retirer
    wg-quick down "$IF" >/dev/null 2>&1 || true
    echo "tunnel arrêté"
    ;;

apply)
    # Rejoué périodiquement : réévalue le verrou selon l'état RÉEL du tunnel.
    [ -r "$CONF" ] || exit 0
    regles_poser
    tunnel_ok && echo "actif" || echo "bloque"
    ;;

client)
    sub="${2:-}"; ip4="${3:-}"
    printf '%s' "$ip4" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || err "adresse IP invalide"
    nft list set $NFT_TABLE $SET >/dev/null 2>&1 || regles_poser
    case "$sub" in
        add) nft add element $NFT_TABLE $SET "{ $ip4 }" 2>/dev/null && echo "ajoute: $ip4" || echo "deja present: $ip4" ;;
        del) nft delete element $NFT_TABLE $SET "{ $ip4 }" 2>/dev/null && echo "retire: $ip4" || echo "absent: $ip4" ;;
        *)   err "usage: client add|del <ip>" ;;
    esac
    ;;

clients)
    nft list set $NFT_TABLE $SET 2>/dev/null | sed -n 's/.*elements = {\(.*\)}.*/\1/p' | tr -d ' ' | tr ',' '\n' | grep -v '^$' || true
    ;;

check)
    # ── VÉRIFIER, PAS SUPPOSER ───────────────────────────────────────────────
    # Une poignée de main récente prouve que le tunnel vit, pas que le trafic
    # sort par lui. Seule une requête EFFECTUÉE PAR L'INTERFACE du tunnel le
    # démontre. On compare les deux adresses : si elles sont identiques, le
    # tunnel ne sert à rien et il faut le savoir.
    tunnel_ok || err "tunnel inactif — rien à vérifier"
    direct=$(curl -s --max-time 8 https://api.ipify.org 2>/dev/null || echo "")
    parvpn=$(curl -s --max-time 8 --interface "$IF" https://api.ipify.org 2>/dev/null || echo "")
    if [ -z "$parvpn" ]; then
        echo "ECHEC: aucune reponse par le tunnel"; exit 1
    elif [ "$parvpn" = "$direct" ]; then
        echo "ECHEC: adresse identique ($parvpn) — le trafic NE PASSE PAS par le tunnel"; exit 1
    else
        echo "OK: sortie $parvpn (adresse directe: ${direct:-inconnue})"
    fi
    ;;

state)
    conf=false;  [ -r "$CONF" ] && conf=true
    iface=false; ip link show "$IF" >/dev/null 2>&1 && iface=true
    actif=false; tunnel_ok && actif=true
    age=$(handshake_age)
    endpoint=$(wg show "$IF" endpoints 2>/dev/null | awk '{print $2; exit}')
    tx=$(wg show "$IF" transfer 2>/dev/null | awk '{print $3; exit}')
    rx=$(wg show "$IF" transfer 2>/dev/null | awk '{print $2; exit}')
    n=$(nft list set $NFT_TABLE $SET 2>/dev/null | grep -c '\.' || true)
    printf '{"config":%s,"interface":%s,"actif":%s,"handshake_s":%s,"endpoint":"%s","rx":%s,"tx":%s,"postes":%s}\n' \
        "$conf" "$iface" "$actif" "${age:--1}" "$(json_esc "${endpoint:-}")" "${rx:-0}" "${tx:-0}" "${n:-0}"
    ;;

*)
    err "commande inconnue : $cmd"
    ;;
esac
