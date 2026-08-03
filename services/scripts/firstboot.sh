#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# PERSONNALISATION au premier démarrage d'un serveur issu de l'image de référence.
#
# ── CE QUE FAIT CE SCRIPT ─────────────────────────────────────────────────────
# L'image de référence est identique sur tous les serveurs : elle ne contient ni
# secret, ni identité, ni domaine. Ce script donne à CETTE machine-ci ce qui doit
# lui être propre — identité, clés, certificats, secrets, domaine — puis déploie
# Bastion et se désarme.
#
# ── IL DOIT ÉCHOUER BRUYAMMENT ────────────────────────────────────────────────
# Le pire résultat n'est pas l'échec : c'est un serveur qui démarre, présente une
# console, et se révèle à moitié configuré le jour où il compte. À chaque étape,
# un échec ARRÊTE la personnalisation, laisse le marqueur ABSENT (donc l'opération
# rejouable) et écrit la raison à l'écran comme dans le journal.
#
# ── D'OÙ VIENNENT LES VALEURS DE SITE ─────────────────────────────────────────
# Deux voies, dans cet ordre :
#   1. /boot/bastion-site.env, si le technicien l'a déposé (déploiement en série) ;
#   2. sinon, saisie sur la console — le technicien est devant la machine.
# Aucune valeur par défaut silencieuse : un nom d'hôte ou un domaine deviné
# produirait N serveurs homonymes sur le réseau.
set -uo pipefail

MARQUE=/var/lib/bastion/firstboot-done
SITE=/boot/bastion-site.env
JOURNAL=/var/log/bastion-firstboot.log

mkdir -p /var/lib/bastion
exec > >(tee -a "$JOURNAL") 2>&1

echo "═══ Bastion — personnalisation du serveur ═══  $(date '+%d/%m/%Y %H:%M:%S')"

if [ -f "$MARQUE" ]; then
    echo "  Déjà personnalisé le $(cat "$MARQUE"). Rien à faire."
    exit 0
fi

fatal() {
    echo
    echo "  ╔══════════════════════════════════════════════════════════════╗"
    echo "  ║  PERSONNALISATION INTERROMPUE                                ║"
    echo "  ╚══════════════════════════════════════════════════════════════╝"
    echo "  Motif : $*"
    echo
    echo "  Le serveur N'EST PAS opérationnel. Le marqueur n'a pas été posé :"
    echo "  corrigez la cause, puis relancez :"
    echo "      sudo systemctl start bastion-firstboot"
    echo "  Journal complet : $JOURNAL"
    logger -t bastion-firstboot -p daemon.err "personnalisation interrompue : $*" 2>/dev/null || true
    exit 1
}

# ── 1. Valeurs de site ──────────────────────────────────────────────────────
if [ -r "$SITE" ]; then
    echo "── Valeurs de site lues dans $SITE ──"
    # shellcheck disable=SC1090
    . "$SITE"
else
    echo "── Aucun fichier de site : saisie sur la console ──"
    # L'invite n'a de sens que sur un vrai terminal. Sans lui — démarrage sans
    # écran, console série absente — on s'arrête au lieu de deviner.
    [ -t 0 ] || fatal "aucun terminal et aucun /boot/bastion-site.env : impossible de connaître le nom du site"
    printf "  Nom d'hôte du serveur (ex. bastion-cml91) : "; read -r NOM_HOTE
    printf "  Domaine Active Directory (ex. bastion.pn.int) : "; read -r DOMAINE
    printf "  Adresse LAN de la passerelle [192.168.182.1] : "; read -r LAN_IP_S
fi

NOM_HOTE="${NOM_HOTE:-}"
DOMAINE="${DOMAINE:-}"
LAN_IP_S="${LAN_IP_S:-192.168.182.1}"

printf '%s' "$NOM_HOTE" | grep -Eq '^[a-z0-9][a-z0-9-]{1,30}$' \
    || fatal "nom d'hôte invalide : « $NOM_HOTE » (minuscules, chiffres et tirets)"
printf '%s' "$DOMAINE" | grep -Eq '^[a-z0-9.-]+\.[a-z]{2,}$' \
    || fatal "domaine invalide : « $DOMAINE »"
printf '%s' "$LAN_IP_S" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
    || fatal "adresse LAN invalide : « $LAN_IP_S »"

# ── 2. Identité machine ─────────────────────────────────────────────────────
echo "── Identité de la machine ──"
systemd-machine-id-setup >/dev/null 2>&1 || fatal "génération de machine-id impossible"
[ -s /etc/machine-id ] || fatal "machine-id toujours vide après génération"
cp -f /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true

hostnamectl set-hostname "$NOM_HOTE" 2>/dev/null || hostname "$NOM_HOTE"
# Sans cette ligne, « sudo » attend la résolution du nom d'hôte à chaque appel et
# ajoute une seconde à chaque commande — panne discrète et très agaçante.
grep -q "127.0.1.1[[:space:]]\+$NOM_HOTE" /etc/hosts 2>/dev/null \
    || printf '127.0.1.1\t%s.%s\t%s\n' "$NOM_HOTE" "$DOMAINE" "$NOM_HOTE" >> /etc/hosts
echo "  nom d'hôte : $NOM_HOTE.$DOMAINE"

