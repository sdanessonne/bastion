#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Quarantaine réseau d'un poste : coupe son trafic ROUTÉ (accès Internet et tout ce qui passe
# par la passerelle) au moyen d'une table nftables DÉDIÉE — « bastion_quarantine ». Cette table
# est ISOLÉE de celle d'OpenNDS : on ne touche jamais aux règles du portail, et vider notre
# table lève instantanément toutes les quarantaines. Sécurité par construction.
#
# LIMITE HONNÊTE : la passerelle route ; elle ne fait pas de pont. Le trafic entre deux postes
# du MÊME sous-réseau (niveau 2) ne passe pas par elle et n'est donc pas filtrable ici. La
# quarantaine coupe l'accès Internet et le trafic routé — c'est le besoin principal.
set -u

# Reconstruit la table à partir de la base (idempotent : on supprime puis on recrée). Le crochet
# « forward » à priorité -150 s'exécute AVANT OpenNDS ; les IP non listées passent (policy accept).
apply() {
    ips=""
    for ip in $(mysql -N radius -e "SELECT ip FROM pf_quarantine" 2>/dev/null); do
        echo "$ip" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || continue
        # garde-fou : on n'isole JAMAIS les IP de la passerelle elle-même.
        case "$ip" in 192.168.182.1|192.168.182.2) continue ;; esac
        ips="$ips $ip,"
    done
    nft delete table ip bastion_quarantine 2>/dev/null || true
    if [ -n "$ips" ]; then
        list=$(printf '%s' "$ips" | sed 's/,$//')
        nft -f - <<EOF
table ip bastion_quarantine {
    set quarantine { type ipv4_addr; elements = { $list } }
    chain forward {
        type filter hook forward priority -150; policy accept;
        ip saddr @quarantine drop
        ip daddr @quarantine drop
    }
}
EOF
    fi
}

case "${1:-}" in
    apply) apply; echo "quarantaine appliquee" ;;
    status) nft list table ip bastion_quarantine 2>/dev/null || echo "aucune quarantaine active" ;;
    *) echo "usage: quarantine apply|status" >&2; exit 2 ;;
esac
