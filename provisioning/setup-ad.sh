#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — Active Directory (Samba AD DC) sur la passerelle.
# Gère les fonctionnaires (utilisateurs), les ordinateurs, les dossiers partagés
# et les GPO. Coexiste avec dnsmasq (portail captif) : le DNS AD écoute sur une
# IP DÉDIÉE (192.168.182.2) ; dnsmasq reste sur .1 et lui délègue la zone AD.
#
# Prérequis : paquets samba smbclient krb5-user winbind dnsutils acl attr.
# Usage : sudo ./setup-ad.sh
set -euo pipefail

# ── D'où viennent ces valeurs ────────────────────────────────────────────────
# LAN_IF valait « enp0s8 » en dur : le nom de l'interface de la VM de développement.
# Sur toute autre machine — un serveur physique nomme les siennes enp1s0/enp2s0 —
# l'adresse du contrôleur de domaine était posée sur une interface INEXISTANTE.
# L'erreur était avalée par le « || true » qui suit, l'unité systemd échouait en
# silence, et Samba se liait à une adresse absente. Un annuaire mort, sans un mot.
# config.env porte déjà la bonne valeur : c'est lui qui décide, ici comme ailleurs.
_ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -r "$_ICI/config.env" ] && . "$_ICI/config.env"
LAN_IF="${LAN_IF:-enp0s8}"
GW_IP="${LAN_IP:-192.168.182.1}"   # dnsmasq (portail captif)
# Le DNS du domaine prend l'adresse suivante sur le même réseau : .1 est la
# passerelle, .2 l'annuaire. Déduite de GW_IP pour rester cohérente si le plan
# d'adressage change dans config.env.
DNS_IP="$(printf '%s' "$GW_IP" | sed 's/\.[0-9]*$//').2"
DC_HOST="dc"

