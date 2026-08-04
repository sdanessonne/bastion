#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — déploiement de la Phase 1 sur Debian 13 (OpenNDS + FreeRADIUS +
# dnsmasq + Apache/PHP). Idempotent. À lancer sur la passerelle : sudo ./deploy.sh
#
# Remplace l'ancien install.sh (basé CoovaChilli, indisponible sur Debian 13).
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/config.env"
SECRETS_ENV="/etc/proxyfibre/secrets.env"

log(){ printf '\033[1;32m[Bastion]\033[0m %s\n' "$*"; }
die(){ printf '\033[1;31m[Bastion]\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "Lancer en root (sudo)."

# ── Où vit le code ───────────────────────────────────────────────────────────
# selfupdate, selftest et import-media codaient ce chemin en dur, sur
# /home/proxyfibre/proxyFibre. Une installation faite depuis un compte portant un
# autre nom donnait un serveur parfaitement fonctionnel mais INCAPABLE de se
# mettre à jour, et un auto-test qui ne contrôlait rien — sans qu'aucun des deux
# ne s'en plaigne. Ce script, lui, sait où il est : on l'écrit une fois pour
# toutes, et les autres le lisent.
mkdir -p /etc/proxyfibre
printf 'REPO_DIR=%s\n' "$REPO_DIR" > /etc/proxyfibre/repo.env
chmod 644 /etc/proxyfibre/repo.env

# ── Secrets ──────────────────────────────────────────────────────────────────
gen(){ head -c 32 /dev/urandom | sha256sum | cut -c1-64; }
# Mot de passe lisible et TYPOGRAPHIABLE : il sera tapé à la main. Alphabet sans
# caractère ambigu (ni O/0, ni I/l/1), présenté en groupes de 5.
#
# La composition est IMPOSÉE, pas espérée. MESURÉ sur 2000 tirages : un tirage
# purement aléatoire sur cet alphabet ne contient AUCUN chiffre dans 4,5 % des cas
# (il n'y a que 8 chiffres pour 55 caractères). Samba refuse alors le mot de passe et
# la création du domaine échoue — une fois sur 22, au hasard, sans rien d'explicable.
# On force donc 2 majuscules, 2 minuscules et 2 chiffres, on complète depuis
# /dev/urandom, puis on mélange (sans quoi les 6 premiers caractères suivraient
# toujours le même motif). ~100 bits d'entropie.
genpass(){
  local maj='ABCDEFGHJKMNPQRSTUVWXYZ' min='abcdefghijkmnpqrstuvwxyz' chi='23456789'
  local tout p='' i
  tout="${maj}${min}${chi}"
  for i in 1 2; do p+="$(tr -dc "$maj" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p+="$(tr -dc "$min" < /dev/urandom | head -c1)"; done
  for i in 1 2; do p+="$(tr -dc "$chi" < /dev/urandom | head -c1)"; done
  p+="$(tr -dc "$tout" < /dev/urandom | head -c 14)"
  printf '%s' "$p" | fold -w1 | shuf | tr -d '\n' | sed 's/.\{5\}/&-/g; s/-$//'
}
if [[ -f "$SECRETS_ENV" ]]; then source "$SECRETS_ENV"; else
  RADIUS_SECRET="$(gen)"; FAS_KEY="$(gen)"; DB_PASS="$(gen)"
  umask 077
  printf 'RADIUS_SECRET="%s"\nFAS_KEY="%s"\nDB_PASS="%s"\n' "$RADIUS_SECRET" "$FAS_KEY" "$DB_PASS" > "$SECRETS_ENV"
  log "Secrets générés → $SECRETS_ENV"
fi

# Mot de passe de la console : ENGENDRÉ s'il n'est pas fourni, jamais codé en dur.
# Un mot de passe par défaut inscrit dans le dépôt serait connu de quiconque lit le
# code : toute passerelle installée sans le changer serait ouverte. Et un défaut
# VIDE serait pire encore — la console n'aurait plus de mot de passe du tout.
# Il est conservé pour que ré-exécuter deploy.sh ne le change pas sous les pieds
# de l'administrateur.
ADMIN_PASS_ENV=/etc/proxyfibre/admin-pass.env
ADMIN_PASS_GENERE=0
if [[ -z "${ADMIN_PASS:-}" ]]; then
  if [[ -f "$ADMIN_PASS_ENV" ]]; then
    source "$ADMIN_PASS_ENV"
  else
    ADMIN_PASS="$(genpass)"; ADMIN_PASS_GENERE=1
    umask 077
    printf 'ADMIN_PASS="%s"\n' "$ADMIN_PASS" > "$ADMIN_PASS_ENV"
  fi
fi

# ── Détection du répertoire FreeRADIUS (3.0 / 3.2) ───────────────────────────
RLMDIR="$(ls -d /etc/freeradius/3.* 2>/dev/null | sort -V | tail -1)"
[[ -d "$RLMDIR" ]] || die "Répertoire FreeRADIUS introuvable."
# Groupe de service (Debian: 'freerad' ; certaines distros: 'freeradius' ou 'radiusd').
FR_GROUP="$(for g in freerad freeradius radiusd; do getent group "$g" >/dev/null && { echo "$g"; break; }; done)"
FR_GROUP="${FR_GROUP:-root}"
log "FreeRADIUS : $RLMDIR (groupe $FR_GROUP)"

# ── Interface LAN : IP statique (requise par OpenNDS) ────────────────────────
if ! ip -4 addr show "$LAN_IF" | grep -q "${LAN_IP}/"; then
  if command -v nmcli >/dev/null && nmcli -t dev status | grep -q "^${LAN_IF}:"; then
    nmcli con delete proxyfibre-lan >/dev/null 2>&1 || true
    nmcli con add type ethernet ifname "$LAN_IF" con-name proxyfibre-lan \
      ipv4.method manual ipv4.addresses "${LAN_IP}/${LAN_CIDR}" \
      ipv4.never-default yes connection.autoconnect yes >/dev/null
    nmcli con up proxyfibre-lan >/dev/null
  else
    ip addr add "${LAN_IP}/${LAN_CIDR}" dev "$LAN_IF" 2>/dev/null || true
    ip link set "$LAN_IF" up
  fi
  log "IP LAN ${LAN_IP}/${LAN_CIDR} sur ${LAN_IF}"
fi

# ── Routage + NAT (nftables — Debian 13 n'a pas iptables par défaut) ─────────
install -D -m644 "${REPO_DIR}/services/sysctl/proxyfibre.conf" /etc/sysctl.d/99-proxyfibre.conf
sysctl --system >/dev/null
# ── Garde réseau : NAT + DNS forcé + repli sur panne ─────────────────────────
# Table dédiée (n'interfère pas avec celles d'OpenNDS). Le fichier nat.nft est
# désormais ENGENDRÉ par netguard.sh : l'état du repli doit être calculé d'après
# l'état réel d'OpenNDS, ce qu'un fichier statique ne peut pas faire.
install -D -m755 "${REPO_DIR}/services/scripts/netguard.sh" /usr/local/sbin/proxyfibre-netguard
install -D -m644 "${REPO_DIR}/services/filter/doh-resolvers.txt" /etc/proxyfibre/doh-resolvers.txt
# Limitation de débit par poste. pf_groups portait déjà down_rate_kbps/up_rate_kbps et
# le hook BinAuth les transmettait à OpenNDS — mais OpenNDS NE MET PAS EN FORME le trafic
# (aucune référence à tc/htb dans son binaire) : ces réglages n'avaient aucun effet.
install -D -m755 "${REPO_DIR}/services/scripts/qos-ctl.sh" /usr/local/sbin/proxyfibre-qos
cat > /etc/proxyfibre/net.env <<ENV
WAN_IF="${WAN_IF}"
LAN_IF="${LAN_IF}"
LAN_IP="${LAN_IP}"
LAN_NET="${LAN_NET}"
LAN_CIDR="${LAN_CIDR}"
AD_DNS_IP="${AD_DNS_IP:-192.168.182.2}"
ENV
chmod 644 /etc/proxyfibre/net.env

# ── Bannière de la console locale ────────────────────────────────────────────
# L'écran affiché avant le prompt de connexion sur les consoles physiques. Posé ICI,
# après net.env, dont il lit les noms d'interfaces.
install -D -m755 "${REPO_DIR}/services/scripts/issue-banner.sh" /usr/local/sbin/proxyfibre-issue
cat > /etc/systemd/system/proxyfibre-issue.service <<'UNIT'
[Unit]
Description=Bastion - banniere de la console locale
# Après le réseau : les noms d'interfaces doivent être stabilisés quand on écrit
# la bannière. Les ADRESSES, elles, sont relues par agetty à chaque affichage.
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/proxyfibre-issue
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/proxyfibre-splash.service <<'UNIT'
[Unit]
Description=Bastion - logo au demarrage
# Au plus tôt : le logo doit apparaître avant que les services ne démarrent, sinon
# l'utilisateur regarde un écran noir pendant tout l'amorçage.
DefaultDependencies=no
After=systemd-vconsole-setup.service
Before=sysinit.target
Conflicts=shutdown.target
Before=shutdown.target

[Service]
Type=oneshot
# Le « - » ignore un échec : un logo ne doit JAMAIS empêcher une passerelle de
# sécurité de démarrer. Le délai borné vaut pour la même raison.
ExecStart=-/usr/local/sbin/proxyfibre-issue --splash
TimeoutStartSec=5
RemainAfterExit=no

[Install]
WantedBy=sysinit.target
UNIT
systemctl daemon-reload
systemctl enable proxyfibre-issue.service >/dev/null 2>&1 || true
systemctl enable proxyfibre-splash.service >/dev/null 2>&1 || true
/usr/local/sbin/proxyfibre-issue || true

# Démarrage aux couleurs du produit : menu d'amorçage masqué, amorçage silencieux.
# « || true » : un échec de mise en forme de l'amorçage ne doit pas interrompre le
# déploiement d'une passerelle qui, elle, fonctionne. Retour arrière possible avec
# « proxyfibre-brand --defaire ».
install -D -m755 "${REPO_DIR}/services/scripts/boot-brand.sh" /usr/local/sbin/proxyfibre-brand
/usr/local/sbin/proxyfibre-brand >/dev/null 2>&1 || \
    echo "  (mise en forme de l'amorçage ignorée — lancez proxyfibre-brand à la main)"

/usr/local/sbin/proxyfibre-netguard apply
# Persistance au reboot. Au démarrage, OpenNDS n'a pas encore confirmé : le garde
# pose le repli, donc le LAN reste fermé tant que le portail n'est pas opérationnel.
cat > /etc/systemd/system/proxyfibre-nat.service <<'UNIT'
[Unit]
Description=Bastion — garde reseau (NAT, DNS force, repli sur panne)
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/proxyfibre-netguard apply
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
UNIT

# REPLI SUR PANNE du portail captif — drop-in, pour ne pas toucher l'unité du paquet.
# Restart=always et NON on-failure : le plantage vécu (deux IP détectées sur
# l'interface passerelle) faisait sortir OpenNDS avec status=0, une sortie PROPRE.
# systemd tenait donc l'arrêt pour normal et ne redémarrait rien — le portail restait
# mort et le réseau OUVERT, sans authentification ni filtrage, sans aucun signal.
mkdir -p /etc/systemd/system/opennds.service.d
cat > /etc/systemd/system/opennds.service.d/bastion-failclose.conf <<'UNIT'
[Unit]
StartLimitIntervalSec=300
# 10 tentatives, comme l'unité d'origine. Notre surcouche descendait à 5 : trop
# peu, puisque les premières échouent forcément (voir RestartSec ci-dessous) et
# qu'au bout du compte systemd renonçait, laissant le repli actif — Internet
# coupé pour tout le service jusqu'à intervention humaine.
StartLimitBurst=10

[Service]
Restart=always
# ── 20 s, ET PAS 5 : LA VALEUR D'ORIGINE ÉTAIT DÉLIBÉRÉE ─────────────────────
# L'unité fournie par le paquet prévoit RestartSec=20. Notre surcouche l'avait
# ramenée à 5 « pour rétablir plus vite », sans voir qu'OpenNDS ne PEUT PAS
# repartir aussi tôt : il refuse avec « openNDS is already running » pendant une
# vingtaine de secondes après son arrêt, même lorsque plus aucun processus ne
# tourne. MESURÉ sur la passerelle : échecs à 5 s d'intervalle, succès à la
# 22e seconde.
#
# Chaque échec déclenche ExecStopPost, donc recoupe le trafic LAN vers Internet.
# Réessayer trop tôt ne rétablissait donc rien : cela prolongeait la coupure en
# la ponctuant d'échecs, et épuisait le budget de tentatives.
RestartSec=20
# ── LE VERROU FANTÔME QUI COUPE INTERNET ─────────────────────────────────────
# CONSTATÉ EN PRODUCTION le 03/08/2026 : après un redémarrage, OpenNDS a refusé
# de repartir en boucle avec « openNDS is already running, status [ 1 ] », alors
# qu'AUCUN processus ne tournait. L'instance précédente était sortie sans
# effacer sa socket de commande /tmp/ndsctl.sock ; la nouvelle la voyait, se
# croyait en double et abandonnait.
#
# Ce blocage ne se répare pas tout seul : la socket ne disparaît jamais, chaque
# tentative échoue à l'identique, et au bout de 5 échecs systemd renonce. Le
# repli sur panne — voulu — laisse alors le trafic LAN vers Internet COUPÉ pour
# tout le service, jusqu'à intervention humaine. Une socket oubliée de 0 octet
# privait donc le commissariat d'Internet.
#
# La socket n'est retirée QUE si aucun processus opennds ne tourne : l'effacer
# sans vérifier détruirait le canal de commande d'une instance bien vivante, et
# « ndsctl » ne répondrait plus — panne plus difficile encore à diagnostiquer.
#
# ON ATTEND LA SORTIE DE L'ANCIENNE INSTANCE, on n'échoue pas.
# MESURÉ : OpenNDS met une dizaine de secondes à s'arrêter (il purge ses règles
# de pare-feu et ses clients). Un redémarrage relance donc la nouvelle instance
# alors que l'ancienne vit encore : elle voyait la socket, se croyait en double,
# et abandonnait. Le service finissait par repartir — après TROIS échecs, sur les
# cinq que systemd tolère avant de renoncer définitivement. Et chaque échec
# déclenche ExecStopPost, donc recoupe le trafic LAN vers Internet : vingt
# secondes de coupure pour tout le service, à chaque déploiement.
# Attendre jusqu'à 20 s coûte moins qu'un échec, et laisse la marge de systemd
# intacte pour les vraies pannes.
ExecStartPre=/bin/sh -c 'i=0; while pgrep -x opennds >/dev/null && [ $i -lt 20 ]; do sleep 1; i=$((i+1)); done; pgrep -x opennds >/dev/null || rm -f /tmp/ndsctl.sock'
# Le trafic LAN->WAN n'est autorisé QU'APRÈS confirmation du démarrage du portail,
# et recoupé dès son arrêt — y compris un arrêt volontaire depuis la console.
# Si le portail échoue 5 fois en 5 min, systemd renonce : le repli RESTE actif.
ExecStartPost=/usr/local/sbin/proxyfibre-netguard open
ExecStopPost=/usr/local/sbin/proxyfibre-netguard close
UNIT
systemctl daemon-reload
systemctl enable proxyfibre-nat >/dev/null 2>&1 || true
log "Garde reseau OK (NAT ${WAN_IF}, DNS force vers ${LAN_IP}, repli sur panne actif)"

# ── MariaDB + schéma RADIUS + utilisateur de test ────────────────────────────
systemctl enable --now mariadb >/dev/null 2>&1 || true
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
SCHEMA="${RLMDIR}/mods-config/sql/main/mysql/schema.sql"
[[ -f "$SCHEMA" ]] && mysql "${DB_NAME}" < "$SCHEMA"
sed -e "s/\${TEST_USER}/${TEST_USER}/g" -e "s/\${TEST_PASS}/${TEST_PASS}/g" \
    "${REPO_DIR}/services/freeradius/testuser.sql" | mysql "${DB_NAME}"
log "Base RADIUS + utilisateur '${TEST_USER}' OK"

# ── FreeRADIUS : client localhost + backend SQL (MariaDB) ────────────────────
# Les comptes sont lus depuis radcheck/radusergroup (gérables via la console admin).
sed "s/\${RADIUS_SECRET}/${RADIUS_SECRET}/g" \
    "${REPO_DIR}/services/freeradius/clients.conf" > "${RLMDIR}/clients.conf"
chgrp "$FR_GROUP" "${RLMDIR}/clients.conf"
sed "s|__DB_PASS__|${DB_PASS}|" "${REPO_DIR}/services/freeradius/sql" > "${RLMDIR}/mods-enabled/sql"
chgrp "$FR_GROUP" "${RLMDIR}/mods-enabled/sql"
# L'utilisateur de test est inséré dans radcheck (voir testuser.sql ci-dessus) ;
# on le retire du fichier "files" pour que SQL fasse foi.
sed -i "/^${TEST_USER}[[:space:]]/d" "${RLMDIR}/mods-config/files/authorize" 2>/dev/null || true
log "FreeRADIUS configuré (backend SQL activé)"

# ── Portail (Apache + PHP) sur le port 2080 ──────────────────────────────────
install -d -m755 /var/www/html/portal
cp -r "${REPO_DIR}/portal/." /var/www/html/portal/
# favicon à la racine du docroot (requête /favicon.ico par défaut des navigateurs)
[ -f /var/www/html/portal/assets/favicon.ico ] && cp /var/www/html/portal/assets/favicon.ico /var/www/html/favicon.ico
# Médiathèque de l'intranet CMS (uploads d'images), inscriptible par le serveur web.
install -d -o www-data -g www-data -m775 /var/www/html/portal/intranet/uploads
find /var/www/html/portal -type f -exec chmod 644 {} +
find /var/www/html/portal -type d -exec chmod 755 {} +
install -D -m640 /dev/null /etc/proxyfibre/portal.env
printf 'FAS_KEY="%s"\nRADIUS_SECRET="%s"\n' "$FAS_KEY" "$RADIUS_SECRET" > /etc/proxyfibre/portal.env
chgrp www-data /etc/proxyfibre/portal.env
# Autoriser Apache (www-data) à interroger OpenNDS pour le tableau de bord utilisateur.
# Deconnexion d'un client : ndsctl NE SUFFIT PAS. Il retire les regles de pare-feu
# mais laisse vivre les connexions deja etablies dans le suivi de connexions du
# noyau -- le client continue de naviguer sous une session officiellement close.
# « conntrack » est donc un prerequis, pas un agrement.
DEBIAN_FRONTEND=noninteractive apt-get install -y conntrack >/dev/null 2>&1 || true
install -m755 "${REPO_DIR}/services/scripts/deauth.sh" /usr/local/sbin/proxyfibre-deauth
cat > /etc/sudoers.d/proxyfibre-portal <<'SUD'
# « ndsctl json * » et pas seulement « ndsctl json ». La regle sans joker
# n'autorisait que la commande NUE : l'appel reel, « ndsctl json <ip> », etait
# refuse par sudo. Le portail ne recevait donc rien et concluait que PERSONNE
# n'etait authentifie -- pour tous les agents, en permanence. Un commentaire du
# code decrivait deja le symptome (« l'agent paraissait deconnecte ») sans que
# la cause, la regle sudo, ait ete trouvee.
www-data ALL=(root) NOPASSWD: /usr/bin/ndsctl json, /usr/bin/ndsctl json *, /usr/bin/ndsctl deauth *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-deauth *
SUD
chmod 440 /etc/sudoers.d/proxyfibre-portal
# Apache écoute sur 2080 (OpenNDS réserve 80/443 pour la capture).
grep -q '^Listen 2080$' /etc/apache2/ports.conf || sed -i 's/^Listen 80$/Listen 2080/' /etc/apache2/ports.conf
# Désactiver PrivateTmp : sinon Apache a un /tmp isolé et PHP ne voit pas la
# socket /tmp/ndsctl.sock d'OpenNDS (nécessaire au tableau de bord utilisateur).
install -d /etc/systemd/system/apache2.service.d
printf '[Service]\nPrivateTmp=false\n' > /etc/systemd/system/apache2.service.d/override.conf
systemctl daemon-reload
install -m644 "${REPO_DIR}/services/apache/proxyfibre.conf" /etc/apache2/sites-available/proxyfibre.conf
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite proxyfibre >/dev/null 2>&1 || true
# Portail en HTTPS (port 2443) : certificat auto-signé (CN + SAN = IP LAN) + vhost SSL.
# OpenNDS redirige en HTTP:2080 ; fas.php rebondit vers HTTPS:2443 (voir https_guard.php).
a2enmod ssl >/dev/null 2>&1 || true
# Certificat Bastion (CA + serveur, valide jusqu'en 2040) : console, portail, blocage.
install -m755 "${REPO_DIR}/services/scripts/make-web-cert.sh" /usr/local/sbin/proxyfibre-make-web-cert
/usr/local/sbin/proxyfibre-make-web-cert "${LAN_IP}" "${DC_IP:-192.168.182.2}" || true
grep -q '^Listen 2443$' /etc/apache2/ports.conf || echo "Listen 2443" >> /etc/apache2/ports.conf
install -m644 "${REPO_DIR}/services/apache/portal-ssl.conf" /etc/apache2/sites-available/proxyfibre-portal-ssl.conf
a2ensite proxyfibre-portal-ssl >/dev/null 2>&1 || true

# ── L'INDEX DEBIAN N'A RIEN A FAIRE SUR UNE PASSERELLE EN SERVICE ────────────
# Le vhost du portail a pour racine /var/www/html, ou Debian laisse son
# « Apache2 Debian Default Page ». Un agent qui saisit l'adresse de la passerelle
# sans chemin -- le cas courant -- tombait dessus et la croyait en panne. Le vhost
# redirige desormais « / », et ce fichier couvre « /index.html », demande
# explicitement par certains navigateurs et par les detections de portail captif.
if [[ -f /var/www/html/index.html ]] && grep -qi 'Debian Default Page' /var/www/html/index.html 2>/dev/null; then
  cat > /var/www/html/index.html <<'IDXEOF'
<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<title>Bastion — portail</title>
<meta http-equiv="refresh" content="0; url=/portal/fas.php"></head>
<body><p>Redirection vers le <a href="/portal/fas.php">portail Bastion</a>…</p></body></html>
IDXEOF
  chmod 644 /var/www/html/index.html
  log "Page Apache par défaut remplacée par une redirection vers le portail"
fi

log "Portail FAS déployé (http://${LAN_IP}:2080/portal/fas.php)"

# ── OpenNDS (config UCI /etc/config/opennds — Debian ignore le fichier legacy) ─
mkdir -p /etc/config
sed -e "s|__LAN_IF__|${LAN_IF}|g" -e "s|__LAN_IP__|${LAN_IP}|g" -e "s|__FAS_KEY__|${FAS_KEY}|g" \
    "${REPO_DIR}/services/opennds/opennds.uci" | sed 's/\r$//' > /etc/config/opennds
log "OpenNDS configuré en UCI (GatewayInterface=${LAN_IF}, FAS 2080)"

# ── dnsmasq (DHCP + DNS du LAN) ──────────────────────────────────────────────
sed -e "s|__LAN_IF__|${LAN_IF}|g" -e "s|__LAN_IP__|${LAN_IP}|g" \
    -e "s|__DHCP_START__|${DHCP_START}|g" -e "s|__DHCP_END__|${DHCP_END}|g" \
    -e "s|__LAN_MASK__|${LAN_MASK}|g" \
    "${REPO_DIR}/services/dnsmasq/proxyfibre.conf" > /etc/dnsmasq.d/proxyfibre.conf

# ── Console d'administration (Apache vhost séparé, port 8080) ────────────────
install -d -m755 /var/www/admin
cp -r "${REPO_DIR}/admin/." /var/www/admin/
[ -f /var/www/admin/assets/favicon.ico ] && cp /var/www/admin/assets/favicon.ico /var/www/admin/favicon.ico
find /var/www/admin -type f -exec chmod 644 {} +
find /var/www/admin -type d -exec chmod 755 {} +
# Dossier des médias téléversés par l'admin (aperçu du fond d'écran, etc.) : accessible en écriture à www-data.
install -d -o www-data -g www-data -m755 /var/www/admin/media
install -D -m640 /dev/null /etc/proxyfibre/admin.env
printf 'DB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > /etc/proxyfibre/admin.env
chgrp www-data /etc/proxyfibre/admin.env
# Table + compte administrateur par défaut (avec double authentification TOTP)
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_admins (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) UNIQUE, password_hash VARCHAR(255), totp_secret VARCHAR(64) DEFAULT NULL, totp_enabled TINYINT(1) NOT NULL DEFAULT 0);"
# Migration des installations antérieures (colonnes 2FA)
mysql "${DB_NAME}" -e "ALTER TABLE pf_admins ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0;" 2>/dev/null || true
# Photo de profil : image PNG ré-encodée (MEDIUMBLOB) + version courte pour le cache.
# Créées aussi à la volée par le code web (avatar_migre), car selfupdate ne rejoue pas ce script.
mysql "${DB_NAME}" -e "ALTER TABLE pf_admins ADD COLUMN IF NOT EXISTS avatar MEDIUMBLOB DEFAULT NULL, ADD COLUMN IF NOT EXISTS avatar_v VARCHAR(16) DEFAULT NULL;" 2>/dev/null || true
# php-gd est indispensable au ré-encodage des photos (installations déjà en service).
dpkg -s php-gd >/dev/null 2>&1 || apt-get install -y php-gd >/dev/null 2>&1 || true
ADMIN_HASH="$(PF_AP="$ADMIN_PASS" php -r "echo password_hash(getenv('PF_AP'), PASSWORD_DEFAULT);")"
# ── LE DÉPLOIEMENT NE TOUCHE PLUS À UN MOT DE PASSE EXISTANT ─────────────────
# CONSTATÉ EN PRODUCTION : cette ligne portait « ON DUPLICATE KEY UPDATE
# password_hash=... ». Chaque déploiement RÉÉCRIVAIT donc le condensat avec le mot
# de passe d'installation, annulant en silence celui que l'administrateur avait
# choisi dans la console.
#
# Le résultat est le pire possible : le mot de passe d'origine — celui qui figure
# en clair dans /etc/proxyfibre/admin-pass.env, celui qu'on a pu communiquer à
# l'installation, celui qui traîne dans un courriel — REDEVIENT valide après
# chaque mise à jour. L'administrateur croit l'avoir changé, la console le lui a
# confirmé, et il redevient bon sans que rien ne l'annonce.
#
# Le compte n'est donc plus créé qu'en son ABSENCE. Réinitialiser un mot de passe
# oublié reste possible, mais doit être un acte délibéré — « proxyfibre-admin-passwd » —
# et non l'effet de bord d'un déploiement.
mysql "${DB_NAME}" -e "INSERT INTO pf_admins (username,password_hash) VALUES ('${ADMIN_USER}','${ADMIN_HASH}') ON DUPLICATE KEY UPDATE username=username;"
# Jetons d'API, générés une seule fois chacun (INSERT IGNORE : un redéploiement ne les
# change pas, sans quoi tous les postes déjà configurés perdraient l'accès).
#   api_token     : serveur central — ouvre TOUTE l'API.
#   station_token : stations blanches — n'ouvre QUE le dépôt de résultats d'analyse.
# Deux jetons distincts parce qu'une station blanche est une machine en libre accès dans
# un couloir : si son jeton fuit, il ne doit donner ni les comptes, ni les journaux.
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_settings (k VARCHAR(64) PRIMARY KEY, v TEXT);
INSERT IGNORE INTO pf_settings (k,v) VALUES ('api_token', SHA2(CONCAT(RAND(),UUID(),NOW(6)),256));
INSERT IGNORE INTO pf_settings (k,v) VALUES ('station_token', SHA2(CONCAT(RAND(),UUID(),NOW(6)),256));"
# Journal des analyses antivirus. Créé ici, et pas seulement à l'ouverture de la page
# antivirus.php : une station blanche peut remonter un résultat avant qu'un administrateur
# n'ait jamais ouvert cette page, et l'insertion échouerait — la trace serait perdue.
# Schéma volontairement IDENTIQUE à celui d'antivirus.php : « IF NOT EXISTS » ne corrige
# pas une divergence, il la masque.
mysql "${DB_NAME}" -e "CREATE TABLE IF NOT EXISTS pf_avscan (
  id INT AUTO_INCREMENT PRIMARY KEY, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  path VARCHAR(255), scanned INT DEFAULT 0, infected INT DEFAULT 0, detail TEXT, launched_by VARCHAR(64));"
