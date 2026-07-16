#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Garde réseau de la passerelle. Trois rôles, une seule table nftables :
#
#   1. NAT       — masquerade du LAN vers le WAN (rôle historique de nat.nft).
#   2. DNS FORCÉ — tout le trafic DNS du LAN est redirigé vers NOTRE résolveur,
#                  et les résolveurs chiffrés publics (DoH/DoT) sont refusés.
#   3. REPLI     — tant que le portail captif n'a pas confirmé son démarrage,
#                  AUCUN trafic LAN→WAN ne passe.
#
# ── POURQUOI 2 ────────────────────────────────────────────────────────────────
# Le filtrage de contenu reposait entièrement sur dnsmasq, donc sur la bonne
# volonté du client à utiliser notre résolveur. Vérifié : un poste authentifié
# interrogeant 8.8.8.8 obtenait l'adresse réelle d'un domaine bloqué. Pire, cocher
# « DNS sécurisé » dans un navigateur suffisait — SANS droit administrateur.
#
# ── POURQUOI 3 ────────────────────────────────────────────────────────────────
# Vécu sur ce produit : OpenNDS s'est arrêté (deux IP détectées sur son interface)
# et le LAN a gardé un accès Internet DIRECT, sans authentification ni filtrage,
# sans que rien ne le signale. La conception était « fail-open » : perdre le portail
# OUVRAIT le réseau. On inverse — perdre le portail le FERME.
# Le repli ne touche QUE le hook « forward » : les postes gardent l'accès à la
# passerelle elle-même (portail, intranet, console), donc à la page d'explication.
#
# ── POURQUOI UN SCRIPT ET PAS UN FICHIER .nft STATIQUE ────────────────────────
# L'état du repli doit être CALCULÉ à partir de l'état réel d'OpenNDS. Un fichier
# nft statique réappliqué (redémarrage du service NAT) réinstallerait le blocage
# alors que le portail tourne : le LAN perdrait Internet sans raison.
#
# Usage : netguard.sh apply|open|close|status
set -euo pipefail

ENV_FILE=/etc/proxyfibre/net.env
[ -r "$ENV_FILE" ] && . "$ENV_FILE"
WAN_IF="${WAN_IF:-enp0s3}"
LAN_IF="${LAN_IF:-enp0s8}"
LAN_IP="${LAN_IP:-192.168.182.1}"
LAN_CIDR="${LAN_NET:-192.168.182.0}/${LAN_CIDR:-24}"
AD_DNS_IP="${AD_DNS_IP:-192.168.182.2}"
DOH_LIST="${DOH_LIST:-/etc/proxyfibre/doh-resolvers.txt}"

NFT=/usr/sbin/nft
TABLE="ip proxyfibre"

# ── Résolveurs chiffrés publics → éléments de set nftables ───────────────────
doh_elements() {
    [ -r "$DOH_LIST" ] || return 0
    sed -e 's/#.*//' -e 's/[[:space:]]//g' "$DOH_LIST" \
      | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(/[0-9]+)?$' \
      | paste -sd, -
}

