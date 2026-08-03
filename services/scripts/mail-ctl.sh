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
    host=$(sed -n 's/^\s*host\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    from=$(sed -n 's/^\s*from\s\+\(\S\+\).*/\1/p' "$MSMTPRC" 2>/dev/null | head -1)
    printf '{"mta":%s,"configure":%s,"host":"%s","from":"%s"}\n' \
        "$mta" "$conf" "$(json_esc "${host:-}")" "$(json_esc "${from:-}")"
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

    hote=$(hostname 2>/dev/null || echo bastion)
    # La sortie de sendmail est CAPTURÉE et rendue telle quelle : c'est elle qui
    # nomme la vraie cause — authentification refusée, port filtré, expéditeur
    # rejeté. Un simple « échec » obligerait à chercher dans les journaux.
    sortie=$( { printf 'Subject: [Bastion/%s] test d envoi\nTo: %s\nContent-Type: text/plain; charset=utf-8\n\n' "$hote" "$dest"
                printf 'Ceci est un test envoye depuis la console d administration de Bastion.\n\n'
                printf 'S il vous parvient, les alertes de surveillance vous parviendront aussi.\n'
                printf 'Emis le %s depuis la passerelle %s.\n\n-- \nMessage automatique, ne pas repondre.\n' \
                       "$(date '+%d/%m/%Y a %H:%M:%S')" "$hote"
              } | /usr/sbin/sendmail -t 2>&1 )
    code=$?
    if [ "$code" -eq 0 ]; then
        echo "OK: message remis au relais pour ${dest}"
    else
        echo "ECHEC (${code}): ${sortie:-aucun detail}"
        exit 1
    fi
    ;;

*)
    echo "usage: mail-ctl.sh state|test <adresse>" >&2; exit 2 ;;
esac
