#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Point d'accès Wi-Fi : régénère /etc/hostapd/hostapd.conf depuis pf_settings, puis
# relance hostapd. Appelé par la console (liste blanche sudo).
#
# AUCUNE donnée n'est passée en argument : le SSID et la phrase secrète sont LUS EN
# BASE. C'est la règle des autres scripts pilotés depuis le web (dhcp-ctl, qos-ctl) et
# elle a une raison précise — une phrase secrète arrivant par la ligne de commande
# apparaîtrait dans « ps », dans les journaux du shell, et ouvrirait une injection.
# La source fait autorité.
set -u
CONF=/etc/hostapd/hostapd.conf

reglage() { mysql -N radius -e "SELECT v FROM pf_settings WHERE k='$1' LIMIT 1" 2>/dev/null | head -1; }

case "${1:-}" in
  apply)
    SSID=$(reglage wifi_ssid)
    PSK=$(reglage wifi_psk)
    CANAL=$(reglage wifi_channel)
    [ -n "$SSID" ] || { echo "aucun SSID enregistre"; exit 1; }

    # REVALIDATION côté serveur. La page a déjà contrôlé, mais une validation qui
    # n'existe qu'en PHP n'est pas une validation : elle protège l'utilisateur
    # distrait, pas le système. Les bornes viennent de la norme 802.11 elle-même —
    # hostapd refuserait de démarrer, et le point d'accès disparaîtrait sans un mot.
    LS=$(printf '%s' "$SSID" | wc -c)
    LP=$(printf '%s' "$PSK"  | wc -c)
    [ "$LS" -ge 1 ] && [ "$LS" -le 32 ] || { echo "SSID hors bornes (1 a 32 caracteres)"; exit 1; }
    [ "$LP" -ge 8 ] && [ "$LP" -le 63 ] || { echo "phrase secrete hors bornes (8 a 63 caracteres)"; exit 1; }
    # Ni saut de ligne ni guillemet : on écrit un fichier de configuration clé=valeur.
    printf '%s' "$SSID$PSK" | grep -q '[[:cntrl:]]' && { echo "caractere de controle refuse"; exit 1; }
    case "$CANAL" in ''|*[!0-9]*) CANAL=6 ;; esac
    [ "$CANAL" -ge 1 ] && [ "$CANAL" -le 13 ] || CANAL=6

    # L'interface sans fil n'est pas codée en dur : sur un autre matériel elle ne
    # s'appelle pas pareil. On reprend celle du fichier en place, sinon on cherche
    # la première carte sans fil du système.
    WIF=$(sed -n 's/^interface=//p' "$CONF" 2>/dev/null | head -1)
    [ -n "$WIF" ] || WIF=$(basename "$(find /sys/class/net -maxdepth 1 -name '*' -exec test -d '{}/wireless' \; -print 2>/dev/null | head -1)" 2>/dev/null)
    [ -n "$WIF" ] || { echo "aucune carte sans fil trouvee"; exit 1; }
    PONT=$(sed -n 's/^bridge=//p' "$CONF" 2>/dev/null | head -1)

    tmp=$(mktemp)
    {
      echo "# Bastion — point d'acces Wi-Fi (genere automatiquement, ne pas editer)"
      echo "# Modifier depuis la console : Reseau → Point d'acces Wi-Fi."
      echo "interface=$WIF"
      [ -n "$PONT" ] && echo "bridge=$PONT"
      echo "driver=nl80211"
      echo "country_code=FR"
      echo "ieee80211d=1"
      echo "ssid=$SSID"
      echo "hw_mode=g"
      echo "channel=$CANAL"
      echo "wmm_enabled=1"
      echo "auth_algs=1"
      echo "ignore_broadcast_ssid=0"
      echo "wpa=2"
      echo "wpa_key_mgmt=WPA-PSK"
      echo "rsn_pairwise=CCMP"
      echo "wpa_passphrase=$PSK"
    } > "$tmp"
    install -m600 -o root -g root "$tmp" "$CONF"
    rm -f "$tmp"

    # On garde l'ANCIENNE configuration le temps de vérifier que la nouvelle démarre.
    # Un SSID accepté par la page mais refusé par hostapd couperait le Wi-Fi de tout
    # un site, et l'administrateur — souvent connecté PAR ce Wi-Fi — n'aurait plus
    # aucun moyen de revenir en arrière.
    if systemctl restart hostapd >/dev/null 2>&1 && sleep 3 && systemctl is-active --quiet hostapd; then
        echo "point d acces applique : $SSID (canal $CANAL)"
    else
        echo "ECHEC : hostapd n a pas demarre avec cette configuration."
        systemctl status hostapd --no-pager -n 5 2>&1 | tail -4
        exit 1
    fi
    ;;

  state)
    SSID=$(sed -n 's/^ssid=//p' "$CONF" 2>/dev/null | head -1)
    CANAL=$(sed -n 's/^channel=//p' "$CONF" 2>/dev/null | head -1)
    WIF=$(sed -n 's/^interface=//p' "$CONF" 2>/dev/null | head -1)
    PONT=$(sed -n 's/^bridge=//p' "$CONF" 2>/dev/null | head -1)
    ACTIF=$(systemctl is-active hostapd 2>/dev/null)
    # Nombre de terminaux associés — un chiffre concret vaut mieux qu'un voyant vert.
    CLIENTS=$(iw dev "$WIF" station dump 2>/dev/null | grep -c '^Station')
    printf '{"ssid":"%s","canal":"%s","interface":"%s","pont":"%s","actif":"%s","clients":%s}\n' \
        "$SSID" "$CANAL" "$WIF" "$PONT" "$ACTIF" "${CLIENTS:-0}"
    ;;

  scan)
    # ── OCCUPATION DU SPECTRE 2,4 GHz ───────────────────────────────────────
    # But : choisir un canal, pas afficher une jolie courbe.
    #
    # On aurait préféré « iw survey dump », qui donne le temps d'occupation RÉEL du
    # canal — la seule mesure honnête. Le pilote rtw88 de ce dongle ne le remonte
    # pas : la commande sort vide. On se rabat donc sur le balayage des réseaux
    # voisins, qui lui fonctionne même en mode point d'accès (ce n'était pas acquis).
    #
    # LE POINT QUI COMPTE : en 2,4 GHz les canaux se CHEVAUCHENT. Un réseau sur le
    # canal 6 gêne les canaux 4 à 8. Compter les réseaux « sur le canal 1 » pour
    # juger le canal 1 donnerait une réponse fausse — c'est pourquoi le score étale
    # chaque réseau sur ses voisins, pondéré par l'écart ET par sa puissance.
    WIF=$(sed -n 's/^interface=//p' "$CONF" 2>/dev/null | head -1)
    [ -n "$WIF" ] || { echo '{"erreur":"aucune interface sans fil"}'; exit 1; }

    # Le balayage fait quitter le canal quelques centaines de millisecondes : les
    # clients connectés peuvent avoir un hoquet. C'est pour cela qu'il est déclenché
    # à la demande depuis la console, et jamais au chargement de la page.
    # NOTRE PROPRE BALISE figure dans le résultat du balayage. Sans l'écarter, notre
    # point d'accès se compte lui-même : le canal occupé paraît toujours être celui
    # qu'on utilise, et l'outil conseille invariablement d'en changer. Constaté à la
    # première exécution — deux réseaux sur le canal 6, dont le nôtre.
    MOI=$(cat "/sys/class/net/$WIF/address" 2>/dev/null | tr 'A-Z' 'a-z')

    iw dev "$WIF" scan 2>/dev/null | awk -v actuel="$(sed -n 's/^channel=//p' "$CONF" 2>/dev/null | head -1)" -v moi="$MOI" '
      /^BSS /            { if (ch > 0 && !soi) enregistrer()
                           ch = 0; sig = -100
                           bss = $2; sub(/\(.*/, "", bss)
                           soi = (tolower(bss) == moi) }
      /^[ \t]*freq:/     { f = $2 + 0
                           if (f == 2484) ch = 14
                           else if (f >= 2412 && f <= 2472) ch = int((f - 2407) / 5 + 0.5) }
      /^[ \t]*signal:/   { sig = $2 + 0 }
      END                { if (ch > 0 && !soi) enregistrer(); sortir() }

      function enregistrer(   d, c, poids, force) {
        nb[ch]++
        if (sig > pic[ch] || pic[ch] == 0) pic[ch] = sig
        # Puissance ramenée à une échelle simple : -50 dBm → 50, -90 dBm → 10.
        # Un réseau lointain gêne réellement moins qu’un réseau proche.
        force = sig + 100; if (force < 1) force = 1
        for (d = -4; d <= 4; d++) {
          c = ch + d
          if (c < 1 || c > 14) continue
          poids = 1 - (d < 0 ? -d : d) / 5      # 1 sur le canal même, 0,2 à 4 canaux
          score[c] += force * poids
        }
      }
      function sortir(   c, max, best, bestv, virgule) {
        max = 0
        for (c = 1; c <= 13; c++) if (score[c] > max) max = score[c]
        bestv = -1
        for (c = 1; c <= 13; c++) {
          # À égalité on préfère 1, 6 ou 11 : les seuls qui ne se chevauchent pas
          # entre eux. Un canal « libre » coincé entre deux voisins encombrés est
          # un mauvais choix que le score seul ne suffit pas toujours à écarter.
          v = -score[c] + ((c == 1 || c == 6 || c == 11) ? 0.5 : 0)
          if (v > bestv) { bestv = v; best = c }
        }
        printf "{\"actuel\":%d,\"conseille\":%d,\"canaux\":[", actuel + 0, best
        for (c = 1; c <= 13; c++) {
          printf "%s{\"canal\":%d,\"reseaux\":%d,\"pic\":%d,\"charge\":%d}",
                 virgule, c, nb[c] + 0, pic[c] + 0,
                 (max > 0 ? int(score[c] * 100 / max + 0.5) : 0)
          virgule = ","
        }
        printf "]}\n"
      }'
    ;;

  *)
    echo "Usage: $0 apply|state|scan" >&2
    exit 1
    ;;
esac
