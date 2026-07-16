#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — ingestion de l'historique de navigation.
# Lit le journal des requêtes DNS de dnsmasq (sur stdin, via tail -F) et insère
# chaque domaine consulté dans la table pf_weblog, avec l'utilisateur associé
# (résolu depuis pf_connlog par IP, mis en cache).
# Lancé par le service systemd proxyfibre-weblog.
set -u
DB() { mysql -N -B radius -e "$1" 2>/dev/null; }

declare -A last uname utime

while IFS= read -r line; do
    # Ne garder que les vraies requêtes de résolution (A / AAAA / HTTPS)
    case "$line" in
        *"query[A]"*|*"query[AAAA]"*|*"query[HTTPS]"*) : ;;
        *) continue ;;
    esac

    dom=$(printf '%s' "$line" | sed -n 's/.*query\[[A-Z]*\] \([^ ]*\) from .*/\1/p')
    ip=$(printf  '%s' "$line" | sed -n 's/.*from \([0-9.]*\).*/\1/p')
    [ -n "$dom" ] && [ -n "$ip" ] || continue
    # Assainir le domaine
    dom=$(printf '%s' "$dom" | tr -cd 'A-Za-z0-9._-')
    case "$ip" in ''|*[!0-9.]*) continue ;; esac
    # Ignorer les domaines techniques internes
    case "$dom" in ''|*.arpa|*.lan|localhost|*.local) continue ;; esac

    now=$(date +%s)
    # Dédupliquer : même ip+domaine dans les 15 s
    key="$ip|$dom"
    [ "${last[$key]:-0}" -gt $((now - 15)) ] && continue
    last[$key]=$now

    # Utilisateur associé à l'IP (cache 60 s)
    if [ "${utime[$ip]:-0}" -lt $((now - 60)) ]; then
        u=$(DB "SELECT username FROM pf_connlog WHERE ip='$ip' AND event='connect' ORDER BY ts DESC LIMIT 1")
        uname[$ip]="${u:-(non authentifie)}"
        utime[$ip]=$now
    fi
    user="${uname[$ip]}"

    DB "INSERT INTO pf_weblog (ts,client_ip,username,domain) VALUES (NOW(),'$ip','$user','$dom')"
done
