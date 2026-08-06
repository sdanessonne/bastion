#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Prise de main à distance : installation du RELAIS sur la passerelle.
#
# Pourquoi un relais, et pas une connexion directe : les postes sont en
# 192.168.182.0/24 derrière la passerelle, le poste d'administration est en
# 10.91.22.0/24, et il n'existe volontairement AUCUNE route de l'un vers l'autre.
# C'est cette absence de chemin qui empêche un poste compromis d'atteindre
# l'administration. Une prise de main « directe » (RDP, assistance Windows)
# obligerait à percer cet isolement.
#
# Avec un relais, les DEUX côtés se connectent en SORTANT vers la passerelle.
# Aucun flux entrant vers le LAN, aucune route à ajouter, l'isolement reste entier.
# Et c'est le seul modèle qui franchira le tunnel inter-sites sans travail
# supplémentaire, le jour où plusieurs commissariats seront raccordés.
#
# Deux services :
#   hbbs — annuaire : les clients s'y enregistrent et s'y retrouvent par leur identifiant
#   hbbr — relais   : achemine le flux quand les deux pairs ne peuvent pas se joindre
#
# Usage : setup-distance.sh [install|start|stop|state|key]
set -eu

DIR=/opt/bastion-distance          # binaires
VAR=/var/lib/bastion-distance      # clés et base des identifiants
LOG=/var/log/bastion-distance.log
CONF=/etc/proxyfibre/distance.env
API="https://api.github.com/repos/rustdesk/rustdesk-server/releases/latest"

# Adresse ANNONCÉE aux clients pour joindre le relais de flux.
#
# Elle doit être joignable des DEUX côtés, et c'est le piège de cette
# installation : le poste d'administration (10.91.22.0/24) ne sait pas joindre
# 192.168.182.1, vérifié le 2026-08-06. On annonce donc l'adresse côté
# administration — les postes, eux, atteignent les deux, ce sont deux adresses
# de la même passerelle.
#
# Modifiable dans /etc/proxyfibre/distance.env (RELAIS_HOTE=…) si la topologie
# change : autre plan d'adressage, ou raccordement multi-sites par le tunnel.
RELAIS_HOTE=$(ip -4 -o addr show enp1s0 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)
[ -r "$CONF" ] && . "$CONF"
RELAIS_HOTE=${RELAIS_HOTE:-192.168.182.1}

msg() { printf '%s\n' "$*"; }
err() { printf 'ERREUR: %s\n' "$*" >&2; exit 1; }

# ── Installation ────────────────────────────────────────────────────────────────
installer() {
  [ "$(id -u)" -eq 0 ] || err "à lancer en root"

  mkdir -p "$DIR" "$VAR"

  # On demande la version du jour plutôt que de figer un numéro : une URL figée
  # finit toujours par disparaître du site de l'éditeur. Même raison que pour le
  # catalogue du store d'applications.
  msg "Recherche de la dernière version du relais…"
  url=$(curl -sSL --max-time 30 -H 'Accept: application/vnd.github+json' "$API" \
        | grep -o '"browser_download_url": *"[^"]*linux-amd64\.zip"' \
        | head -1 | sed 's/.*"\(https[^"]*\)"/\1/')
  [ -n "$url" ] || err "aucune archive linux-amd64 trouvée (réseau, ou quota de l'API GitHub atteint)"
  ver=$(printf '%s' "$url" | sed 's~.*/download/\([^/]*\)/.*~\1~')
  msg "Version $ver"

  tmp=$(mktemp -d)
  trap 'rm -rf "$tmp"' EXIT
  curl -fsSL --max-time 300 -o "$tmp/s.zip" "$url" || err "téléchargement impossible"

  # On vérifie que c'est bien une archive ZIP avant de la déballer. « curl a rendu 0 »
  # ne prouve rien : une page d'erreur HTML passerait tous les contrôles de taille.
  head -c 2 "$tmp/s.zip" | grep -q 'PK' || err "le fichier reçu n'est pas une archive ZIP"

  command -v unzip >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq unzip; }
  unzip -qo "$tmp/s.zip" -d "$tmp/x" || err "archive illisible"

  for b in hbbs hbbr; do
    f=$(find "$tmp/x" -type f -name "$b" | head -1)
    [ -n "$f" ] || err "$b absent de l'archive"
    install -m 755 "$f" "$DIR/$b"
  done
  msg "Binaires installés dans $DIR"

  ecrire_units
  ouvrir_ports
  systemctl daemon-reload
  systemctl enable --now bastion-hbbr.service bastion-hbbs.service

  # hbbs fabrique sa paire de clés au premier démarrage : on la lui laisse le temps.
  n=0
  while [ ! -s "$VAR/id_ed25519.pub" ] && [ $n -lt 15 ]; do sleep 1; n=$((n+1)); done
  [ -s "$VAR/id_ed25519.pub" ] || err "le relais n'a pas produit sa clé publique — voir $LOG"

  msg ""
  msg "Relais en service. Clé publique à distribuer aux clients :"
  msg "  $(cat "$VAR/id_ed25519.pub")"
}

