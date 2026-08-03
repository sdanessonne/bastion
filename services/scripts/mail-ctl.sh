#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Envoi des alertes par courriel : état réel, et test qui dit la vérité.
#
# ── CE QUI A ÉTÉ CONSTATÉ ─────────────────────────────────────────────────────
# Une adresse de notification était enregistrée dans la console, le surveillant
# tournait chaque minute, il détectait bien les anomalies — et AUCUN courriel ne
# pouvait partir : aucun agent de transport n'était installé. La fonction d'envoi
# renvoyait « false » et personne ne lisait ce retour.
#
# La journée l'a illustré : le portail captif est tombé, le repli a coupé
# Internet pour tout le service, et pas un message n'est parti.
#
# ── CE QUE CE SCRIPT AJOUTE ───────────────────────────────────────────────────
# « state » dit ce qui est RÉELLEMENT possible — agent présent, relais configuré.
# « test » envoie pour de bon et rapporte l'erreur exacte. C'est le seul contrôle
# qui vaille : un relais accepté par la configuration peut refuser à l'usage
# (mot de passe changé, port bloqué, expéditeur rejeté), et rien ne le dirait.
#
# Usage :  mail-ctl.sh state
#          mail-ctl.sh test <adresse>
set -uo pipefail

MSMTPRC=/etc/msmtprc

