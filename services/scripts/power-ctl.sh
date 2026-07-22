#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Redémarrage / arrêt de la passerelle, déclenché depuis la console d'administration via
# sudo. Deux verbes seulement, en liste fermée — aucun argument libre.
#
# ── CE QUE CELA DONNE À LA CONSOLE WEB ──────────────────────────────────────
# Le pouvoir de couper la passerelle. Sur une machine dont le rôle est de faire tenir le
# réseau, c'est une action à fort impact : un arrêt coupe l'accès de TOUS les postes, et
# personne ne peut la rallumer à distance. La console encadre donc l'appel (confirmation,
# et pour l'arrêt une case à cocher explicite) ; ce script, lui, se contente d'exécuter
# le verbe demandé s'il fait partie des deux autorisés.
#
# Usage : proxyfibre-power reboot | poweroff
set -eu

case "${1:-}" in
    reboot)
        # « systemctl reboot » place la demande dans systemd et rend la main aussitôt : la
        # page de confirmation a le temps d'arriver au navigateur avant que les services ne
        # s'arrêtent. Le message « wall » prévient une éventuelle session sur la console.
        echo "OK: redémarrage en cours."
        wall "Redémarrage demandé depuis la console Bastion." 2>/dev/null || true
        systemctl reboot
        ;;
    poweroff)
        echo "OK: arrêt en cours."
        wall "Arrêt demandé depuis la console Bastion." 2>/dev/null || true
        systemctl poweroff
        ;;
    *)
        echo "usage: proxyfibre-power reboot | poweroff" >&2
        exit 2
        ;;
esac