# ── Unités systemd ──────────────────────────────────────────────────────────────
ecrire_units() {
  cat > /etc/systemd/system/bastion-hbbs.service <<UNIT
[Unit]
Description=Bastion - annuaire de prise de main a distance (hbbs)
After=network-online.target bastion-hbbr.service
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$VAR
# « -r » annonce aux clients l'adresse du relais de flux (voir en tete du script :
# elle doit etre joignable de l'administration ET des postes).
ExecStart=$DIR/hbbs -r $RELAIS_HOTE:21117
Restart=always
RestartSec=3
StandardOutput=append:$LOG
StandardError=append:$LOG
# Durcissement : ce service n'a besoin que de son propre repertoire.
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=$VAR

[Install]
WantedBy=multi-user.target
UNIT

  cat > /etc/systemd/system/bastion-hbbr.service <<UNIT
[Unit]
Description=Bastion - relais de prise de main a distance (hbbr)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$VAR
ExecStart=$DIR/hbbr
Restart=always
RestartSec=3
StandardOutput=append:$LOG
StandardError=append:$LOG
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=$VAR

[Install]
WantedBy=multi-user.target
UNIT
  msg "Unités systemd écrites."
}

# ── Pare-feu ────────────────────────────────────────────────────────────────────
# Portée volontairement étroite : le LAN des postes et le réseau d'administration.
# Jamais l'Internet — un annuaire de prise de main exposé au monde serait une
# invitation, même protégé par une clé.
ouvrir_ports() {
  # ── 1. Le portail captif d'abord ─────────────────────────────────────────────
  # C'est LUI qui décide ce qu'un poste a le droit d'adresser à la passerelle. Sa
  # chaîne ndsRTR se termine par un « reject » : tout port absent de la liste
  # « users_to_router » est refusé, et elle s'applique AVANT nos propres règles
  # (priorité -100 contre -50). Sans cette étape, les services tourneraient, les
  # ports écouteraient, et pas un poste ne pourrait s'enregistrer.
  conf=/etc/config/opennds
  if [ -f "$conf" ]; then
    ajout=0
    for r in "tcp port 21115" "tcp port 21116" "udp port 21116" \
             "tcp port 21117" "tcp port 21118" "tcp port 21119"; do
      ligne="	list users_to_router 'allow $r'"
      grep -qF "allow $r'" "$conf" || { printf '%s\n' "$ligne" >> "$conf"; ajout=$((ajout+1)); }
    done
    if [ "$ajout" -gt 0 ]; then
      msg "Portail captif : $ajout règle(s) d'accès au relais ajoutée(s)."
      systemctl restart opennds 2>/dev/null || msg "ATTENTION: redémarrage d'opennds impossible — les règles ne prendront effet qu'au prochain démarrage."
    else
      msg "Portail captif : règles d'accès déjà présentes."
    fi
  else
    msg "ATTENTION: $conf absent — les postes ne pourront pas joindre le relais."
  fi

  # ── 2. Nos propres règles ────────────────────────────────────────────────────
  command -v nft >/dev/null 2>&1 || { msg "nftables absent : ports non ouverts."; return 0; }
  nft list table inet bastion_distance >/dev/null 2>&1 && nft delete table inet bastion_distance
  nft -f - <<'RULES'
table inet bastion_distance {
  chain input {
    type filter hook input priority -50; policy accept;
    ip saddr { 192.168.182.0/24, 10.91.22.0/24 } tcp dport { 21115, 21116, 21117, 21118, 21119 } accept
    ip saddr { 192.168.182.0/24, 10.91.22.0/24 } udp dport 21116 accept
  }
}
RULES
  msg "Ports 21115-21119 ouverts pour 192.168.182.0/24 et 10.91.22.0/24."
}

# ── État ────────────────────────────────────────────────────────────────────────
etat() {
  cle=""
  [ -s "$VAR/id_ed25519.pub" ] && cle=$(cat "$VAR/id_ed25519.pub")
  hbbs=$(systemctl is-active bastion-hbbs.service 2>/dev/null || echo inactive)
  hbbr=$(systemctl is-active bastion-hbbr.service 2>/dev/null || echo inactive)
  ecoute=$(ss -lntu 2>/dev/null | grep -c ':2111[5-9]' || true)
  # Le portail captif laisse-t-il vraiment passer les postes ? Un relais qui écoute
  # sans que ndsRTR l'autorise a toutes les apparences du bon fonctionnement.
  #
  # On regarde DANS la chaîne ndsRTR, pas dans le jeu de règles entier : chercher
  # partout trouvait notre propre table et répondait « ouvert » sans rien prouver.
  # Un contrôle qui se valide lui-même ne contrôle rien.
  portail=$(nft list ruleset 2>/dev/null | sed -n '/chain ndsRTR/,/^\t}/p' | grep -c 'dport 21116' || true)
  printf '{"hbbs":"%s","hbbr":"%s","cle":"%s","ports_ecoutes":%s,"relais":"%s","portail_ouvert":%s}\n' \
         "$hbbs" "$hbbr" "$cle" "${ecoute:-0}" "$RELAIS_HOTE:21117" "$([ "${portail:-0}" -gt 0 ] && echo true || echo false)"
}

case "${1:-state}" in
  install) installer ;;
  start)   systemctl start bastion-hbbr.service bastion-hbbs.service; msg "démarré" ;;
  stop)    systemctl stop bastion-hbbs.service bastion-hbbr.service; msg "arrêté" ;;
  key)     [ -s "$VAR/id_ed25519.pub" ] && cat "$VAR/id_ed25519.pub" || err "aucune clé — le relais n'a jamais démarré" ;;
  state)   etat ;;
  *)       err "usage: $0 [install|start|stop|state|key]" ;;
esac
