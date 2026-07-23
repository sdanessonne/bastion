#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Désactivation PROGRAMMÉE des comptes arrivés à échéance (fin de mission, mutation).
# Lancé une fois par jour par la minuterie systemd proxyfibre-account-expiry.timer.
#
# À l'échéance : on retire l'accès Internet (portail RADIUS) et on désactive le compte de
# domaine (AD). Le compte n'est PAS supprimé — l'identité, la photo et l'historique restent ;
# il suffit de retirer la date et de rendre l'accès pour le réactiver.
set -u

today=$(date +%F)

# « applied=0 » : on n'agit qu'une fois par échéance (pas de répétition ni de spam d'audit).
mysql -N radius -e "SELECT username FROM pf_user_expiry
    WHERE applied=0 AND expires_at IS NOT NULL AND expires_at <= '$today'" 2>/dev/null | while IFS= read -r u; do
    # Garde-fou : on n'injecte QUE des identifiants sûrs dans les requêtes suivantes.
    case "$u" in ''|*[!A-Za-z0-9._@-]*) continue ;; esac

    # 1) Accès Internet (portail) retiré.
    mysql radius -e "DELETE FROM radcheck WHERE username='$u'; DELETE FROM radusergroup WHERE username='$u';" 2>/dev/null || true

    # 2) Compte de domaine désactivé (si présent). proxyfibre-ad tourne en root ici.
    [ -x /usr/local/sbin/proxyfibre-ad ] && /usr/local/sbin/proxyfibre-ad user disable "$u" >/dev/null 2>&1 || true

    # 3) Trace d'audit système + marquage « traité ».
    mysql radius -e "INSERT INTO pf_audit (admin,action,detail,ip) VALUES (NULL,'system.account_expired','$u','cron');
                     UPDATE pf_user_expiry SET applied=1 WHERE username='$u';" 2>/dev/null || true

    logger -t bastion "compte expiré désactivé : $u" 2>/dev/null || true
done
