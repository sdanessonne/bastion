#!/bin/bash
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Bastion — pourquoi la console est lente : la mesure, pas l'hypothèse.
#
# « C'est lent » admet une dizaine de causes également plausibles — mémoire
# saturée, annuaire poussif, disque à bout, PHP qui recompile à chaque appel. Les
# départager en lisant le code est impossible : le même code est rapide sur une
# machine et lent sur une autre. Ce script mesure, sur CETTE machine.
#
# Il ne modifie RIEN. Lecture seule, quelques secondes.
#
# Usage :  sudo /usr/local/sbin/proxyfibre-perf-check
set -u

t()  { printf '\n\033[1;36m── %s ─────────────────────────────────────\033[0m\n' "$1"; }
val() { printf '  %-34s %s\n' "$1" "$2"; }

echo "Bastion — diagnostic de performance   $(date '+%d/%m/%Y %H:%M:%S')"
echo "Machine : $(hostname)   ·   $(uptime -p 2>/dev/null || true)"

# ── 1. Mémoire ───────────────────────────────────────────────────────────────
# Premier suspect sur une machine à 3 Go qui fait tourner un contrôleur de
# domaine, une base, un DNS filtrant et un antivirus. Si la machine échange sur
# le disque (swap), TOUT est lent et aucune optimisation du code n'y changera
# quoi que ce soit — c'est la première chose à écarter.
t "Mémoire"
free -h | sed 's/^/  /'
swap_used=$(free -m | awk '/^Swap:/{print $3}')
if [ "${swap_used:-0}" -gt 200 ]; then
  printf '  \033[1;31m→ %s Mo en swap : la machine manque de mémoire. C EST LA CAUSE PRINCIPALE.\033[0m\n' "$swap_used"
elif [ "${swap_used:-0}" -gt 0 ]; then
  printf '  → %s Mo en swap (résidu, pas alarmant)\n' "$swap_used"
else
  echo "  → pas de swap utilisé"
fi

t "Les 8 processus qui consomment le plus de mémoire"
ps -eo rss=,comm= --sort=-rss | head -8 | awk '{printf "  %7.0f Mo  %s\n", $1/1024, $2}'

# ── 2. Charge et processeur ──────────────────────────────────────────────────
t "Charge"
ncpu=$(nproc 2>/dev/null || echo 1)
val "processeurs" "$ncpu"
val "charge (1/5/15 min)" "$(cut -d' ' -f1-3 /proc/loadavg)"
# Une charge durablement supérieure au nombre de cœurs signifie que les requêtes
# attendent leur tour : la console est alors lente sans qu'aucune page ne le soit.
l1=$(cut -d' ' -f1 /proc/loadavg)
awk -v l="$l1" -v n="$ncpu" 'BEGIN{ if (l > n) printf "  \033[1;31m→ charge %.2f pour %d cœur(s) : la machine est saturée\033[0m\n", l, n; else printf "  → charge normale\n" }'

t "Attente disque (iowait)"
# Un iowait élevé = le processeur attend le disque. Sur un disque lent, c'est la
# cause la plus fréquente d'une lenteur qui touche TOUTES les pages à la fois.
if command -v vmstat >/dev/null 2>&1; then
  vmstat 1 3 | tail -1 | awk '{printf "  iowait : %s %%\n", $16; if ($16+0 > 15) printf "  \033[1;31m→ le processeur attend le disque\033[0m\n"; else printf "  → disque non limitant\n"}'
else
  echo "  vmstat absent (paquet procps) — contrôle sauté"
fi

