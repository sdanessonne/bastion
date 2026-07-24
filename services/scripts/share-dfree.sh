#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Commande « dfree » de Samba : impose un QUOTA par partage.
#   Samba l'appelle : proxyfibre-share-dfree <chemin-du-partage>
#   Sortie attendue  : « <blocs_totaux> <blocs_libres> <taille_bloc> » (blocs de 1024 o).
#
# Avec un quota configuré (Mo) pour ce partage : renvoie une taille TOTALE = quota et un ESPACE
# LIBRE = quota − occupé → Windows affiche le quota et refuse l'écriture une fois plein.
# SANS quota : renvoie le df RÉEL du système de fichiers (aucun effet).
# L'occupation est lue dans un cache rafraîchi par « share-quota scan » (rapide, pas de « du » ici).
path="$1"
name=$(basename "$path" 2>/dev/null)
CONF=/etc/proxyfibre/share-quota.conf
CACHE=/dev/shm/pf-share-used.cache

q=$(sed -n "s|^$name=||p" "$CONF" 2>/dev/null | head -1)
case "$q" in ''|*[!0-9]*) q=0 ;; esac

if [ "$q" -le 0 ]; then
    # Pas de quota : espace réel du système de fichiers (blocs de 1024 o).
    df -P -B1024 "$path" 2>/dev/null | awk 'NR==2{print $2, $4, 1024; f=1} END{if(!f) print 1048576, 1048576, 1024}'
    exit 0
fi

total=$(( q * 1024 ))                                        # quota (Mo) → blocs de 1024 o
used=$(sed -n "s|^$name=||p" "$CACHE" 2>/dev/null | head -1)  # occupé (Ko), depuis le cache
case "$used" in ''|*[!0-9]*) used=$(du -s -k "$path" 2>/dev/null | awk '{print $1}') ;; esac
case "$used" in ''|*[!0-9]*) used=0 ;; esac
avail=$(( total - used ))
[ "$avail" -lt 0 ] && avail=0
echo "$total $avail 1024"
