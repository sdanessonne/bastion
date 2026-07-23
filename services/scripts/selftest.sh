#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# proxyfibre-selftest — batterie de contrôles anti-régression de la passerelle.
# But : transformer les pannes « silencieuses » (page PHP cassée, script non déployé,
# service arrêté) en un rapport immédiat OK / KO. Lancé automatiquement à la fin de
# chaque « selfupdate _apply », et à la demande par l'administrateur.
#
# Usage : proxyfibre-selftest [quick|full]   (défaut : full)
#   quick = lint PHP + scripts + services + pages essentielles (rapide, post-mise à jour)
#   full  = quick + toutes les pages + py_compile + intégrité du catalogue GPO
# Sortie : 0 si aucun échec, 1 sinon.
set -u
MODE="${1:-full}"
WWW=/var/www/admin
BASE=https://127.0.0.1:8443
SBIN=/usr/local/sbin

pass=0; fail=0; warn=0
ok() { pass=$((pass+1)); printf '  OK   %s\n' "$1"; }
ko() { fail=$((fail+1)); printf '  KO   %s\n' "$1"; }
wn() { warn=$((warn+1)); printf '  --   %s\n' "$1"; }
h()  { printf '\n== %s ==\n' "$1"; }

# ── 1) Lint PHP : toutes les pages et includes compilent ─────────────────────
h "Lint PHP"
if command -v php >/dev/null 2>&1; then
  nerr=0; ntot=0
  for f in "$WWW"/*.php "$WWW"/inc/*.php; do
    [ -f "$f" ] || continue
    ntot=$((ntot+1))
    php -l "$f" >/dev/null 2>&1 || { ko "php -l $(basename "$f")"; nerr=$((nerr+1)); }
  done
  [ "$nerr" -eq 0 ] && ok "les $ntot fichiers PHP compilent"
else
  wn "php introuvable — lint ignoré"
fi

# ── 2) Scripts privilégiés : tous ceux de la table de déploiement sont posés ──
# Attrape la panne « selfupdate n'a déployé qu'une partie des scripts ».
h "Scripts privilégiés (/usr/local/sbin)"
missing=0
for s in netguard qos issue brand make-web-cert apply-filter update-adblock service \
         apt update-conf syspasswd power speedtest clamav ad gpo-apply gpo-apps gpo-kms \
         gpo-drives gpo-bitlocker metrics-sample backup habilitation sign purge-logs \
         walledgarden-refresh weblog-ingest account-expiry dhcp quarantine selftest anomaly; do
  [ -x "$SBIN/proxyfibre-$s" ] || { ko "proxyfibre-$s absent ou non exécutable"; missing=$((missing+1)); }
done
[ "$missing" -eq 0 ] && ok "les 32 scripts privilégiés sont présents"

# ── 3) Services critiques actifs ─────────────────────────────────────────────
h "Services"
for svc in apache2 mariadb dnsmasq freeradius opennds samba-ad-dc; do
  if systemctl is-active --quiet "$svc" 2>/dev/null; then ok "$svc actif"
  elif systemctl cat "$svc" >/dev/null 2>&1; then ko "$svc INACTIF"
  else wn "$svc non installé"; fi
done

# ── 4) Pages de la console : répondent sans erreur serveur (500 = régression) ─
# Une page protégée renvoie 302 (redirection login) ; l'essentiel est qu'elle
# s'exécute sans erreur fatale PHP (500) ni crash (000).
h "Pages console"
check_page() {
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$BASE/$1" 2>/dev/null)
  case "$code" in
    2*|3*) ok "$1 → $code" ;;
    000)   ko "$1 → injoignable" ;;
    5*)    ko "$1 → $code (erreur serveur)" ;;
    *)     wn "$1 → $code" ;;
  esac
}
if [ "$MODE" = "quick" ]; then
  for p in login.php ad.php users.php sauvegarde.php systeme.php journal.php antivirus.php cms.php; do
    check_page "$p"
  done
else
  for f in "$WWW"/*.php; do
    p=$(basename "$f")
    case "$p" in watchdog.php|logseal.php) continue ;; esac  # internes (non servis en GET)
    check_page "$p"
  done
fi

# ── 5) Contrôles approfondis (mode full uniquement) ──────────────────────────
if [ "$MODE" != "quick" ]; then
  h "Python (py_compile)"
  for p in gpo-apply gpo-apps gpo-kms gpo-drives gpo-bitlocker; do
    b="$SBIN/proxyfibre-$p"; [ -f "$b" ] || continue
    if python3 -c 'import py_compile,sys; py_compile.compile(sys.argv[1],doraise=True)' "$b" >/dev/null 2>&1; then
      ok "py_compile $p"
    else
      ko "py_compile $p"
    fi
  done

  h "Intégrité du catalogue GPO"
  if command -v php >/dev/null 2>&1 && [ -f "$WWW/inc/gpo-catalog.php" ]; then
    res=$(php -r '
      $c = require $argv[1]; $bad = 0;
      foreach ($c as $k => $e) {
        foreach (["cat","title","icon","scope","desc","policies"] as $f) if (!isset($e[$f])) { echo "champ $f manquant dans $k\n"; $bad++; }
        foreach ($e["policies"] as $p) {
          foreach (["keyname","valuename","class","type","data"] as $f) if (!array_key_exists($f,$p)) { echo "$k: policy sans $f\n"; $bad++; }
          if (!in_array($p["type"] ?? "", ["REG_SZ","REG_DWORD","REG_QWORD","REG_BINARY","REG_MULTI_SZ","REG_EXPAND_SZ"])) { echo "$k: type invalide\n"; $bad++; }
          if (!in_array($p["class"] ?? "", ["MACHINE","USER","BOTH"])) { echo "$k: class invalide\n"; $bad++; }
        }
      }
      echo $bad ? "PROBLEMES=$bad\n" : ("OK=".count($c)."\n");
    ' "$WWW/inc/gpo-catalog.php" 2>&1)
    case "$res" in
      OK=*) ok "catalogue GPO valide (${res#OK=} entrées)" ;;
      *)    ko "catalogue GPO : $(echo "$res" | tr '\n' ' ')" ;;
    esac
  else
    wn "catalogue GPO introuvable"
  fi
fi

# ── Résumé ───────────────────────────────────────────────────────────────────
printf '\nRésumé : %d OK · %d avertissement(s) · %d échec(s)\n' "$pass" "$warn" "$fail"
[ "$fail" -eq 0 ] || exit 1
exit 0