# ── (Re)construction complète de la table ────────────────────────────────────
build() {
    local doh; doh="$(doh_elements || true)"
    # « add » puis « delete » : garantit un état propre même si la table n'existe pas.
    cat > /etc/proxyfibre/nat.nft <<NFT
#!/usr/sbin/nft -f
# Fichier ENGENDRÉ par netguard.sh — ne pas éditer à la main.
add table ${TABLE}
delete table ${TABLE}
table ${TABLE} {
    set dohresolvers {
        type ipv4_addr
        flags interval
        comment "Resolveurs DoH/DoT publics - filet pour les postes non geres"
$( [ -n "$doh" ] && echo "        elements = { ${doh} }" )
    }

    chain postrouting {
        type nat hook postrouting priority 100; policy accept;
        oifname "${WAN_IF}" masquerade
    }

    # DNS FORCÉ. Un poste configuré sur un résolveur externe est redirigé vers le
    # nôtre sans le savoir : le filtrage s'applique quoi qu'il configure.
    # EXCLUSIONS INDISPENSABLES :
    #  - ${LAN_IP}     : notre résolveur (dnsmasq) — le rediriger sur lui-même est inutile.
    #  - ${AD_DNS_IP}  : DNS de Samba AD. Les postes du domaine DOIVENT l'interroger
    #    (enregistrements SRV Kerberos/LDAP) ; le détourner casserait l'ouverture de
    #    session et les stratégies de groupe. Il n'ouvre AUCUNE brèche : Samba
    #    forwarde vers dnsmasq (« dns forwarder = ${LAN_IP} »), donc filtré aussi.
    # Priorité -110 et non « dstnat » (-100) : OpenNDS place SA chaîne nat prerouting
    # exactement à -100 (redirection du port 80 vers le portail). Deux chaînes de même
    # priorité sur le même hook ont un ordre indéterminé — on se place franchement avant.
    chain prerouting {
        type nat hook prerouting priority -110; policy accept;
        iifname "${LAN_IF}" ip daddr { ${LAN_IP}, ${AD_DNS_IP} } return
        iifname "${LAN_IF}" udp dport 53 counter dnat to ${LAN_IP}:53
        iifname "${LAN_IF}" tcp dport 53 counter dnat to ${LAN_IP}:53
    }

    # Priorité -150 : AVANT les chaînes d'OpenNDS (-100), donc un refus ici est sans appel.
    # Rappel nftables : le verdict « accept » d'une chaîne de base ne court-circuite PAS
    # les autres chaînes du même hook — seuls « drop » et « reject » sont définitifs.
    # C'est ce qui permet à cette chaîne de trancher malgré les « accept » d'OpenNDS.
    chain guard_fwd {
        type filter hook forward priority -150; policy accept;

        # DNS-over-TLS (853/tcp) et DNS-over-QUIC (853/udp) : aucun usage légitime ici.
        iifname "${LAN_IF}" tcp dport 853 counter reject with tcp reset comment "DoT"
        iifname "${LAN_IF}" udp dport 853 counter reject comment "DoQ"

        # DNS-over-HTTPS : indiscernable du web, on refuse les adresses des résolveurs connus.
        iifname "${LAN_IF}" ip daddr @dohresolvers tcp dport 443 counter reject with tcp reset comment "DoH"
        iifname "${LAN_IF}" ip daddr @dohresolvers udp dport 443 counter reject comment "DoH h3"
    }

    # Chaîne du repli, VIDE ici : son contenu est posé/retiré par « close »/« open »
    # selon l'état réel d'OpenNDS (voir sync_failclose).
    chain failclose {
        type filter hook forward priority -160; policy accept;
    }
}
NFT
    $NFT -f /etc/proxyfibre/nat.nft
}

# ── Repli : blocage LAN→WAN ──────────────────────────────────────────────────
is_closed() { $NFT list chain ${TABLE} failclose 2>/dev/null | grep -q 'failclose-active'; }

close() {
    is_closed && return 0
    $NFT flush chain ${TABLE} failclose 2>/dev/null || true
    # Le LAN garde l'accès à la passerelle (hook input) : seule la sortie vers le WAN
    # est coupée. « reject » plutôt que « drop » : le poste échoue tout de suite au
    # lieu d'attendre l'expiration d'un délai.
    $NFT add rule ${TABLE} failclose iifname "${LAN_IF}" oifname "${WAN_IF}" \
        counter reject comment '"failclose-active"'
    logger -t bastion-netguard "REPLI ACTIF : portail captif absent, trafic LAN->WAN coupe"
}

open() {
    is_closed || return 0
    $NFT flush chain ${TABLE} failclose 2>/dev/null || true
    logger -t bastion-netguard "repli leve : portail captif operationnel"
}

# L'état du repli suit l'état RÉEL d'OpenNDS, jamais une supposition.
sync_failclose() {
    if systemctl is-active --quiet opennds 2>/dev/null; then open; else close; fi
}

case "${1:-}" in
    apply)  build; sync_failclose ;;
    open)   open ;;
    close)  close ;;
    status)
        echo "portail captif : $(systemctl is-active opennds 2>/dev/null || echo inconnu)"
        echo "repli          : $(is_closed && echo 'ACTIF - LAN->WAN coupe' || echo 'leve - trafic autorise')"
        echo "resolveurs DoH refuses : $($NFT list set ${TABLE} dohresolvers 2>/dev/null | grep -c '\.' || echo 0) entree(s)"
        echo
        echo "--- compteurs (paquets interceptes) ---"
        $NFT list chain ${TABLE} prerouting 2>/dev/null | grep -E 'dnat|counter' | sed 's/^/  /'
        $NFT list chain ${TABLE} guard_fwd  2>/dev/null | grep counter | sed 's/^/  /'
        ;;
    *) echo "Usage: $0 apply|open|close|status" >&2; exit 2 ;;
esac
