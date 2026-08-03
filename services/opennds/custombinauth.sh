#!/bin/bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — custombinauth : applique les quotas/horaires par groupe (Phase 4)
# et journalise les connexions/déconnexions (Phase 5).
# Inclus par /usr/lib/opennds/binauth_log.sh. Exécuté en root.
#
# Variables disponibles en entrée : $action, $custom, et les arguments positionnels
#   auth_client : $2=mac $5=ip $7=custom
#   *_deauth    : $2=mac $3=octets_in $4=octets_out $5=session_start $6=session_end $8=custom
# Variables de sortie modifiables : session_length(SECONDES) upload_rate/download_rate(kb/s)
#   upload_quota/download_quota(kB) exitlevel(0=autorise,1=refuse)
#   NB : pf_groups stocke la durée en MINUTES → conversion *60 plus bas.

DB="mysql -N -B radius"

# Identifiant depuis le champ custom (base64 "user=<login>"), assaini.
pf_user=$(ndsctl b64decode "$custom" 2>/dev/null | sed -n 's/.*user=\([^,]*\).*/\1/p')
pf_user=$(printf '%s' "$pf_user" | tr -cd 'A-Za-z0-9._@-')
[ -z "$pf_user" ] && pf_user="inconnu"

if [ "$action" = "auth_client" ]; then
    pf_mac="$2"; pf_ip="$5"

    # Groupe de l'utilisateur (défaut : "default")
    pf_group=$($DB -e "SELECT groupname FROM radusergroup WHERE username='$pf_user' LIMIT 1;" 2>/dev/null)
    [ -z "$pf_group" ] && pf_group="default"

    # Limites du groupe
    limits=$($DB -e "SELECT COALESCE(session_timeout_min,0),COALESCE(down_rate_kbps,0),COALESCE(up_rate_kbps,0),COALESCE(down_quota_mb,0),COALESCE(up_quota_mb,0),COALESCE(hours_start,0),COALESCE(hours_end,24) FROM pf_groups WHERE groupname='$pf_group' LIMIT 1;" 2>/dev/null)
    if [ -n "$limits" ]; then
        read pf_sess pf_drate pf_urate pf_dquota pf_uquota pf_hstart pf_hend <<EOF2
$limits
EOF2
    else
        pf_sess=0; pf_drate=0; pf_urate=0; pf_dquota=0; pf_uquota=0; pf_hstart=0; pf_hend=24
    fi

    # Contrôle des plages horaires (si hstart != hend)
    if [ "$pf_hstart" != "$pf_hend" ]; then
        hour=$(date +%H); hour=$((10#$hour))
        if [ "$hour" -lt "$pf_hstart" ] || [ "$hour" -ge "$pf_hend" ]; then
            exitlevel=1
        fi
    fi

    # Appliquer les quotas/débits.
    # IMPORTANT : OpenNDS attend session_length en SECONDES → conversion depuis les minutes.
    session_length=$((pf_sess * 60))
    download_rate="$pf_drate"
    upload_rate="$pf_urate"
    download_quota=$((pf_dquota * 1024))
    upload_quota=$((pf_uquota * 1024))

    # Journaliser la connexion (si autorisée)
    if [ "$exitlevel" = "0" ]; then
        $DB -e "INSERT INTO pf_connlog (username,groupname,mac,ip,event,ts) VALUES ('$pf_user','$pf_group','$pf_mac','$pf_ip','connect',NOW());" 2>/dev/null

        # LIMITATION DE DÉBIT RÉELLE.
        # Les valeurs ci-dessus (download_rate/upload_rate) sont transmises à OpenNDS,
        # mais OpenNDS NE MET PAS EN FORME LE TRAFIC : son binaire ne contient aucune
        # référence à tc/htb (vérifié). Il s'en sert seulement pour DÉCONNECTER un poste
        # trop rapide. Les débits réglés par groupe n'avaient donc aucun effet — un seul
        # poste pouvait saturer la ligne. C'est ce script qui les applique vraiment.
        /usr/local/sbin/proxyfibre-qos add "$pf_ip" "$pf_drate" "$pf_urate" >/dev/null 2>&1 || true

        # SORTIE PAR TUNNEL, si le groupe le prévoit.
        # Le poste est basculé À L'AUTHENTIFICATION, comme la limitation de débit :
        # c'est le seul moment où l'on connaît à la fois l'agent, son groupe et son
        # adresse. La bascule est donc automatique — un agent du groupe n'a rien à
        # activer, donc rien à oublier d'activer.
        pf_vpn=$($DB -e "SELECT COALESCE(vpn_exit,0) FROM pf_groups WHERE groupname='$pf_group' LIMIT 1;" 2>/dev/null)
        if [ "${pf_vpn:-0}" = "1" ]; then
            /usr/local/sbin/proxyfibre-vpn client add "$pf_ip" >/dev/null 2>&1 || true
        fi

        # OpenNDS ne transmet PAS l'IP lors de la déconnexion (seulement la MAC) : sans
        # cette correspondance, impossible de relâcher la limite du bon poste.
        mkdir -p /run/bastion-qos 2>/dev/null
        printf '%s' "$pf_ip" > "/run/bastion-qos/$(printf '%s' "$pf_mac" | tr -cd 'A-Fa-f0-9:')" 2>/dev/null
    fi

elif [ "$action" = "deauth" ]; then
    pf_mac="$2"; pf_in="$3"; pf_out="$4"; pf_start="$5"; pf_end="$6"
    dur=$((pf_end - pf_start)); [ "$dur" -lt 0 ] && dur=0
    $DB -e "INSERT INTO pf_connlog (username,mac,event,bytes_in,bytes_out,duration_s,ts) VALUES ('$pf_user','$pf_mac','disconnect','${pf_in:-0}','${pf_out:-0}','$dur',NOW());" 2>/dev/null

    # Relâcher la limite : on relit l'IP mémorisée à la connexion.
    pf_key=$(printf '%s' "$pf_mac" | tr -cd 'A-Fa-f0-9:')
    if [ -n "$pf_key" ] && [ -r "/run/bastion-qos/$pf_key" ]; then
        pf_ip=$(cat "/run/bastion-qos/$pf_key" 2>/dev/null | tr -cd '0-9.')
        [ -n "$pf_ip" ] && /usr/local/sbin/proxyfibre-qos del "$pf_ip" >/dev/null 2>&1 || true
        # Retrait du tunnel. Sans cela, l'adresse resterait marquée et le poste
        # SUIVANT à recevoir ce bail sortirait par le tunnel sans y avoir droit —
        # et, le tunnel tombé, se retrouverait bloqué sans explication.
        [ -n "$pf_ip" ] && /usr/local/sbin/proxyfibre-vpn client del "$pf_ip" >/dev/null 2>&1 || true
        rm -f "/run/bastion-qos/$pf_key" 2>/dev/null
    fi
fi