json_esc() { printf '%s' "${1:-}" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n' | cut -c1-400; }

# Le relais est-il réellement renseigné ? La présence du fichier ne suffit pas :
# le modèle posé à l'installation contient des valeurs à remplacer, et le laisser
# tel quel produirait un « configuré » mensonger.
relais_ok() {
    [ -r "$MSMTPRC" ] || return 1
    grep -qE '^\s*host\s+\S+' "$MSMTPRC" || return 1
    grep -q 'A_REMPLACER' "$MSMTPRC" && return 1
    return 0
}

case "${1:-state}" in

state)
    mta=false;  [ -x /usr/sbin/sendmail ] && mta=true
    conf=false; relais_ok && conf=true
    # Tous les champs SAUF le mot de passe : le formulaire de la console doit
    # pouvoir se repréremplir, mais un secret qu'on réaffiche est un secret qu'on
    # laisse traîner dans le HTML, dans le cache du navigateur et dans son
    # gestionnaire de mots de passe.
    host=$(sed -n 's/^\s*host\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    from=$(sed -n 's/^\s*from\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    port=$(sed -n 's/^\s*port\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    user=$(sed -n 's/^\s*user\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    tls=starttls
    grep -qE '^\s*tls_starttls\s+off' "$MSMTPRC" 2>/dev/null && tls=ssl
    printf '{"mta":%s,"configure":%s,"host":"%s","from":"%s","port":"%s","user":"%s","tls":"%s"}\n' \
        "$mta" "$conf" "$(json_esc "${host:-}")" "$(json_esc "${from:-}")" \
        "$(json_esc "${port:-587}")" "$(json_esc "${user:-}")" "$tls"
    ;;

test)
    dest="${2:-}"
    # L'adresse arrive de la console : elle est revalidée ici. Une adresse
    # fantaisiste serait passée telle quelle à l'agent de transport.
    printf '%s' "$dest" | grep -Eq '^[A-Za-z0-9._%%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' \
        || { echo "ECHEC: adresse invalide"; exit 2; }
    [ -x /usr/sbin/sendmail ] || {
        echo "ECHEC: aucun agent de transport installe (paquet msmtp-mta)"; exit 3; }
    relais_ok || {
        echo "ECHEC: relais SMTP non configure dans ${MSMTPRC}"; exit 4; }

    # ── ON TESTE CE QUI PART VRAIMENT ────────────────────────────────────────
    # Le message est fabrique par le MEME modele que les alertes reelles
    # (admin/inc/mailer.php). Un test qui redigerait son propre message ne
    # prouverait que sa propre existence : si le modele contenait une erreur
    # d'en-tete MIME ou d'encodage, le test passerait au vert et les vraies
    # alertes arriveraient illisibles.
    php /var/www/admin/inc/mailtest.php "$dest" 2>&1
    exit $?
    ;;

config)
    # ── LES VALEURS ARRIVENT PAR L'ENTRÉE STANDARD, JAMAIS EN ARGUMENT ───────
    # Un mot de passe passé en argument est lisible par tout le monde dans « ps »
    # le temps de l'exécution, et il se retrouve dans l'historique du shell comme
    # dans les journaux d'audit du système. Il transite donc par un tuyau, que
    # seuls le processus appelant et celui-ci partagent.
    #
    # Format attendu, une paire par ligne : host=… port=… from=… user=… pass=… tls=…
    host=""; port="587"; from=""; user=""; pass=""; tls="starttls"; garder=0
    while IFS= read -r ligne; do
        cle=${ligne%%=*}; val=${ligne#*=}
        case "$cle" in
            host) host="$val" ;;  port) port="$val" ;;  from) from="$val" ;;
            user) user="$val" ;;  pass) pass="$val" ;;  tls)  tls="$val"  ;;
            keeppass) garder=1 ;;
        esac
    done

    printf '%s' "$host" | grep -Eq '^[A-Za-z0-9._-]+$' || { echo "ECHEC: nom de serveur invalide"; exit 2; }
    printf '%s' "$port" | grep -Eq '^[0-9]{1,5}$'      || { echo "ECHEC: port invalide"; exit 2; }
    printf '%s' "$from" | grep -Eq '^[A-Za-z0-9._%%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' \
        || { echo "ECHEC: adresse d'expedition invalide"; exit 2; }
    [ -n "$user" ] || user="$from"

    # Conservation du mot de passe existant : sans cela, modifier le seul numéro
    # de port obligerait à ressaisir le mot de passe, et l'oublier écraserait
    # silencieusement une configuration qui marchait.
    if [ "$garder" = "1" ] || [ -z "$pass" ]; then
        anc=$(sed -n 's/^\s*password\s\+\(.*\)$/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
        if [ -n "${anc:-}" ] && [ "$anc" != "A_REMPLACER" ]; then
            pass="$anc"
        elif [ -z "$pass" ]; then
            echo "ECHEC: mot de passe requis (aucun enregistre)"; exit 2
        fi
    fi

    case "$tls" in
        ssl)  ligne_tls="tls_starttls  off" ;;
        *)    ligne_tls="tls_starttls  on"  ;;
    esac

    # Écriture par un fichier temporaire en 600 PUIS déplacement : créer le
    # fichier définitif avant d'en restreindre les droits laisserait le mot de
    # passe lisible pendant l'intervalle.
    tmp=$(mktemp /etc/msmtprc.XXXXXX) || { echo "ECHEC: fichier temporaire"; exit 3; }
    chmod 600 "$tmp"
    {
        printf '# Bastion — relais d envoi des alertes.\n'
        printf '# Ecrit par la console (Services > Surveillance et alertes).\n'
        printf '# Le mot de passe est en clair : ce fichier doit rester en 600 root:root.\n\n'
        printf 'defaults\nauth           on\ntls            on\n'
        printf 'tls_trust_file /etc/ssl/certs/ca-certificates.crt\n'
        printf 'logfile        /var/log/msmtp.log\n\n'
        printf 'account        alertes\n'
        printf 'host           %s\n' "$host"
        printf 'port           %s\n' "$port"
        printf '%s\n' "$ligne_tls"
        printf 'from           %s\n' "$from"
        printf 'user           %s\n' "$user"
        printf 'password       %s\n' "$pass"
        printf '\naccount default : alertes\n'
    } > "$tmp"
    mv -f "$tmp" "$MSMTPRC" && chmod 600 "$MSMTPRC"
    touch /var/log/msmtp.log 2>/dev/null && chmod 640 /var/log/msmtp.log 2>/dev/null
    echo "OK: relais enregistre ($host:$port)"
    ;;

*)
    echo "usage: mail-ctl.sh state|test <adresse>|config (valeurs sur l'entree standard)" >&2; exit 2 ;;
esac