# ── 3. Clés d'hôte SSH ──────────────────────────────────────────────────────
# Sans régénération, tous les serveurs partagent la même clé : l'un peut se faire
# passer pour l'autre, et l'avertissement de changement de clé ne protège plus.
echo "── Clés d'hôte SSH ──"
ssh-keygen -A >/dev/null 2>&1 || fatal "génération des clés d'hôte impossible"
ls /etc/ssh/ssh_host_*_key >/dev/null 2>&1 || fatal "aucune clé d'hôte produite"
systemctl restart ssh >/dev/null 2>&1 || systemctl restart sshd >/dev/null 2>&1 || true
echo "  clés régénérées"

# ── 4. Taille du disque ─────────────────────────────────────────────────────
# L'image a la taille du gabarit. Copiée sur un disque plus grand, l'espace
# supplémentaire reste inutilisé — et l'on s'en aperçoit le jour où les journaux
# saturent une partition qu'on croyait spacieuse.
echo "── Ajustement du disque ──"
RACINE=$(findmnt -no SOURCE / 2>/dev/null)
if [ -n "${RACINE:-}" ] && command -v growpart >/dev/null 2>&1; then
    DISQUE=$(lsblk -no PKNAME "$RACINE" 2>/dev/null | head -1)
    NUMPART=$(printf '%s' "$RACINE" | grep -o '[0-9]*$')
    if [ -n "${DISQUE:-}" ] && [ -n "${NUMPART:-}" ]; then
        growpart "/dev/$DISQUE" "$NUMPART" >/dev/null 2>&1 || true
        resize2fs "$RACINE" >/dev/null 2>&1 || true
        echo "  partition étendue : $(df -h / | awk 'NR==2{print $2}') au total"
    fi
else
    echo "  (growpart absent — extension à faire à la main si le disque est plus grand)"
fi

# ── 5. Secrets propres à ce serveur ─────────────────────────────────────────
# Engendrés, jamais repris de l'image : c'est toute la raison d'être de cette
# étape. Deux serveurs ne doivent jamais partager un mot de passe.
echo "── Secrets ──"
install -d -m 700 /etc/proxyfibre
MDP_ADMIN=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-24)
[ ${#MDP_ADMIN} -ge 20 ] || fatal "engendrement du mot de passe impossible"
umask 077
printf 'ADMIN_PASS="%s"\n' "$MDP_ADMIN" > /etc/proxyfibre/admin-pass.env
chmod 600 /etc/proxyfibre/admin-pass.env
echo "  mot de passe de la console engendré"

# ── 6. Configuration réseau du site ─────────────────────────────────────────
CONF=/home/bastion/proxyFibre/provisioning/config.env
if [ -f "$CONF" ]; then
    sed -i "s|^LAN_IP=.*|LAN_IP=\"$LAN_IP_S\"|" "$CONF" 2>/dev/null || true
    sed -i "s|^AD_REALM=.*|AD_REALM=\"$(printf '%s' "$DOMAINE" | tr 'a-z' 'A-Z')\"|" "$CONF" 2>/dev/null || true
    sed -i "s|^AD_DOMAIN=.*|AD_DOMAIN=\"$(printf '%s' "$DOMAINE" | cut -d. -f1 | tr 'a-z' 'A-Z')\"|" "$CONF" 2>/dev/null || true
    echo "  config.env ajusté (LAN $LAN_IP_S, domaine $DOMAINE)"
fi

# ── 7. Déploiement ──────────────────────────────────────────────────────────
echo "── Déploiement de Bastion (plusieurs minutes) ──"
DEP=/home/bastion/proxyFibre/provisioning/deploy.sh
[ -x "$DEP" ] || fatal "deploy.sh introuvable ($DEP) — l'image ne contient pas le dépôt"
"$DEP" || fatal "le déploiement a échoué — voir $JOURNAL"

# ── 8. Contrôle AVANT de déclarer terminé ───────────────────────────────────
# Poser le marqueur sans vérifier reviendrait à certifier un travail qu'on n'a
# pas regardé. Le serveur doit répondre pour être déclaré opérationnel.
echo "── Contrôle ──"
manque=""
for s in apache2 mariadb dnsmasq; do
    systemctl is-active --quiet "$s" || manque="$manque $s"
done
[ -z "$manque" ] || fatal "services non démarrés après déploiement :$manque"
code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "https://127.0.0.1:2443/portal/fas.php" 2>/dev/null)
[ "$code" = "200" ] || fatal "le portail ne répond pas (code ${code:-aucun})"
echo "  services actifs, portail en 200"

# ── 9. Désarmement ──────────────────────────────────────────────────────────
date '+%d/%m/%Y %H:%M:%S' > "$MARQUE"
systemctl disable bastion-firstboot.service >/dev/null 2>&1 || true

cat <<FIN

  ╔══════════════════════════════════════════════════════════════════╗
  ║  SERVEUR OPÉRATIONNEL                                            ║
  ╚══════════════════════════════════════════════════════════════════╝

  Nom          : $NOM_HOTE.$DOMAINE
  Portail      : https://$LAN_IP_S:2443/portal/fas.php
  Console      : https://$LAN_IP_S:8443/  (compte « admin »)

  Mot de passe de la console — RELEVEZ-LE MAINTENANT :

      $MDP_ADMIN

  Il est aussi conservé dans /etc/proxyfibre/admin-pass.env (600 root).
  Changez-le depuis la console dès la première connexion.

  Journal de cette personnalisation : $JOURNAL

FIN
logger -t bastion-firstboot -p daemon.notice "personnalisation terminee : $NOM_HOTE.$DOMAINE" 2>/dev/null || true
