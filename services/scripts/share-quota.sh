#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Quotas des partages réseau (SMB). L'application se fait via la « dfree command » de Samba
# (proxyfibre-share-dfree) : le poste voit l'espace limité au quota et l'écriture est refusée
# une fois plein — SANS jamais toucher aux fichiers ni aux ACL.
#   share-quota list                 nom TAB chemin TAB occupé(o) TAB quota(Mo)  (par partage)
#   share-quota set <partage> <Mo>   définit (0 = illimité) le quota d'un partage
#   share-quota scan                 recalcule l'occupation (cache tmpfs, lu par dfree)
#   share-quota enable               active la « dfree command » sur tous les partages de données
set -u
CONF=/etc/proxyfibre/share-quota.conf
CACHE=/dev/shm/pf-share-used.cache
SHFILE=/etc/samba/shares.conf
DFREE=/usr/local/sbin/proxyfibre-share-dfree
TAB=$(printf '\t')
action="${1:-list}"; name="${2:-}"; val="${3:-}"

# Partages DISQUE (nom + chemin), hors partages système.
disk_shares() {
    testparm -s 2>/dev/null | awk '
        function flush(){ if(sec!="" && path!="") print sec "\t" path }
        /^\[/{ flush(); sec=$0; gsub(/[][]/,"",sec); path=""; next }
        /^[[:space:]]*path[[:space:]]*=/ { p=$0; sub(/^[^=]*=[[:space:]]*/,"",p); path=p }
        END{ flush() }' |
    grep -viE "^(global|sysvol|netlogon|print\\\$|IPC\\\$)$TAB"
}

case "$action" in
  list)
    disk_shares | while IFS="$TAB" read -r s p; do
        [ -n "$p" ] || continue
        u=$(sed -n "s|^$s=||p" "$CACHE" 2>/dev/null | head -1)         # occupé en Ko (cache)
        case "$u" in ''|*[!0-9]*) u=$(du -s -k "$p" 2>/dev/null | awk '{print $1}') ;; esac
        case "$u" in ''|*[!0-9]*) u=0 ;; esac
        q=$(sed -n "s|^$s=||p" "$CONF" 2>/dev/null | head -1)
        case "$q" in ''|*[!0-9]*) q=0 ;; esac
        printf '%s\t%s\t%s\t%s\n' "$s" "$p" "$((u*1024))" "$q"
    done ;;

  set)
    [ -n "$name" ] || { echo "usage: set <partage> <Mo>" >&2; exit 2; }
    case "$name" in *[!A-Za-z0-9._-]*) echo "ERROR: nom de partage invalide" >&2; exit 2 ;; esac
    case "$val" in ''|*[!0-9]*) val=0 ;; esac
    mkdir -p /etc/proxyfibre
    touch "$CONF"; chmod 600 "$CONF"; chown root:root "$CONF" 2>/dev/null || true
    grep -v "^$name=" "$CONF" 2>/dev/null > "$CONF.tmp" || true
    [ "$val" -gt 0 ] && echo "$name=$val" >> "$CONF.tmp"
    mv "$CONF.tmp" "$CONF"
    echo "ok $name=$val" ;;

  scan)
    : > "$CACHE.tmp" 2>/dev/null || { echo "ERROR: cache" >&2; exit 1; }
    disk_shares | while IFS="$TAB" read -r s p; do
        [ -n "$p" ] || continue
        u=$(du -s -k "$p" 2>/dev/null | awk '{print $1}'); case "$u" in ''|*[!0-9]*) u=0 ;; esac
        echo "$s=$u" >> "$CACHE.tmp"
    done
    mv "$CACHE.tmp" "$CACHE" 2>/dev/null; chmod 644 "$CACHE" 2>/dev/null || true
    echo "scan ok" ;;

  enable)
    # Ajoute « dfree command » à chaque section de partage de données qui ne l'a pas encore.
    [ -f "$SHFILE" ] || { echo "ERROR: shares.conf absent" >&2; exit 1; }
    cp "$SHFILE" "$SHFILE.bak-quota" 2>/dev/null || true
    awk -v df="$DFREE" '
        function emit(){ if(insec && data && !hasd) print "   dfree command = " df }
        /^\[/{ emit(); sec=$0; gsub(/[][]/,"",sec); insec=1; hasd=0;
               lc=tolower(sec); data=(lc!="global" && lc!="sysvol" && lc!="netlogon" && lc!="print$"); print; next }
        /dfree command/ { hasd=1 }
        { print }
        END{ emit() }' "$SHFILE" > "$SHFILE.tmp" 2>/dev/null
    if [ -s "$SHFILE.tmp" ] && mv "$SHFILE.tmp" "$SHFILE" && testparm -s >/dev/null 2>&1; then
        smbcontrol all reload-config >/dev/null 2>&1 || true
        echo "application activee"
    else
        cp "$SHFILE.bak-quota" "$SHFILE" 2>/dev/null || true
        echo "ERROR: configuration Samba invalide (retablie)" >&2; exit 1
    fi ;;

  *) echo "usage: share-quota list | set <partage> <Mo> | scan | enable" >&2; exit 2 ;;
esac