grep -q '^Listen 8080$' /etc/apache2/ports.conf || echo "Listen 8080" >> /etc/apache2/ports.conf
sed -e "s|__LAN_NET__|${LAN_NET}|g" -e "s|__LAN_CIDR__|${LAN_CIDR}|g" \
    "${REPO_DIR}/services/apache/admin.conf" > /etc/apache2/sites-available/proxyfibre-admin.conf
a2ensite proxyfibre-admin >/dev/null 2>&1 || true
# HTTPS auto-signé pour la console admin (port 8443).
if [[ ! -f /etc/proxyfibre/admin.crt ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout /etc/proxyfibre/admin.key -out /etc/proxyfibre/admin.crt \
    -subj "/CN=proxyfibre-admin" >/dev/null 2>&1 || true
  chmod 640 /etc/proxyfibre/admin.key 2>/dev/null || true
fi
a2enmod ssl >/dev/null 2>&1 || true
grep -q '^Listen 8443$' /etc/apache2/ports.conf || echo "Listen 8443" >> /etc/apache2/ports.conf
sed -e "s|__LAN_NET__|${LAN_NET}|g" -e "s|__LAN_CIDR__|${LAN_CIDR}|g" \
    "${REPO_DIR}/services/apache/admin-ssl.conf" > /etc/apache2/sites-available/proxyfibre-admin-ssl.conf 2>/dev/null && \
  a2ensite proxyfibre-admin-ssl >/dev/null 2>&1 || true
log "Console admin déployée (port 8080, compte ${ADMIN_USER})"

# ── Phases 3-5 : filtrage, quotas/horaires, journalisation ───────────────────
# Tables
mysql "${DB_NAME}" <<'SQL'
CREATE TABLE IF NOT EXISTS pf_blocklist (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) UNIQUE, category VARCHAR(64) DEFAULT 'manuel', added_by VARCHAR(64), added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS pf_groups (groupname VARCHAR(64) PRIMARY KEY, session_timeout_min INT DEFAULT 0, down_rate_kbps INT DEFAULT 0, up_rate_kbps INT DEFAULT 0, down_quota_mb INT DEFAULT 0, up_quota_mb INT DEFAULT 0, hours_start INT DEFAULT 0, hours_end INT DEFAULT 24);
CREATE TABLE IF NOT EXISTS pf_connlog (id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64), groupname VARCHAR(64), mac VARCHAR(17), ip VARCHAR(45), event VARCHAR(16), bytes_in BIGINT DEFAULT 0, bytes_out BIGINT DEFAULT 0, duration_s INT DEFAULT 0, ts DATETIME, INDEX(username), INDEX(ts));
CREATE TABLE IF NOT EXISTS pf_weblog (id BIGINT AUTO_INCREMENT PRIMARY KEY, ts DATETIME, client_ip VARCHAR(45), username VARCHAR(64), domain VARCHAR(255), INDEX(username), INDEX(ts), INDEX(domain));
CREATE TABLE IF NOT EXISTS pf_commissariats (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(96) UNIQUE, cpn VARCHAR(96) DEFAULT '');
CREATE TABLE IF NOT EXISTS pf_user_site (username VARCHAR(64) PRIMARY KEY, commissariat_id INT, INDEX(commissariat_id));
CREATE TABLE IF NOT EXISTS pf_user_profile (username VARCHAR(64) PRIMARY KEY, nom VARCHAR(96) DEFAULT '', prenom VARCHAR(96) DEFAULT '', service VARCHAR(96) DEFAULT '', INDEX(nom), INDEX(service));
CREATE TABLE IF NOT EXISTS pf_apps (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(96), description TEXT, filename VARCHAR(160), args VARCHAR(255) DEFAULT '', icon VARCHAR(16) DEFAULT '📦', deployed TINYINT(1) NOT NULL DEFAULT 0, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
INSERT IGNORE INTO pf_groups (groupname) VALUES ('default');
-- Commissariats de l'Essonne (6 circonscriptions de police nationale — éditables depuis la console).
INSERT IGNORE INTO pf_commissariats (name,cpn) VALUES
 ("Évry-Courcouronnes","CPN Évry-Corbeil"),("Corbeil-Essonnes","CPN Évry-Corbeil"),("Ris-Orangis","CPN Évry-Corbeil"),
 ("Vigneux-sur-Seine","CPN Val d'Yerres-Val de Seine"),("Montgeron","CPN Val d'Yerres-Val de Seine"),("Draveil","CPN Val d'Yerres-Val de Seine"),
 ("Juvisy-sur-Orge","CPN Juvisy-sur-Orge"),("Athis-Mons","CPN Juvisy-sur-Orge"),("Savigny-sur-Orge","CPN Juvisy-sur-Orge"),("Viry-Châtillon","CPN Juvisy-sur-Orge"),
 ("Massy","CPN Massy-Palaiseau"),("Palaiseau","CPN Massy-Palaiseau"),("Les Ulis","CPN Massy-Palaiseau"),
 ("Sainte-Geneviève-des-Bois","CPN Sainte-Geneviève-des-Bois"),("Brétigny-sur-Orge","CPN Sainte-Geneviève-des-Bois"),("Arpajon","CPN Sainte-Geneviève-des-Bois"),("Longjumeau","CPN Sainte-Geneviève-des-Bois"),
 ("Étampes","CPN Étampes"),("Dourdan","CPN Étampes");
SQL
# Filtrage DNS : scripts + sudoers + génération initiale
install -m755 "${REPO_DIR}/services/scripts/apply-filter.sh" /usr/local/sbin/proxyfibre-apply-filter
# Catégories thématiques de filtrage (listes YAML, activables depuis l'admin)
[ -f "${REPO_DIR}/services/filter/categories.yaml" ] && install -D -m644 "${REPO_DIR}/services/filter/categories.yaml" /etc/proxyfibre/categories.yaml
install -m755 "${REPO_DIR}/services/scripts/update-adblock.sh" /usr/local/sbin/proxyfibre-update-adblock
cat > /etc/sudoers.d/proxyfibre-filter <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-apply-filter
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-update-adblock enable, /usr/local/sbin/proxyfibre-update-adblock disable
SUD
chmod 440 /etc/sudoers.d/proxyfibre-filter
# Page d'information de blocage (les domaines filtrés résolvent vers la passerelle).
install -d -o www-data -g www-data -m755 /var/www/block
install -m644 "${REPO_DIR}/portal/block.php" /var/www/block/block.php
install -m644 "${REPO_DIR}/services/apache/block.conf" /etc/apache2/sites-available/proxyfibre-block.conf
a2ensite proxyfibre-block >/dev/null 2>&1 || true
/usr/local/sbin/proxyfibre-apply-filter || true

# Point d'accès Wi-Fi pilotable depuis la console (SSID + phrase secrète).
# Le script LIT la base ; rien ne transite par la ligne de commande — une phrase
# secrète passée en argument apparaîtrait dans « ps » et dans les journaux du shell.
install -m755 "${REPO_DIR}/services/scripts/wifi-ctl.sh" /usr/local/sbin/proxyfibre-wifi
# Listes noires de contenu (universite de Toulouse). Le script ne prend aucune donnee
# du web : les categories retenues sont lues dans pf_settings.
install -m755 "${REPO_DIR}/services/scripts/blacklist-ctl.sh" /usr/local/sbin/proxyfibre-blacklist
cat > /etc/sudoers.d/proxyfibre-blacklist <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-blacklist check, /usr/local/sbin/proxyfibre-blacklist update, /usr/local/sbin/proxyfibre-blacklist rebuild, /usr/local/sbin/proxyfibre-blacklist state, /usr/local/sbin/proxyfibre-blacklist categories
SUD
chmod 440 /etc/sudoers.d/proxyfibre-blacklist
cat > /etc/sudoers.d/proxyfibre-wifi <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-wifi apply, /usr/local/sbin/proxyfibre-wifi state, /usr/local/sbin/proxyfibre-wifi scan
SUD
chmod 440 /etc/sudoers.d/proxyfibre-wifi

# Sortie par tunnel : la console lit l'état et vérifie l'adresse de sortie, mais
# ne monte ni ne démonte le tunnel elle-même. « import » manipule une clé privée
# et « up/down » coupent l'accès d'un groupe entier : ces trois-là restent à la
# main d'un administrateur système sur la machine, pas derrière un bouton web.
# Les arguments sont listés explicitement — une règle sur le seul nom de commande
# autoriserait « proxyfibre-vpn down », qui n'est pas dans cette liste.
# Depot de la configuration par la console : un SEUL chemin autorise.
# « import » recopie le fichier vers /etc/wireguard. Si le chemin etait libre
# dans sudoers, www-data pourrait faire recopier n'importe quel fichier lisible
# par root. Le chemin est donc fige ici ET verifie par le script lui-meme.
install -d -m 0750 -o www-data -g root /run/bastion
printf 'd /run/bastion 0750 www-data root -
' > /usr/lib/tmpfiles.d/bastion.conf
cat > /etc/sudoers.d/proxyfibre-vpn <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-admx list, /usr/local/sbin/proxyfibre-maj state, /usr/local/sbin/proxyfibre-mail state, /usr/local/sbin/proxyfibre-mail test *, /usr/local/sbin/proxyfibre-mail config, /usr/local/sbin/proxyfibre-wanip state, /usr/local/sbin/proxyfibre-vpn state, /usr/local/sbin/proxyfibre-vpn check, /usr/local/sbin/proxyfibre-vpn clients, /usr/local/sbin/proxyfibre-vpn up, /usr/local/sbin/proxyfibre-vpn down, /usr/local/sbin/proxyfibre-vpn import /run/bastion/vpn-import.conf
SUD
chmod 440 /etc/sudoers.d/proxyfibre-vpn

# Pilotage des services depuis la console admin (liste blanche stricte)
install -m755 "${REPO_DIR}/services/scripts/service-ctl.sh" /usr/local/sbin/proxyfibre-service
install -m755 "${REPO_DIR}/services/scripts/apt-ctl.sh"     /usr/local/sbin/proxyfibre-apt
install -m755 "${REPO_DIR}/services/scripts/selfupdate.sh"  /usr/local/sbin/proxyfibre-selfupdate
install -m755 "${REPO_DIR}/services/scripts/update-conf.sh" /usr/local/sbin/proxyfibre-update-conf
# Changement du mot de passe système depuis la console (Système → Compte système).
install -m750 "${REPO_DIR}/services/scripts/syspasswd.sh"  /usr/local/sbin/proxyfibre-syspasswd
# Redémarrage / arrêt de la passerelle depuis le menu de la console.
install -m755 "${REPO_DIR}/services/scripts/power-ctl.sh"  /usr/local/sbin/proxyfibre-power
# Recherche quotidienne d'une mise à jour : garde l'état « en retard » frais, pour que la
# console puisse afficher une popup quand une version est disponible.
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-updatecheck.service" /etc/systemd/system/proxyfibre-updatecheck.service
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-updatecheck.timer"   /etc/systemd/system/proxyfibre-updatecheck.timer
systemctl daemon-reload
systemctl enable --now proxyfibre-updatecheck.timer >/dev/null 2>&1 || true
# Mesure de la ligne Internet : la passerelle ne peut pas connaître sa capacité autrement.
install -m755 "${REPO_DIR}/services/scripts/speedtest-wan.sh" /usr/local/sbin/proxyfibre-speedtest

# ── Sortie Internet par tunnel, réservée à un groupe ─────────────────────────
# Le besoin : consulter en source ouverte le profil d'un mis en cause sans que
# l'adresse publique du commissariat apparaisse dans les journaux du site visité.
# Réservé à un GROUPE : faire sortir tout le réseau par un tunnel casserait les
# applications métier et les accès ministériels qui filtrent par adresse source.
dpkg -s wireguard-tools >/dev/null 2>&1 || \
  DEBIAN_FRONTEND=noninteractive apt-get install -y wireguard-tools >/dev/null 2>&1 || true
install -m755 "${REPO_DIR}/services/scripts/vpn-ctl.sh" /usr/local/sbin/proxyfibre-vpn
# Adresse vue de l'exterieur : relevee par la minuterie, JAMAIS pendant le rendu
# d'une page. Deux attentes reseau au milieu du tableau de bord rendraient la
# console lente pour une information de confort.
install -m755 "${REPO_DIR}/services/scripts/wanip.sh" /usr/local/sbin/proxyfibre-wanip
# État des mises à jour, relevé par la minuterie et LU par le tableau de bord :
# interroger apt à chaque affichage ajouterait une attente pour un compteur.
install -m755 "${REPO_DIR}/services/scripts/maj-state.sh" /usr/local/sbin/proxyfibre-maj
# Modeles ADMX dans le magasin central du SYSVOL : sans lui, chaque poste
# d administration ne voit que les modeles presents sur SA machine.
install -m755 "${REPO_DIR}/services/scripts/admx-ctl.sh" /usr/local/sbin/proxyfibre-admx

# ── Image de reference : generalisation + personnalisation ───────────────────
# Cloner un serveur EN SERVICE serait une faute : ses secrets, son annuaire
# nominatif et ses journaux de navigation partiraient sur chaque exemplaire, et
# deux controleurs de domaine issus du meme clone se croiraient le meme.
# Le modele retenu est donc : un gabarit GENERALISE (sysprep) dont chaque copie
# se donne son identite au premier demarrage (firstboot).
install -m755 "${REPO_DIR}/services/scripts/sysprep.sh"   /usr/local/sbin/proxyfibre-sysprep
install -m755 "${REPO_DIR}/services/scripts/firstboot.sh" /usr/local/sbin/proxyfibre-firstboot
# growpart : sans lui, une image copiee sur un disque plus grand laisse l'espace
# inutilise -- et l'on s'en apercoit le jour ou les journaux saturent une
# partition qu'on croyait spacieuse.
dpkg -s cloud-guest-utils >/dev/null 2>&1 ||   DEBIAN_FRONTEND=noninteractive apt-get install -y cloud-guest-utils >/dev/null 2>&1 || true

cat > /etc/systemd/system/bastion-firstboot.service <<'UNIT'
[Unit]
Description=Bastion — personnalisation au premier demarrage
# Le reseau doit etre la : le deploiement installe des paquets et interroge des
# depots. Sans cette attente, la personnalisation echouerait sur une machine
# pourtant correctement cablee.
After=network-online.target
Wants=network-online.target
ConditionPathExists=!/var/lib/bastion/firstboot-done

[Service]
Type=oneshot
RemainAfterExit=yes
# Sortie sur la CONSOLE : le technicien est devant la machine, il doit voir ce
# qui se passe et relever le mot de passe engendre. Un journal seul l'obligerait
# a savoir qu'il existe.
StandardInput=tty
StandardOutput=journal+console
StandardError=journal+console
TTYPath=/dev/tty1
TTYReset=yes
ExecStart=/usr/local/sbin/proxyfibre-firstboot
TimeoutStartSec=1800

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload

# ── Envoi des alertes par courriel ───────────────────────────────────────────
# CONSTATE : une adresse de notification etait enregistree, le surveillant
# tournait, il detectait les anomalies -- et AUCUN courriel ne pouvait partir,
# faute d'agent de transport. Le portail est tombe, Internet a ete coupe pour
# tout le service, et pas un message n'est parti.
# msmtp-mta est leger (pas de serveur en ecoute, juste un relais sortant), ce qui
# convient a une passerelle : elle ne doit RECEVOIR aucun courriel.
dpkg -s msmtp-mta >/dev/null 2>&1 ||   DEBIAN_FRONTEND=noninteractive apt-get install -y msmtp-mta >/dev/null 2>&1 || true
install -m755 "${REPO_DIR}/services/scripts/mail-ctl.sh" /usr/local/sbin/proxyfibre-mail

# Modele de configuration. Les identifiants NE SONT PAS ecrits ici : le fichier
# porte des valeurs a remplacer, et « proxyfibre-mail state » refuse de declarer
# le relais configure tant qu'elles y sont. Un modele pris pour une configuration
# valide donnerait une alerte silencieuse de plus.
if [ ! -f /etc/msmtprc ]; then
  cat > /etc/msmtprc <<'MSMTP'
# Bastion — relais d'envoi des alertes.
# A COMPLETER par l'administrateur, puis : chmod 600 /etc/msmtprc
# Le mot de passe est en clair dans ce fichier : il doit rester lisible du seul
# compte root. Pour Gmail, utiliser un MOT DE PASSE D'APPLICATION, jamais le mot
# de passe du compte.
defaults
auth           on
tls            on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        /var/log/msmtp.log

account        alertes
host           A_REMPLACER.exemple.fr
port           587
from           A_REMPLACER@exemple.fr
user           A_REMPLACER@exemple.fr
password       A_REMPLACER

account default : alertes
MSMTP
  chmod 600 /etc/msmtprc
  touch /var/log/msmtp.log && chmod 640 /var/log/msmtp.log
fi

# Le verrou doit être RÉÉVALUÉ en permanence : si le tunnel tombe en cours de
# journée, le routage reprendrait la route normale et le groupe sortirait EN
# CLAIR sans que rien ne change à l'écran. L'agent poursuivrait sa recherche en
# se croyant couvert — pire que pas de tunnel du tout.
cat > /etc/systemd/system/proxyfibre-vpn-guard.service <<'UNIT'
[Unit]
Description=Bastion — verrou de la sortie par tunnel (re-evaluation)
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/proxyfibre-vpn apply
UNIT
cat > /etc/systemd/system/proxyfibre-vpn-guard.timer <<'UNIT'
[Unit]
Description=Bastion — controle du tunnel toutes les 30 s
[Timer]
OnBootSec=60
OnUnitActiveSec=30
AccuracySec=5
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now proxyfibre-vpn-guard.timer >/dev/null 2>&1 || true

# Marqueur de groupe. Colonne ajoutée sans condition : « ADD COLUMN IF NOT
# EXISTS » est disponible sur MariaDB et rend le déploiement rejouable.
mysql -N -B radius -e "ALTER TABLE pf_groups ADD COLUMN IF NOT EXISTS vpn_exit TINYINT(1) NOT NULL DEFAULT 0;" 2>/dev/null || true
# Base des fabricants de cartes réseau (paquet Debian « ieee-data »).
# Elle sert à nommer l'appareil derrière une adresse MAC dans la page DHCP. Le
# paquet vient du dépôt Debian, PAS d'un service web : interroger un service en
# ligne enverrait à un tiers la liste des appareils présents dans le
# commissariat. Sans lui, la console retombe sur une table intégrée d'une
# trentaine de marques — dégradé, mais jamais dépendant de l'extérieur.
dpkg -s ieee-data >/dev/null 2>&1 ||   DEBIAN_FRONTEND=noninteractive apt-get install -y ieee-data >/dev/null 2>&1 || true

# Diagnostic de lenteur : lecture seule, ne modifie rien.
install -m755 "${REPO_DIR}/services/scripts/perf-check.sh" /usr/local/sbin/proxyfibre-perf-check
# Reinitialisation deliberee du mot de passe de la console : le deploiement ne le
# reecrit plus, il fallait donc un vrai chemin de secours.
install -m755 "${REPO_DIR}/services/scripts/admin-passwd.sh" /usr/local/sbin/proxyfibre-admin-passwd
# La batterie de controles n etait posee que par selfupdate.sh : sur cette
# passerelle elle datait de la veille, et les controles ajoutes depuis ne
# s executaient tout simplement pas. Un controle qui ne tourne pas est pire
# qu absent -- on croit etre couvert.
install -m755 "${REPO_DIR}/services/scripts/selftest.sh" /usr/local/sbin/proxyfibre-selftest

# ── OPcache : PHP ne recompile plus le code à chaque affichage ───────────────
# Sans lui, les 1,1 Mo de code de la console sont relus, analysés et recompilés
# à CHAQUE clic pour produire le même résultat. C'est le seul réglage qui pèse
# identiquement sur toutes les pages, donc le premier à poser quand la lenteur
# est générale.
if ! php -m 2>/dev/null | grep -qi '^Zend OPcache$'; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y php-opcache >/dev/null 2>&1 || true
fi
# Le numéro de version PHP n'est pas codé en dur : Debian 13 livre PHP 8.4
# aujourd'hui, et un chemin figé casserait silencieusement à la prochaine
# version majeure -- le fichier serait écrit là où plus personne ne le lit.
OPC_POSE=0
for d in /etc/php/*/apache2/conf.d; do
  [ -d "$d" ] || continue
  install -m644 "${REPO_DIR}/services/php/opcache.ini" "$d/99-bastion-opcache.ini"
  OPC_POSE=1
done
if [ "$OPC_POSE" = "1" ]; then
  systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true
  # ── ON VÉRIFIE, ON NE SUPPOSE PAS ─────────────────────────────────────────
  # Le fichier peut être en place et l'extension absente ; la configuration peut
  # être lue par le PHP en ligne de commande et pas par celui d'Apache. Seul
  # Apache lui-même peut répondre, on le lui demande donc.
  OPC_SONDE=/var/www/html/.opcache-probe.php
  printf '<?php $s=function_exists("opcache_get_status")?@opcache_get_status(false):false;\n' > "$OPC_SONDE"
  printf 'echo ($s && !empty($s["opcache_enabled"])) ? "ACTIF ".intval($s["memory_usage"]["free_memory"]/1048576)."Mo libres" : "INACTIF";\n' >> "$OPC_SONDE"
  OPC_ETAT=$(curl -s --max-time 10 "http://127.0.0.1:2080/.opcache-probe.php" 2>/dev/null || echo "")
  rm -f "$OPC_SONDE"
  case "$OPC_ETAT" in
    ACTIF*) log "OPcache actif (${OPC_ETAT#ACTIF })" ;;
    INACTIF) log "ATTENTION : OPcache configuré mais INACTIF dans Apache — la console restera lente" ;;
    *)      log "OPcache configuré (état non vérifiable : sonde injoignable)" ;;
  esac
else
  log "ATTENTION : aucun répertoire /etc/php/*/apache2/conf.d — OPcache NON configuré"
fi
# Dépôt Git de Bastion : renseigné depuis la console (Système → Mise à jour de Bastion).
# 600 : le fichier peut contenir un jeton d'accès à un dépôt privé.
[ -f /etc/proxyfibre/update.env ] || printf 'GIT_REPO=""\nGIT_BRANCH="main"\nGIT_TOKEN=""\n' > /etc/proxyfibre/update.env
chmod 600 /etc/proxyfibre/update.env
# rsync : utilisé par la mise à jour pour synchroniser console et portail.
command -v rsync >/dev/null || apt-get -y install rsync >/dev/null 2>&1

# Antivirus : la base de signatures DOIT se mettre à jour seule. Un moteur aux
# signatures figées est pire qu'aucun antivirus — il donne une confiance infondée.
# (Constaté : le service était installé mais « disabled », donc jamais mis à jour.)
systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
cat > /etc/sudoers.d/proxyfibre-services <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-service start *, /usr/local/sbin/proxyfibre-service stop *, /usr/local/sbin/proxyfibre-service restart *, /usr/local/sbin/proxyfibre-service reload *, /usr/local/sbin/proxyfibre-service logs *
# Mise à jour Debian. Les verbes sont énumérés UN PAR UN, jamais « proxyfibre-apt * » :
# le joker autoriserait aussi « _run » et « _check », qui exécutent apt pour de vrai et
# ne doivent être atteignables que depuis l'unité systemd. Aucun verbe ne prend de nom de
# paquet — sinon la console offrirait « apt install n'importe quoi » en root.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-apt check, /usr/local/sbin/proxyfibre-apt list, /usr/local/sbin/proxyfibre-apt apply, /usr/local/sbin/proxyfibre-apt state, /usr/local/sbin/proxyfibre-apt log
# Mise à jour de Bastion depuis Git. Même règle : verbes énumérés, « _check » et
# « _apply » (qui écrivent réellement sur le disque) ne sont PAS atteignables par le web.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-selfupdate state, /usr/local/sbin/proxyfibre-selfupdate check, /usr/local/sbin/proxyfibre-selfupdate apply, /usr/local/sbin/proxyfibre-selfupdate log, /usr/local/sbin/proxyfibre-selfupdate pubkey, /usr/local/sbin/proxyfibre-selfupdate testssh
# Écriture de la configuration du dépôt (URL, branche, jeton) : script dédié, jamais
# un « tee » ou un « sh -c » générique, qui donneraient l'écriture de n'importe quel fichier.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-update-conf
# Mot de passe système. Les DEUX comptes autorisés sont énumérés ici, un par un : même si
# la liste fermée du script sautait un jour, sudo n'accepterait toujours que ces deux
# invocations, et jamais « proxyfibre-syspasswd <compte-arbitraire> ».
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-syspasswd proxyfibre, /usr/local/sbin/proxyfibre-syspasswd root
# Redémarrage / arrêt : les deux verbes énumérés, jamais « proxyfibre-power * ».
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-power reboot, /usr/local/sbin/proxyfibre-power poweroff
# Réservations DHCP : seul « apply » (régénère la conf depuis la base + recharge dnsmasq).
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-dhcp apply
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-image list, /usr/local/sbin/proxyfibre-image space, /usr/local/sbin/proxyfibre-image delete *
# Quarantaine réseau : « apply » (reconstruit la table nft dédiée) et « status ».
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-quarantine apply, /usr/local/sbin/proxyfibre-quarantine status
# Mesure de la ligne. « _run » n'est PAS listé : lui seul sature réellement la liaison.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-speedtest run, /usr/local/sbin/proxyfibre-speedtest state, /usr/local/sbin/proxyfibre-speedtest log
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-time status, /usr/local/sbin/proxyfibre-time resync, /usr/local/sbin/proxyfibre-time set *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-share-quota list, /usr/local/sbin/proxyfibre-share-quota set *, /usr/local/sbin/proxyfibre-share-quota scan, /usr/local/sbin/proxyfibre-share-quota enable
SUD
chmod 440 /etc/sudoers.d/proxyfibre-services

# Antivirus ClamAV + Active Directory : helpers + sudoers (liste blanche)
install -m755 "${REPO_DIR}/services/scripts/clamav-ctl.sh" /usr/local/sbin/proxyfibre-clamav
install -m755 "${REPO_DIR}/services/scripts/ad-ctl.sh"     /usr/local/sbin/proxyfibre-ad
install -m755 "${REPO_DIR}/services/scripts/gpo-apply.py"  /usr/local/sbin/proxyfibre-gpo-apply
install -m755 "${REPO_DIR}/services/scripts/gpo-apps.py"   /usr/local/sbin/proxyfibre-gpo-apps
install -m755 "${REPO_DIR}/services/scripts/gpo-kms.py"    /usr/local/sbin/proxyfibre-gpo-kms
install -m755 "${REPO_DIR}/services/scripts/gpo-drives.py" /usr/local/sbin/proxyfibre-gpo-drives
install -m755 "${REPO_DIR}/services/scripts/gpo-numlock.py" /usr/local/sbin/proxyfibre-gpo-numlock
install -m755 "${REPO_DIR}/services/scripts/gpo-defaultapps.py" /usr/local/sbin/proxyfibre-gpo-defaultapps
# Store d'applications : dossier des installeurs (servi sur 2080) + limites d'upload PHP.
install -d -o www-data -g www-data -m 755 /var/www/html/apps
PHPCONF="$(ls -d /etc/php/*/apache2/conf.d 2>/dev/null | head -1)"
[ -n "$PHPCONF" ] && printf 'upload_max_filesize = 900M\npost_max_size = 910M\nmax_execution_time = 600\nmemory_limit = 256M\n' > "$PHPCONF/99-proxyfibre-uploads.ini"
# Installeur AD (permet une (re)création du domaine depuis la console admin)
[ -f "${REPO_DIR}/provisioning/setup-ad.sh" ] && install -m755 "${REPO_DIR}/provisioning/setup-ad.sh" /usr/local/sbin/proxyfibre-setup-ad
# Échantillonneur de charge (processeur/mémoire) : cron 1/min → historique pf_metrics (24 h)
install -m755 "${REPO_DIR}/services/scripts/metrics-sample.php" /usr/local/sbin/proxyfibre-metrics-sample
# Le relevé de l'adresse publique s'accroche à la même minuterie : elle existe
# déjà, elle tourne à la bonne cadence, et cela évite une tâche planifiée de plus
# à surveiller. Le script sort tout de suite si le tunnel n'est pas établi — il
# n'interroge alors qu'une seule fois l'extérieur, pas deux.
# Les mises à jour sont relevées toutes les 10 minutes et non chaque minute :
# elles ne changent pas d'une minute à l'autre, et « apt » relit tout son cache à
# chaque appel — inutile de le lui demander soixante fois par heure sur une
# machine modeste.
printf '%s\n%s\n%s\n' \
  '* * * * * root php /usr/local/sbin/proxyfibre-metrics-sample >/dev/null 2>&1' \
  '* * * * * root /usr/local/sbin/proxyfibre-wanip refresh >/dev/null 2>&1' \
  '*/10 * * * * root /usr/local/sbin/proxyfibre-maj refresh >/dev/null 2>&1' \
  > /etc/cron.d/proxyfibre-metrics
install -m755 "${REPO_DIR}/services/scripts/backup-ctl.sh" /usr/local/sbin/proxyfibre-backup
# Cachet électronique des fiches d'habilitation (autorité de signature locale).
install -m755 "${REPO_DIR}/services/scripts/habilitation-ctl.sh" /usr/local/sbin/proxyfibre-habilitation
/usr/local/sbin/proxyfibre-habilitation init >/dev/null 2>&1 || true
# Signature électronique des dossiers de réquisition (CMS/PKCS#7 via l'AC Bastion).
install -m755 "${REPO_DIR}/services/scripts/sign-ctl.sh" /usr/local/sbin/proxyfibre-sign
# Sauvegarde automatique hebdomadaire (timer systemd, activé par défaut).
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-backup.service" /etc/systemd/system/proxyfibre-backup.service
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-backup.timer"   /etc/systemd/system/proxyfibre-backup.timer
systemctl daemon-reload
systemctl enable --now proxyfibre-backup.timer >/dev/null 2>&1 || true
cat > /etc/sudoers.d/proxyfibre-secu <<'SUD'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-clamav update, /usr/local/sbin/proxyfibre-clamav scan *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-ad *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-backup *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-habilitation *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/proxyfibre-sign *
SUD
chmod 440 /etc/sudoers.d/proxyfibre-secu
# Rafraîchissement hebdomadaire du bloqueur de publicités (s'il est activé)
echo '0 4 * * 0 root [ "$(mysql -N -B radius -e "SELECT v FROM pf_settings WHERE k=\"adblock_enabled\"" 2>/dev/null)" = "1" ] && /usr/local/sbin/proxyfibre-update-adblock enable' > /etc/cron.d/proxyfibre-adblock
# Quotas/horaires + journalisation : custombinauth (appelé par OpenNDS binauth)
install -m755 "${REPO_DIR}/services/opennds/custombinauth.sh" /usr/lib/opennds/custombinauth.sh
# Échantillons de débit laissés par un essai en ligne de commande : appartenant à root,
# ils empêcheraient le serveur web de les réécrire et le débit resterait figé.
rm -f /dev/shm/pf-net-*.sample 2>/dev/null || true

# Journal systemd : plafonné. Par défaut il grossit jusqu'à 10 % du disque — sur une
# passerelle laissée des mois en service, c'est plusieurs gigaoctets pour rien.
sed -i 's/^#\?SystemMaxUse=.*/SystemMaxUse=500M/' /etc/systemd/journald.conf 2>/dev/null || true
grep -q '^SystemMaxUse=' /etc/systemd/journald.conf || echo 'SystemMaxUse=500M' >> /etc/systemd/journald.conf
grep -q '^SystemMaxFileSize=' /etc/systemd/journald.conf || echo 'SystemMaxFileSize=50M' >> /etc/systemd/journald.conf
systemctl restart systemd-journald >/dev/null 2>&1 || true

# Pas de session graphique sur une passerelle : ~500 Mo de RAM, un flux de mises à jour
# et une surface d'attaque qui n'ont rien à faire sur un équipement réseau exposé.
# On ne DÉSINSTALLE pas (réversible d'une commande) : on ne la démarre plus.
systemctl set-default multi-user.target >/dev/null 2>&1 || true

# ── Rétention : purge quotidienne ────────────────────────────────────────────
# La tâche est lancée SANS argument, à dessein. Elle imposait « 365 » en dur : la
# durée de conservation réglée dans la console était donc affichée à l'écran,
# enregistrée en base... et ignorée par la seule chose qui efface réellement
# quelque chose. Un service qui ramenait la conservation à 90 jours croyait
# l'avoir fait ; les données restaient un an.
# Sans argument, le script lit « log_retention_days » et retombe sur 365 s'il est
# absent ou aberrant — il refuse déjà en dessous de 30 j et au-delà de 5 ans.
install -m755 "${REPO_DIR}/services/scripts/purge-logs.sh" /usr/local/sbin/proxyfibre-purge-logs
echo "30 3 * * * root /usr/local/sbin/proxyfibre-purge-logs" > /etc/cron.d/proxyfibre-purge
log "Filtrage + quotas + journalisation déployés"

# ── Walled garden : serveurs de MAJ (Windows/Linux) ouverts sans authentification ──
# dnsmasq ajoute les IP résolues des domaines de MAJ à l'ensemble @walledgarden
# d'OpenNDS (autorisé aux clients preauth). Un service compagnon relance dnsmasq
# après OpenNDS (l'ensemble doit exister avant que dnsmasq ne s'y réfère).
install -m644 "${REPO_DIR}/services/dnsmasq/walledgarden.conf" /etc/dnsmasq.d/proxyfibre-walledgarden.conf
install -m755 "${REPO_DIR}/services/scripts/walledgarden-refresh.sh" /usr/local/sbin/proxyfibre-walledgarden-refresh
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-walledgarden.service" /etc/systemd/system/proxyfibre-walledgarden.service
systemctl daemon-reload
systemctl enable proxyfibre-walledgarden.service >/dev/null 2>&1 || true
# Quarantaine réseau : réappliquée au démarrage (table nft dédiée, isolée d'OpenNDS).
systemctl enable proxyfibre-quarantine.service >/dev/null 2>&1 || true
log "Walled garden (mises à jour Windows/Linux) déployé"

# ── Serveur de temps local (chrony) ──────────────────────────────────────────
if ! command -v chronyd >/dev/null; then apt-get install -y -qq chrony >/dev/null 2>&1 || true; fi
install -D -m644 "${REPO_DIR}/services/chrony/proxyfibre.conf" /etc/chrony/conf.d/proxyfibre.conf 2>/dev/null || true
systemctl restart chrony 2>/dev/null || true
log "Serveur NTP local (chrony) déployé"

# ── Historique de navigation (journalisation DNS → base) ─────────────────────
cat > /etc/dnsmasq.d/proxyfibre-log.conf <<'DNSLOG'
log-queries
log-facility=/var/log/proxyfibre-dns.log
DNSLOG
systemctl restart dnsmasq 2>/dev/null || true
install -m755 "${REPO_DIR}/services/scripts/weblog-ingest.sh" /usr/local/sbin/proxyfibre-weblog-ingest
install -m644 "${REPO_DIR}/services/systemd/proxyfibre-weblog.service" /etc/systemd/system/proxyfibre-weblog.service
systemctl daemon-reload
systemctl enable --now proxyfibre-weblog >/dev/null 2>&1 || true
log "Historique de navigation par utilisateur déployé"

# ── Serveur PXE (installation d'OS par le réseau) ────────────────────────────
if bash "${REPO_DIR}/services/scripts/setup-pxe.sh"; then
  log "Serveur PXE déployé (boot réseau → installation OS)"
else
  log "PXE non déployé (téléchargement netboot indisponible ?) — non bloquant"
fi

# ── Démarrage ────────────────────────────────────────────────────────────────
systemctl restart freeradius
systemctl restart apache2
systemctl restart dnsmasq
# OpenNDS : stop propre + pause avant start (un "restart" trop rapide échoue avec
# "openNDS is already running... Retry later"). Son init prend ~10 s.
systemctl enable opennds >/dev/null 2>&1 || true
systemctl stop opennds 2>/dev/null || true
sleep 3
systemctl start opennds

log "──────────────────────────────────────────────────────────────"
log "Déploiement terminé."
log "  LAN ${LAN_IF} = ${LAN_IP}/${LAN_CIDR}  ·  DHCP ${DHCP_START}-${DHCP_END}"
log "  Portail FAS : http://${LAN_IP}/portal/fas.php"
[ -n "${TEST_USER:-}" ] && [ -n "${TEST_PASS:-}" ] && log "  Utilisateur de test : ${TEST_USER} / ${TEST_PASS}"
log "──────────────────────────────────────────────────────────────"

# Le mot de passe engendré n'est affiché QU'UNE FOIS, au premier déploiement : il n'est
# ensuite lisible que dans /etc/proxyfibre/admin-pass.env, réservé à root.
if [[ "${ADMIN_PASS_GENERE:-0}" = "1" ]]; then
  echo
  echo "  ╔══════════════════════════════════════════════════════════════════╗"
  echo "  ║  MOT DE PASSE DE LA CONSOLE — À NOTER MAINTENANT                 ║"
  echo "  ╠══════════════════════════════════════════════════════════════════╣"
  printf '  ║  Utilisateur : %-49s ║\n' "${ADMIN_USER}"
  printf '  ║  Mot de passe : %-48s ║\n' "${ADMIN_PASS}"
  echo "  ║                                                                  ║"
  echo "  ║  Engendré au hasard : aucun mot de passe par défaut n'existe     ║"
  echo "  ║  dans ce produit. Relisible dans /etc/proxyfibre/admin-pass.env  ║"
  echo "  ║  (accès root). Activez la double authentification dans Profil.   ║"
  echo "  ╚══════════════════════════════════════════════════════════════════╝"
  echo
fi
