#!/usr/bin/env bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Importe les GROS FICHIERS depuis un support amovible : source d'installation
# Windows, image master, image Ubuntu. Ceux-là ne peuvent pas voyager dans l'ISO
# d'installation de Bastion — elle pèse 940 Mo, l'ISO Windows en pèse 7,9 à elle
# seule — et, pour la source Windows, ils n'ont pas à être redistribués : le
# service dispose de son propre média sous licence en volume.
#
# ── CE QUE CELA CHANGE POUR LE CLIENT ────────────────────────────────────────
# Sans ce script, l'installation « clé en main » livre un serveur Bastion complet
# mais SANS déploiement Windows : le menu PXE affiche l'option et elle échoue.
# Avec lui, il suffit de brancher une clé USB contenant les fichiers avant de
# démarrer : le premier amorçage les récupère et le parc est déployable.
#
# ── CE QU'IL ACCEPTE ─────────────────────────────────────────────────────────
#   win11.iso    → /srv/pxe/iso/       source d'installation Windows (option [1])
#   ubuntu.iso   → /srv/pxe/iso/       amorçage Ubuntu en direct
#   master.wim   → /srv/pxe/images/    image master (option [2])
# Les noms sont cherchés à la racine du support et dans un dossier « bastion ».
#
# Usage : import-media.sh [auto|<point de montage>]
#   auto  : parcourt les supports amovibles branchés (défaut)
set -uo pipefail

DEST_ISO=/srv/pxe/iso
DEST_IMG=/srv/pxe/images
LOG=/var/log/bastion-import-media.log

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

[ "$(id -u)" -eq 0 ] || { echo "Ce script doit être lancé en root."; exit 1; }
mkdir -p "$DEST_ISO" "$DEST_IMG"

# ── Copie d'un fichier attendu, avec contrôle ────────────────────────────────
# On ne recopie pas ce qui est déjà là et identique : sur une clé USB laissée
# branchée, l'import serait sinon refait à chaque démarrage — plusieurs minutes
# de copie pour rien.
copier() {
    local src="$1" dst="$2" nom
    nom=$(basename "$dst")
    [ -f "$src" ] || return 1

    local taille libre
    taille=$(stat -c%s "$src")
    libre=$(df -B1 --output=avail "$(dirname "$dst")" | tail -1)
    if [ -f "$dst" ]; then
        if [ "$(stat -c%s "$dst")" = "$taille" ]; then
            log "  $nom : déjà présent et de même taille, ignoré."
            return 0
        fi
        # Le remplacement n'a de sens que si la place existe EN PLUS de l'ancien,
        # le temps de la copie. Sinon on refuse plutôt que de tout perdre.
        libre=$((libre + $(stat -c%s "$dst")))
    fi
    if [ "$taille" -gt "$libre" ]; then
        log "  $nom : PLACE INSUFFISANTE ($(numfmt --to=iec "$taille") requis, \
$(numfmt --to=iec "$libre") disponibles) — ignoré."
        return 1
    fi

    log "  $nom : copie de $(numfmt --to=iec "$taille")…"
    # Fichier temporaire puis renommage : une copie interrompue ne laisse jamais
    # un fichier tronqué que le menu PXE prendrait pour une source valide.
    if cp -f "$src" "$dst.part" && sync; then
        mv -f "$dst.part" "$dst"
        chmod 644 "$dst"
        log "  $nom : importé."
        return 0
    fi
    rm -f "$dst.part"
    log "  $nom : ÉCHEC de la copie."
    return 1
}

# ── Recherche des fichiers sur un support ────────────────────────────────────
traiter() {
    local racine="$1" trouve=0
    for base in "$racine" "$racine/bastion" "$racine/Bastion" "$racine/BASTION"; do
        [ -d "$base" ] || continue
        copier "$base/win11.iso"  "$DEST_ISO/win11.iso"   && trouve=1
        copier "$base/ubuntu.iso" "$DEST_ISO/ubuntu.iso"  && trouve=1
        copier "$base/master.wim" "$DEST_IMG/master.wim"  && trouve=1
    done
    return $((1 - trouve))
}

CIBLE="${1:-auto}"
IMPORTE=0

if [ "$CIBLE" != "auto" ]; then
    log "Import depuis $CIBLE"
    traiter "$CIBLE" && IMPORTE=1
else
    log "Recherche de supports amovibles…"
    # On monte SOI-MÊME les partitions amovibles : au premier démarrage, aucun
    # environnement de bureau n'est là pour le faire, et rien ne serait trouvé.
    TMP=$(mktemp -d)
    while read -r dev fstype; do
        [ -n "$fstype" ] || continue
        case "$fstype" in vfat|exfat|ntfs|ext4|iso9660|udf) ;; *) continue ;; esac
        point=$(lsblk -no MOUNTPOINT "$dev" | head -1)
        demonte=0
        if [ -z "$point" ]; then
            mount -o ro "$dev" "$TMP" 2>/dev/null || continue
            point="$TMP"; demonte=1
        fi
        log "  support : $dev ($fstype) monté sur $point"
        traiter "$point" && IMPORTE=1
        [ "$demonte" = 1 ] && umount "$TMP" 2>/dev/null || true
    done < <(lsblk -rno NAME,FSTYPE,RM,TYPE | awk '$3=="1" && $4=="part" {print "/dev/"$1, $2}')
    rmdir "$TMP" 2>/dev/null || true
fi

if [ "$IMPORTE" = 0 ]; then
    log "Aucun fichier à importer trouvé."
    exit 0
fi

# ── Prise en compte : monter l'ISO Windows et publier les partages ───────────
# setup-pxe.sh est idempotent : il ne refait que ce qui a changé.
# Où vit le code : écrit par deploy.sh, qui est le seul à le savoir de source
# sûre. Le chemin était codé en dur sur /home/proxyfibre/proxyFibre — une
# installation depuis un compte portant un autre nom donnait alors un serveur
# incapable de se mettre à jour, sans que rien ne le signale.
[ -r /etc/proxyfibre/repo.env ] && . /etc/proxyfibre/repo.env
REPO_DIR="${REPO_DIR:-/home/proxyfibre/proxyFibre}"
if [ -x "$REPO_DIR/services/scripts/setup-pxe.sh" ]; then
    log "Prise en compte par le serveur PXE…"
    bash "$REPO_DIR/services/scripts/setup-pxe.sh" >>"$LOG" 2>&1 \
        && log "Serveur PXE actualisé." \
        || log "ATTENTION : actualisation du serveur PXE en échec — voir $LOG"
fi

log "Import terminé."
