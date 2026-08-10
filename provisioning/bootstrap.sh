#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# ─────────────────────────────────────────────────────────────────────────────
# Bastion — installation depuis GitHub, en une commande.
#
#   curl -fsSL https://raw.githubusercontent.com/<compte>/bastion/main/provisioning/bootstrap.sh \
#     | sudo BASTION_TOKEN=ghp_xxx bash
#
# LE DÉPÔT EST PRIVÉ. Sans jeton d'accès, GitHub répond 404 — pas « accès
# refusé », mais « n'existe pas ». Sans le contrôle ci-dessous, l'installation
# échouerait sur un message incompréhensible, en laissant croire à une faute de
# frappe dans l'adresse. On le vérifie donc explicitement, et on le DIT.
#
# Trois façons de fournir l'accès, par ordre de préférence :
#   1. BASTION_TOKEN — jeton d'accès personnel GitHub (lecture seule suffit) ;
#   2. une clé SSH déjà autorisée sur le compte (BASTION_SSH=1) ;
#   3. un bundle git déposé à la main (BASTION_BUNDLE=/chemin/code.bundle),
#      pour une machine sans accès à Internet — le cas d'un commissariat isolé.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

DEPOT="${BASTION_DEPOT:-sdanessonne/bastion}"
BRANCHE="${BASTION_BRANCHE:-main}"
CIBLE="${BASTION_CIBLE:-/opt/bastion}"

log(){ printf '\033[1;32m[Bastion]\033[0m %s\n' "$*"; }
avert(){ printf '\033[1;33m[Bastion]\033[0m %s\n' "$*"; }
die(){ printf '\033[1;31m[Bastion]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "À lancer en root : ajoutez « sudo » devant la commande."
command -v apt-get >/dev/null || die "Cette installation cible Debian. « apt-get » est introuvable."

# ── Outils minimaux ─────────────────────────────────────────────────────────
for o in git curl; do
  command -v "$o" >/dev/null || { log "Installation de $o…"; apt-get update -qq; apt-get install -y -qq "$o"; }
done

# ── Récupération du code ────────────────────────────────────────────────────
recuperer() {
  if [[ -n "${BASTION_BUNDLE:-}" ]]; then
    [[ -r "$BASTION_BUNDLE" ]] || die "Bundle illisible : $BASTION_BUNDLE"
    log "Installation depuis le bundle $BASTION_BUNDLE (sans Internet)."
    git clone -q -b "$BRANCHE" "$BASTION_BUNDLE" "$CIBLE"
    return
  fi

  if [[ -n "${BASTION_TOKEN:-}" ]]; then
    log "Récupération depuis GitHub (jeton d'accès)…"
    # Le jeton ne doit PAS rester dans .git/config : il y serait lisible par
    # quiconque ouvre le dépôt, et il finirait dans « git remote -v ».
    git clone -q -b "$BRANCHE" "https://x-access-token:${BASTION_TOKEN}@github.com/${DEPOT}.git" "$CIBLE" \
      || die "Clonage impossible. Jeton invalide, expiré, ou sans droit de lecture sur ${DEPOT} ?"
    git -C "$CIBLE" remote set-url origin "https://github.com/${DEPOT}.git"
    return
  fi

  if [[ "${BASTION_SSH:-}" == "1" ]]; then
    log "Récupération depuis GitHub (clé SSH)…"
    git clone -q -b "$BRANCHE" "git@github.com:${DEPOT}.git" "$CIBLE" \
      || die "Clonage SSH impossible. La clé de cette machine est-elle autorisée sur le compte ?"
    return
  fi

  # Aucun moyen d'accès fourni : on le dit clairement plutôt que de tenter un
  # clonage anonyme qui rendrait un « 404 » trompeur.
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "https://github.com/${DEPOT}" || echo 000)
  if [[ "$code" == "404" ]]; then
    die "Le dépôt ${DEPOT} est PRIVÉ (GitHub répond 404 sans authentification).
       Fournissez un accès :
         curl … | sudo BASTION_TOKEN=ghp_xxx bash     (jeton GitHub, lecture seule)
         curl … | sudo BASTION_SSH=1 bash             (clé SSH déjà autorisée)
         curl … | sudo BASTION_BUNDLE=/chemin.bundle bash   (hors ligne)"
  fi
  log "Dépôt public : récupération anonyme…"
  git clone -q -b "$BRANCHE" "https://github.com/${DEPOT}.git" "$CIBLE" \
    || die "Clonage impossible depuis https://github.com/${DEPOT}"
}

if [[ -d "$CIBLE/.git" ]]; then
  log "Bastion est déjà présent dans $CIBLE — mise à jour."
  git -C "$CIBLE" fetch -q --all && git -C "$CIBLE" reset -q --hard "origin/${BRANCHE}" 2>/dev/null \
    || avert "Mise à jour impossible (dépôt hors ligne ?) — on continue avec la copie locale."
else
  mkdir -p "$(dirname "$CIBLE")"
  recuperer
fi
log "Code dans $CIBLE ($(git -C "$CIBLE" log --oneline -1 2>/dev/null || echo 'version inconnue'))"

# ── Configuration ───────────────────────────────────────────────────────────
CONF="$CIBLE/provisioning/config.env"
if [[ ! -f "$CONF" ]]; then
  cp "$CIBLE/provisioning/config.env.example" "$CONF"
  chmod 600 "$CONF"
  echo
  avert "Configuration à renseigner AVANT d'installer : $CONF"
  avert "Elle contient les mots de passe et le plan d'adressage — je ne peux pas les deviner."
  echo
  echo "  Éditez-la, puis relancez exactement la même commande :"
  echo "    nano $CONF"
  echo
  # On s'arrête ici volontairement. Installer avec les valeurs d'exemple
  # produirait une passerelle en apparence fonctionnelle, avec des mots de
  # passe connus de tous — bien pire qu'une installation interrompue.
  exit 0
fi

# ── Installation ────────────────────────────────────────────────────────────
log "Étape 1/2 — système (paquets, routage, base)…"
bash "$CIBLE/provisioning/install.sh"
log "Étape 2/2 — application (services, console, portail)…"
bash "$CIBLE/provisioning/deploy.sh"

echo
log "Installation terminée."
ip -4 -o addr show 2>/dev/null | awk '$2!="lo"{split($4,a,"/"); print "  Console : https://" a[1] ":8443/"}'
log "Vérifiez l'état avec : sudo /usr/local/sbin/proxyfibre-selftest"
