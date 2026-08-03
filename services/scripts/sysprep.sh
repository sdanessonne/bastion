#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# GÉNÉRALISATION d'une machine gabarit, avant d'en prendre l'image.
#
# ── CE QUE FAIT CE SCRIPT, ET POURQUOI IL EST DANGEREUX ───────────────────────
# Il EFFACE tout ce qui identifie cette machine et tout ce qu'elle a appris :
# secrets, certificats, base de données, annuaire, journaux, clés d'hôte. La
# machine n'est plus utilisable ensuite — c'est le but. Elle devient un moule.
#
# À NE JAMAIS LANCER SUR UNE PASSERELLE EN SERVICE.
#
# ── POURQUOI CHAQUE EFFACEMENT COMPTE ────────────────────────────────────────
# Une image de référence est copiée sur N serveurs. Tout ce qui y reste est
# partagé par les N. Concrètement :
#
#   · un secret oublié (mot de passe de relais, clé VPN, jeton d'API) rend les N
#     commissariats vulnérables à la compromission d'un seul ;
#   · une clé SSH autorisée oubliée donne accès aux N à celui qui la détient —
#     y compris à un prestataire qui n'a plus à y toucher ;
#   · les journaux de navigation, l'annuaire nominatif et le journal d'audit sont
#     des DONNÉES PERSONNELLES : les recopier dans un autre commissariat est une
#     divulgation, pas une maladresse ;
#   · une identité machine dupliquée (machine-id, clés d'hôte, SID de domaine)
#     produit des pannes qui n'apparaissent pas au démarrage mais des semaines
#     plus tard, sur des ouvertures de session qui échouent sans explication.
#
# Usage :  sysprep.sh --confirmer
set -uo pipefail

[ "$(id -u)" = "0" ] || { echo "ERREUR : à lancer en root." >&2; exit 1; }

if [ "${1:-}" != "--confirmer" ]; then
    cat >&2 <<'AIDE'

  GÉNÉRALISATION DU GABARIT — opération IRRÉVERSIBLE

  Ce script efface les secrets, la base de données, l'annuaire Active Directory,
  les journaux, les certificats, les clés d'hôte SSH et l'identité machine.
  La machine ne sera plus utilisable : elle deviendra un modèle à imager.

  Ne le lancez QUE sur une machine gabarit dédiée, jamais sur une passerelle
  en production.

  Pour confirmer :  sudo proxyfibre-sysprep --confirmer

AIDE
    exit 2
fi

echo "── Généralisation du gabarit ──"
efface() { [ -e "$1" ] && { rm -rf "$1" && echo "  effacé : $1"; } || true; }

# ── 1. Arrêt des services ───────────────────────────────────────────────────
# Avant tout effacement : un service actif réécrirait ce qu'on vient de retirer,
# et l'on obtiendrait une image « nettoyée » qui ne l'est pas.
for s in opennds apache2 samba-ad-dc freeradius dnsmasq mariadb clamav-daemon \
         proxyfibre-watchdog.timer proxyfibre-vpn-guard.timer; do
    systemctl stop "$s" >/dev/null 2>&1 || true
done
echo "  services arrêtés"

# ── 2. Secrets ──────────────────────────────────────────────────────────────
for f in /etc/proxyfibre/admin.env /etc/proxyfibre/admin-pass.env \
         /etc/proxyfibre/secrets.env /etc/proxyfibre/portal.env \
         /etc/proxyfibre/vpn-actif /etc/msmtprc /var/log/msmtp.log; do
    efface "$f"
done
efface /etc/wireguard
# Certificats et autorité : les régénérer est obligatoire, sinon N passerelles
# présentent le MÊME certificat et la même clé privée.
for f in /etc/proxyfibre/*.crt /etc/proxyfibre/*.key /etc/proxyfibre/ca.*; do
    [ -e "$f" ] && efface "$f"
done
efface /home/bastion/proxyFibre/provisioning/iso/iso-secrets.env

# ── 3. Accès distants ───────────────────────────────────────────────────────
# LE PLUS FACILE À OUBLIER. Une clé laissée ici ouvre TOUTES les passerelles
# déployées depuis cette image, sans limite de durée et sans trace.
for h in /root /home/*; do
    [ -d "$h/.ssh" ] || continue
    efface "$h/.ssh/authorized_keys"
    efface "$h/.ssh/known_hosts"
    efface "$h/.bash_history"
done
efface /etc/sudoers.d/bastion
echo "  clés autorisées et accès temporaires retirés"

# ── 4. Données : base, annuaire, sauvegardes ────────────────────────────────
# Journaux de navigation, annuaire nominatif, audit, photos des agents : des
# données personnelles qui n'ont rien à faire dans un autre commissariat.
systemctl start mariadb >/dev/null 2>&1 && sleep 3
mysql -e "DROP DATABASE IF EXISTS radius;" >/dev/null 2>&1 \
    && echo "  base de données effacée" || echo "  base déjà absente"
systemctl stop mariadb >/dev/null 2>&1 || true

# Active Directory : le domaine porte une identité de réplication unique. Le
# dupliquer crée deux contrôleurs qui se croient le même — Samba ne le supporte
# pas, et la corruption se manifeste tardivement.
efface /var/lib/samba
efface /var/cache/samba
efface /etc/samba/smb.conf
efface /srv/backups
rm -f /srv/pxe/images/*.wim 2>/dev/null || true

# ── 5. Identité machine ─────────────────────────────────────────────────────
# machine-id dupliqué : systemd, dbus et le client DHCP s'en servent comme
# identifiant unique. Deux machines identiques obtiennent le même bail et se
# chassent mutuellement du réseau.
: > /etc/machine-id
efface /var/lib/dbus/machine-id
rm -f /etc/ssh/ssh_host_* 2>/dev/null || true
echo "  identité machine et clés d'hôte SSH retirées"

# ── 6. Journaux et états volatils ───────────────────────────────────────────
journalctl --rotate >/dev/null 2>&1 || true
journalctl --vacuum-time=1s >/dev/null 2>&1 || true
find /var/log -type f \( -name '*.log' -o -name '*.gz' -o -name '*.1' \) -delete 2>/dev/null || true
: > /var/log/wtmp 2>/dev/null || true
: > /var/log/btmp 2>/dev/null || true
efface /var/lib/misc/dnsmasq.leases
rm -f /dev/shm/pf-* 2>/dev/null || true
rm -rf /run/bastion/* /tmp/ndsctl.sock 2>/dev/null || true
echo "  journaux et caches vidés"

# ── 7. Armement du premier démarrage ────────────────────────────────────────
# Le marqueur est RETIRÉ : sa présence est ce qui empêche la personnalisation de
# rejouer. L'oublier donnerait une image qui démarre sans jamais se configurer —
# et, faute de secrets, sans que rien ne fonctionne.
efface /var/lib/bastion/firstboot-done
systemctl enable bastion-firstboot.service >/dev/null 2>&1 \
    && echo "  personnalisation au premier démarrage : armée" \
    || echo "  ATTENTION : bastion-firstboot.service introuvable — relancez deploy.sh avant d'imager"

echo
echo "  ── Gabarit prêt. ÉTEIGNEZ MAINTENANT, sans redémarrer. ──"
echo "  Un redémarrage relancerait la personnalisation et gâcherait le gabarit."
echo "  Puis prenez l'image depuis un support externe :"
echo "      dd if=/dev/sdX conv=sparse bs=4M | gzip > bastion-modele.img.gz"
