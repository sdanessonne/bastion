#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Commande « dfree » de Samba : impose un QUOTA par partage.
#   Samba l'appelle : proxyfibre-share-dfree <chemin-du-partage>
#   Sortie attendue  : « <blocs_totaux> <blocs_libres> <taille_bloc> » (blocs de 1024 o).
#
# Le quota (Mo) et l'occupation (Ko) sont indexés par le CHEMIN du partage (c'est ce que Samba
# passe ici), en correspondance EXACTE. Avec quota : total=quota, libre=quota−occupé → Windows
# affiche le quota et refuse l'écriture une fois plein. Sans quota : df RÉEL (aucun effet).
# L'occupation vient d'un cache rafraîchi par « share-quota scan » (rapide, pas de « du » ici).
path="$1"
CONF=/etc/proxyfibre/share-quota.conf
CACHE=/dev/shm/pf-share-used.cache
TAB=$(printf '\t')

q=$(awk -F"$TAB" -v p="$path" '$1==p{print $2; exit}' "$CONF" 2>/dev/null)
case "$q" in ''|*[!0-9]*) q=0 ;; esac

if [ "$q" -le 0 ]; then
    df -P -B1024 "$path" 2>/dev/null | awk 'NR==2{print $2, $4, 1024; f=1} END{if(!f) print 1048576, 1048576, 1024}'
    exit 0
fi

total=$(( q * 1024 ))                                          # quota (Mo) → blocs de 1024 o
used=$(awk -F"$TAB" -v p="$path" '$1==p{print $2; exit}' "$CACHE" 2>/dev/null)   # occupé (Ko)
case "$used" in ''|*[!0-9]*) used=$(du -s -k "$path" 2>/dev/null | awk '{print $1}') ;; esac
case "$used" in ''|*[!0-9]*) used=0 ;; esac
avail=$(( total - used ))
[ "$avail" -lt 0 ] && avail=0
echo "$total $avail 1024"