# ── 3. PHP : le code est-il recompilé à chaque requête ? ─────────────────────
# Sans OPcache, PHP relit et recompile TOUS les fichiers à chaque affichage. La
# console fait 1,1 Mo de code : c'est loin d'être gratuit, et cela pèse
# identiquement sur toutes les pages — exactement le symptôme décrit.
t "PHP / OPcache"
ini=$(ls /etc/php/*/apache2/conf.d/*opcache* 2>/dev/null | head -1)
if [ -z "$ini" ]; then
  printf '  \033[1;31m→ aucun fichier de configuration OPcache pour Apache : extension probablement ABSENTE\033[0m\n'
  echo "     correctif :  apt-get install -y php-opcache && systemctl reload apache2"
else
  val "configuration" "$ini"
  etat=$(grep -h '^ *opcache.enable *=' /etc/php/*/apache2/conf.d/*opcache* /etc/php/*/mods-available/opcache.ini 2>/dev/null | tail -1)
  val "opcache.enable" "${etat:-non précisé (défaut = activé)}"
  mem=$(grep -h '^ *opcache.memory_consumption *=' /etc/php/*/mods-available/opcache.ini 2>/dev/null | tail -1)
  val "mémoire allouée" "${mem:-défaut (128 Mo)}"
fi

# ── 4. Le temps de génération, page par page ─────────────────────────────────
# Mesuré depuis la machine elle-même : le réseau et le navigateur sont ainsi hors
# du chiffre. Ce qui reste est le temps que le SERVEUR met à produire la page.
t "Temps de génération des pages (depuis le serveur)"
PORT=8443
if ! ss -lnt 2>/dev/null | grep -q ":$PORT "; then PORT=8080; fi
proto=https; [ "$PORT" = "8080" ] && proto=http
printf '  (%s, port %s — une page protégée répond 302, le temps reste significatif)\n\n' "$proto" "$PORT"
for p in login.php index.php ad.php users.php filter.php systeme.php journal.php cms.php reseau.php; do
  [ -f "/var/www/admin/$p" ] || continue
  # Trois mesures : la première inclut les caches froids, ce qui fausserait le jugement.
  best=99; tot=0; n=0
  for _ in 1 2 3; do
    d=$(curl -sk -o /dev/null -w '%{time_total}' --max-time 30 "$proto://127.0.0.1:$PORT/$p" 2>/dev/null || echo 30)
    tot=$(awk -v a="$tot" -v b="$d" 'BEGIN{print a+b}')
    n=$((n+1))
  done
  moy=$(awk -v t="$tot" -v n="$n" 'BEGIN{printf "%.2f", t/n}')
  couleur=""; fin=""
  awk -v m="$moy" 'BEGIN{exit !(m>1.5)}' && { couleur="\033[1;31m"; fin="\033[0m"; }
  printf "  %b%-16s %6s s%b\n" "$couleur" "$p" "$moy" "$fin"
done

# ── 5. Les gros consommateurs habituels ──────────────────────────────────────
t "Services gourmands"
for s in samba-ad-dc mariadb clamav-daemon dnsmasq freeradius apache2 opennds; do
  if systemctl is-active --quiet "$s" 2>/dev/null; then
    pid=$(systemctl show -p MainPID --value "$s" 2>/dev/null)
    rss=0
    [ -n "$pid" ] && [ "$pid" != "0" ] && rss=$(awk '/VmRSS/{print int($2/1024)}' "/proc/$pid/status" 2>/dev/null || echo 0)
    val "$s" "actif — ${rss} Mo"
  else
    val "$s" "arrêté"
  fi
done
# dnsmasq porte les listes de blocage : elles ont déjà fait grimper sa mémoire à
# 1 Go dans ce projet, en bloquant au passage service-public.fr et debian.org.
if [ -d /etc/dnsmasq.d ]; then
  n=$(cat /etc/dnsmasq.d/*.conf 2>/dev/null | grep -c '^address=/' || true)
  val "domaines bloqués (dnsmasq)" "${n:-0}"
  [ "${n:-0}" -gt 1500000 ] && printf '  \033[1;31m→ liste énorme : dnsmasq consomme et ralentit toute résolution\033[0m\n'
fi

# ── 6. Pages lentes déjà consignées par la console ───────────────────────────
# Le chronomètre de admin/inc/perf.php note chaque page au-delà de 1,5 s.
t "Pages lentes consignées (chronomètre de la console)"
j=$(grep -h 'bastion-perf' /var/log/apache2/*error*.log 2>/dev/null | tail -12)
if [ -n "$j" ]; then printf '%s\n' "$j" | sed 's/^/  /'
else echo "  aucune (chronomètre absent, ou aucune page au-dessus de 1,5 s)"; fi

echo
echo "Fin du diagnostic. Rien n'a été modifié."