# ── Nom de domaine : priorité env AD_REALM/AD_DOMAIN, puis réglages base
#    (pf_settings ad_realm/ad_domain, choisis depuis la console admin), puis défaut.
[ -f /etc/proxyfibre/ad.env ] && . /etc/proxyfibre/ad.env 2>/dev/null || true
REALM="${AD_REALM:-}"
DOMAIN="${AD_DOMAIN:-}"
[ -z "$REALM" ]  && REALM="$(mysql -N radius -e "SELECT v FROM pf_settings WHERE k='ad_realm'"  2>/dev/null || true)"
[ -z "$DOMAIN" ] && DOMAIN="$(mysql -N radius -e "SELECT v FROM pf_settings WHERE k='ad_domain'" 2>/dev/null || true)"
REALM="$(printf '%s' "${REALM:-BASTION.LOCAL}" | tr 'a-z' 'A-Z' | tr -cd 'A-Z0-9.-')"
DOMAIN="$(printf '%s' "${DOMAIN:-BASTION}" | tr 'a-z' 'A-Z' | tr -cd 'A-Z0-9-' | cut -c1-15)"
# Mot de passe de l'Administrateur du domaine : ENGENDRÉ, jamais codé en dur.
# Un défaut inscrit dans le dépôt serait connu de quiconque lit le code : tout
# commissariat déployé sans le surcharger aurait un compte « Domain Admins » dont le
# mot de passe est public. Il est conservé dans /etc/proxyfibre/ad.env (600, root),
# relu en tête de ce script — ré-exécuter setup-ad.sh ne le change donc pas.
# Contrainte Samba : au moins 7 caractères, avec majuscules, minuscules et chiffres.
genpass(){
  # Composition IMPOSÉE et non espérée : MESURÉ, un tirage purement aléatoire sur cet
  # alphabet est sans chiffre dans 4,5 % des cas (8 chiffres pour 55 caractères).
  # Samba EXIGE majuscule + minuscule + chiffre : le provisioning du domaine
  # échouerait donc une fois sur 22, au hasard. On mélange ensuite, sans quoi les six
  # premiers caractères suivraient toujours le même motif.
  local maj='ABCDEFGHJKMNPQRSTUVWXYZ' min='abcdefghijkmnpqrstuvwxyz' chi='23456789'
  local tout p='' i
  tout="${maj}${min}${chi}"
  for i in 1 2; do p="${p}$(tr -dc "$maj" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p="${p}$(tr -dc "$min" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p="${p}$(tr -dc "$chi" < /dev/urandom | head -c1)"; done
  p="${p}$(tr -dc "$tout" < /dev/urandom | head -c 14)"
  printf '%s' "$p" | fold -w1 | shuf | tr -d '\n' | sed 's/.\{5\}/&-/g; s/-$//'
}
if [ -z "${AD_ADMIN_PASS:-}" ]; then
    AD_ADMIN_PASS="$(genpass)"
    AD_PASS_GENERE=1
fi
ADMINPASS="${AD_ADMIN_PASS}"
echo "[AD] Domaine cible : ${REALM} (NetBIOS ${DOMAIN})"

# ── Les paquets, installés et non supposés ───────────────────────────────────
# L'en-tête les déclarait « prérequis » et le script partait du principe qu'on les
# avait posés. Sur un serveur neuf, il déroulait donc six étapes — arrêt des
# services, adresse .2, DNS, nom d'hôte — avant de buter sur « samba-tool :
# commande introuvable », laissant la machine à moitié configurée pour un domaine
# qui n'existait pas. Un prérequis qu'on peut satisfaire soi-même n'a pas à être
# une condition d'entrée.
if ! command -v samba-tool >/dev/null 2>&1; then
    echo "[AD] Installation des paquets Samba (absents)…"
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        samba smbclient krb5-user winbind dnsutils acl attr >/dev/null 2>&1 || true
    command -v samba-tool >/dev/null 2>&1 || {
        echo "[AD] ECHEC : samba-tool reste introuvable après installation."
        echo "     Vérifier l'accès au dépôt Debian, puis relancer ce script."
        exit 1
    }
    echo "[AD]   samba-tool $(samba-tool --version 2>/dev/null | head -1)"
fi

echo "[AD] Arrêt des services Samba classiques (mode DC uniquement)…"
systemctl disable --now smbd nmbd winbind samba 2>/dev/null || true

echo "[AD] IP dédiée ${DNS_IP} (DNS AD) + persistance…"
# IMPORTANT : l'IP .2 doit être posée avec un LABEL d'alias (${LAN_IF}:0). Sinon OpenNDS
# 10.x détecte deux IP sur son interface passerelle (« IP address aliasing forbidden ») et
# REFUSE de démarrer → plus de portail captif (accès Internet ouvert sans authentification).
# Le label fait qu'OpenNDS ne compte plus .2 comme une IP de l'interface, tout en la gardant
# joignable sur le LAN pour le DNS/LDAP/Kerberos AD.
ip addr add "${DNS_IP}/24" dev "${LAN_IF}" label "${LAN_IF}:0" 2>/dev/null || true
cat > /etc/systemd/system/proxyfibre-adip.service <<EOF
[Unit]
Description=Bastion — IP dediee pour le DNS Active Directory
After=network-online.target
Before=opennds.service
[Service]
Type=oneshot
ExecStart=/sbin/ip addr add ${DNS_IP}/24 dev ${LAN_IF} label ${LAN_IF}:0
ExecStop=/sbin/ip addr del ${DNS_IP}/24 dev ${LAN_IF}
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable proxyfibre-adip >/dev/null 2>&1 || true

echo "[AD] Coexistence DNS : dnsmasq sur .1, délègue la zone AD à Samba (.2)…"
UP="$(grep -m1 '^nameserver' /etc/resolv.conf 2>/dev/null | awk '{print $2}')"
UP="${UP:-1.1.1.1}"
# 1) dnsmasq ne doit PAS lier .2 : on retire interface=enp0s8 (listen-address suffit).
[ -f /etc/dnsmasq.d/proxyfibre.conf ] && \
  sed -i 's/^interface=enp0s8/#interface=enp0s8  # neutralise pour laisser .2 a Samba/' /etc/dnsmasq.d/proxyfibre.conf
# 2) Bloc AD dans dnsmasq.conf (IDEMPOTENT : on retire l'ancien bloc puis on réécrit
#    avec le realm courant — sinon une recréation garderait l'ancienne zone déléguée).
sed -i '/# --- Active Directory (Bastion) BEGIN ---/,/# --- Active Directory (Bastion) END ---/d' /etc/dnsmasq.conf
sed -i '/# --- Active Directory (Bastion) ---/d' /etc/dnsmasq.conf   # ancien format sans END
cat >> /etc/dnsmasq.conf <<EOF
# --- Active Directory (Bastion) BEGIN ---
bind-interfaces
listen-address=127.0.0.1,${GW_IP}
server=/${REALM,,}/${DNS_IP}
rev-server=192.168.182.0/24,${DNS_IP}
no-resolv
server=${UP}
# --- Active Directory (Bastion) END ---
EOF
systemctl restart dnsmasq
sleep 2
dig +short +time=3 +tries=1 @${GW_IP} debian.org >/dev/null 2>&1 && echo "  DNS externe OK" || echo "  ATTENTION: DNS externe KO (vérifier upstream ${UP})"

echo "[AD] Nom d'hôte + hosts…"
hostnamectl set-hostname "${DC_HOST}" 2>/dev/null || true
grep -q "${DC_HOST}.${REALM,,}" /etc/hosts || echo "${DNS_IP} ${DC_HOST}.${REALM,,} ${DC_HOST}" >> /etc/hosts

echo "[AD] Provisioning du domaine ${REALM}…"
[ -f /etc/samba/smb.conf ] && mv /etc/samba/smb.conf "/etc/samba/smb.conf.pre-ad" 2>/dev/null || true
rm -f /var/lib/samba/private/secrets.tdb 2>/dev/null || true
samba-tool domain provision \
  --use-rfc2307 --realm="${REALM}" --domain="${DOMAIN}" \
  --server-role=dc --dns-backend=SAMBA_INTERNAL \
  --adminpass="${ADMINPASS}" --host-ip="${DNS_IP}" \
  --option="interfaces=${DNS_IP}" --option="bind interfaces only=yes"

echo "[AD] Kerberos + forwarder DNS + identifiants…"
cp -f /var/lib/samba/private/krb5.conf /etc/krb5.conf
sed -i "s/dns forwarder = .*/dns forwarder = ${GW_IP}/" /etc/samba/smb.conf || true
install -D -m600 /dev/null /etc/proxyfibre/ad.env
printf 'AD_ADMIN_PASS="%s"\n' "${ADMINPASS}" > /etc/proxyfibre/ad.env

echo "[AD] Dossiers partagés + include (À LA FIN de smb.conf, sinon casse [global])…"
mkdir -p /srv/partage/commun /srv/partage/fonctionnaires
chmod 1777 /srv/partage/commun
cat > /etc/samba/shares.conf <<'EOF'
[Commun]
   path = /srv/partage/commun
   read only = no
   browseable = yes

[Fonctionnaires]
   path = /srv/partage/fonctionnaires
   read only = no
   browseable = yes
EOF
grep -q 'include = /etc/samba/shares.conf' /etc/samba/smb.conf || \
  echo 'include = /etc/samba/shares.conf' >> /etc/samba/smb.conf

# Journal d'audit des authentifications : qui (utilisateur) depuis quel poste, à chaque logon.
grep -q 'auth_json_audit' /etc/samba/smb.conf || \
  sed -i '/\[global\]/a\	log level = 1 auth_json_audit:3@/var/log/samba/auth_audit.log' /etc/samba/smb.conf

# Accès anonyme pour le déploiement PXE (source d'installation + images master) : WinPE
# n'a pas de compte du domaine, et embarquer un mot de passe dans boot.wim serait une faille
# (boot.wim est téléchargeable AVANT authentification au portail).
# Ne concerne QUE les partages marqués « guest ok = yes » ([Install], [Images]) : les autres
# ([Commun], [Fonctionnaires], sysvol…) restent protégés — vérifié.
grep -q 'map to guest' /etc/samba/smb.conf || \
  sed -i '/\[global\]/a\	map to guest = Bad User' /etc/samba/smb.conf

# Détection des clients morts — INDISPENSABLE pour le déploiement PXE.
# Sans cela : un poste qui redémarre laisse sa session TCP ouverte côté Samba (aucun FIN
# n'est envoyé). Or Windows réutilise le MÊME port source après redémarrage → le serveur
# voit un SYN sur une connexion « déjà établie » et répond ACK au lieu de SYN-ACK → le
# client échoue avec « erreur système 53 : chemin réseau introuvable ».
# keepalive : sonde le client toutes les 30 s et ferme les sessions mortes. C'est LUI
#             qui règle réellement le problème ci-dessus : un poste redémarré ne répond
#             plus aux sondes et sa session est fermée en moins d'une minute et demie.
#
# deadtime  : ferme les connexions inactives. Exprimé en MINUTES.
#
#   ── 2 minutes, et il faut s'y tenir ───────────────────────────────────────
#   Cette valeur a été portée à 30 le 28/07, en soupçonnant qu'elle faisait échouer
#   la capture d'image master (« erreur 6, descripteur non valide », à 31 %). Le
#   soupçon était FAUX : la capture a échoué exactement de la même façon avec 30
#   minutes. En revanche l'allongement a immédiatement produit une « erreur système
#   53 » au montage suivant — précisément ce que ce réglage sert à empêcher.
#
#   Un poste qui redémarre ne ferme pas sa session : Samba la garde ouverte pendant
#   toute la durée de deadtime, et Windows, qui réutilise le même port source,
#   retombe sur une connexion fantôme. Deux minutes est le bon ordre de grandeur.
#
#   Leçon : ne pas modifier un réglage sur une hypothèse non vérifiée. Ici le
#   correctif portait ailleurs (répertoire de travail de DISM, puis capture écrite
#   en local), et ce détour a coûté une panne supplémentaire.
grep -q 'keepalive' /etc/samba/smb.conf || \
  sed -i '/\[global\]/a\	keepalive = 30\n\tdeadtime = 2' /etc/samba/smb.conf
# Passerelles déjà installées : remettre la valeur correcte si une version
# intermédiaire l'a allongée. smb.conf n'est pas réécrit par une mise à jour du
# dépôt, il est modifié en place.
sed -i 's/^\(\s*\)deadtime\s*=\s*[0-9]\+/\1deadtime = 2/' /etc/samba/smb.conf

echo "[AD] resolv.conf → dnsmasq (résout ${REALM} + externe), persistant…"
chattr -i /etc/resolv.conf 2>/dev/null || true
printf 'search %s\nnameserver 127.0.0.1\n' "${REALM,,}" > /etc/resolv.conf
chattr +i /etc/resolv.conf 2>/dev/null || true

echo "[AD] Démarrage du contrôleur de domaine…"
systemctl unmask samba-ad-dc >/dev/null 2>&1 || true
systemctl enable --now samba-ad-dc
sleep 5
# Un redémarrage propre après provisioning fiabilise la liaison du DNS interne
# (sinon le DNS peut rester figé, surtout lors d'une recréation).
systemctl restart samba-ad-dc
sleep 6
systemctl is-active --quiet samba-ad-dc && echo "  samba-ad-dc actif" || { echo "  ECHEC — voir: journalctl -u samba-ad-dc"; exit 1; }

echo "[AD] Ouverture de l'accès au contrôleur de domaine (.${DNS_IP}) pour les postes…"
# .2 est une IP DU ROUTEUR → l'accès des clients passe par ndsRTR (users_to_router),
# et non par le walled garden. On accepte tout trafic vers le DC dans ndsRTR.
nft add element ip nds_filter walledgarden { ${DNS_IP} } 2>/dev/null || true
nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "${DNS_IP}" || \
    nft insert rule ip nds_filter ndsRTR ip daddr ${DNS_IP} counter accept 2>/dev/null || true

echo "[AD] Groupe par défaut « Fonctionnaires »…"
samba-tool group add Fonctionnaires 2>/dev/null || true

echo "[AD] OK. Domaine ${REALM} créé."
echo "     Postes membres : DNS = ${DNS_IP}, domaine ${DOMAIN}. Partages : \\\\${DNS_IP}\\Commun."
# Affiché UNE SEULE FOIS, à la création du domaine. Ensuite, il n'est plus lisible que
# dans /etc/proxyfibre/ad.env (600, root) — et surtout, il n'est plus dans le dépôt.
if [ "${AD_PASS_GENERE:-0}" = "1" ]; then
    echo
    echo "  ╔══════════════════════════════════════════════════════════════════╗"
    echo "  ║  ADMINISTRATEUR DU DOMAINE — À NOTER MAINTENANT                  ║"
    echo "  ╠══════════════════════════════════════════════════════════════════╣"
    printf '  ║  Compte      : %-49s ║\n' "${DOMAIN}\\Administrator"
    printf '  ║  Mot de passe : %-48s ║\n' "${ADMINPASS}"
    echo "  ║                                                                  ║"
    echo "  ║  Engendré au hasard. Relisible dans /etc/proxyfibre/ad.env       ║"
    echo "  ║  (accès root). Sert à joindre les postes au domaine.             ║"
    echo "  ╚══════════════════════════════════════════════════════════════════╝"
    echo
fi
