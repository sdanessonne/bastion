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
# Où vit le code. Un chemin faux ne provoquerait AUCUNE erreur : les contrôles ne
# trouveraient simplement rien à inspecter et passeraient au vert à tort — ce qui
# est pire qu'un échec. Le chemin était code en dur sur /home/proxyfibre/proxyFibre ;
# une installation depuis un compte portant un autre nom donnait alors un serveur
# incapable de se mettre à jour, sans que rien ne le signale. deploy.sh, lui, sait
# où il est : il l'écrit dans repo.env, et tout le monde le relit ici.
[ -r /etc/proxyfibre/repo.env ] && . /etc/proxyfibre/repo.env
REPO_DIR="${REPO_DIR:-/home/proxyfibre/proxyFibre}"

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

# ── 4 ter) Le mot de passe d'installation est-il encore valide ? ─────────────
# Le fichier /etc/proxyfibre/admin-pass.env contient EN CLAIR le mot de passe
# engendré à l'installation. S'il ouvre encore la console, c'est qu'il n'a jamais
# été changé — or il a pu être communiqué, imprimé, envoyé par courriel.
# Ce contrôle a une seconde utilité : le déploiement REMETTAIT ce mot de passe à
# chaque exécution, annulant en silence celui qu'on avait choisi. Si ce défaut
# réapparaissait, ce contrôle repasserait au rouge.
h "Mot de passe de la console"
PASSF=/etc/proxyfibre/admin-pass.env
if [ ! -r "$PASSF" ]; then
    ok "aucun mot de passe d'installation conservé"
else
    # La comparaison se fait par password_verify, jamais en affichant la valeur.
    ADMP=$(sed -n 's/^ADMIN_PASS="\(.*\)"$/\1/p' "$PASSF" 2>/dev/null | head -1)
    ADMH=$(mysql -N -B radius -e "SELECT password_hash FROM pf_admins WHERE username='admin' LIMIT 1;" 2>/dev/null)
    if [ -z "$ADMP" ] || [ -z "$ADMH" ]; then
        wn "comparaison impossible (fichier ou compte illisible)"
    elif PF_P="$ADMP" PF_H="$ADMH" php -r 'exit(password_verify(getenv("PF_P"), getenv("PF_H")) ? 0 : 1);' 2>/dev/null; then
        ko "le mot de passe D'INSTALLATION ouvre encore la console — jamais changé"
        echo "     → sudo proxyfibre-admin-passwd     (la saisie n'est pas affichée)"
    else
        ok "le mot de passe a été changé depuis l'installation"
    fi
fi

# ── 4 bis) La racine du portail mène AU PORTAIL ──────────────────────────────
# Le vhost du portail a pour racine /var/www/html, où Debian laisse son
# « Apache2 Debian Default Page ». Rien ne signalait le problème : Apache
# répondait 200, le service était « actif », et le contrôle des pages ci-dessus
# ne teste que la console. Seul un agent tapant l'adresse sans chemin le voyait —
# et concluait que la passerelle était en panne.
h "Racine du portail"
# L'adresse est dans net.env, PAS dans config.env : le contrôle visait le mauvais
# fichier et se sautait poliment en annonçant « adresse LAN inconnue ». Il
# paraissait donc s'exécuter alors qu'il ne vérifiait rien — le défaut même qu'il
# est censé détecter ailleurs.
LAN_IP_T=$(sed -n 's/^LAN_IP=//p' /etc/proxyfibre/net.env 2>/dev/null | tr -d '"' | head -1)
if [ -z "$LAN_IP_T" ]; then
  wn "adresse LAN inconnue (config.env) — contrôle sauté"
else
  for pt in 2443 2080; do
    corps=$(curl -sk --max-time 10 "https://$LAN_IP_T:$pt/" 2>/dev/null ||             curl -s  --max-time 10 "http://$LAN_IP_T:$pt/"  2>/dev/null)
    dest=$(curl -sk -o /dev/null -w '%{redirect_url}' --max-time 10 "https://$LAN_IP_T:$pt/" 2>/dev/null || true)
    if printf '%s' "$corps" | grep -qi 'Debian Default Page'; then
      ko "port $pt : la racine sert la page Apache par défaut"
    elif printf '%s' "$dest" | grep -q 'fas.php' || printf '%s' "$corps" | grep -qi 'fas.php\|Bastion'; then
      ok "port $pt : la racine mène au portail"
    else
      wn "port $pt : racine ni page Debian ni portail (à vérifier)"
    fi
  done
fi

# ── 4 quater) La chaîne d'alerte par courriel est-elle réellement debout ? ───
# Une adresse enregistrée et rien qui part, c'est le pire cas : on cesse de
# surveiller soi-même en croyant être prévenu. Ce contrôle a une seconde raison
# d'être — lors de la mise au point, le binaire msmtp a été REMPLACÉ par un
# script qui avalait les messages en rapportant un succès. La console affichait
# vert, le journal d'audit aussi, et rien ne partait. Un exécutable qui n'en est
# plus un ne se voit que si on le regarde.
h "Alertes par courriel"
DEST_AL=$(mysql -N -B radius -e "SELECT v FROM pf_settings WHERE k='alert_email' LIMIT 1;" 2>/dev/null)
if [ -z "$DEST_AL" ]; then
    wn "aucune adresse de notification — les anomalies restent au journal système"
elif [ ! -x /usr/sbin/sendmail ]; then
    ko "adresse renseignée ($DEST_AL) mais AUCUN agent de messagerie : rien ne part"
elif ! file -L /usr/sbin/sendmail 2>/dev/null | grep -q 'ELF'; then
    ko "/usr/sbin/sendmail n'est pas un exécutable — messages avalés en silence"
else
    et=$(/usr/local/sbin/proxyfibre-mail state 2>/dev/null)
    case "$et" in
        *'"configure":true'*) ok "envoi possible vers $DEST_AL" ;;
        *) ko "adresse renseignée ($DEST_AL) mais relais SMTP non configuré" ;;
    esac
fi

# ── 4 ter) OPcache : le code est-il recompilé à chaque affichage ? ───────────
# Une régression ici ne casse rien : la console fonctionne, elle est seulement
# deux à trois fois plus lente. Personne ne l'attribuerait à une extension PHP —
# c'est exactement le genre de panne qu'il faut rendre visible.
h "OPcache (compilation évitée)"
if ! php -m 2>/dev/null | grep -qi '^Zend OPcache$'; then
  ko "extension OPcache ABSENTE — PHP recompile tout le code à chaque page"
elif ! ls /etc/php/*/apache2/conf.d/*opcache*.ini >/dev/null 2>&1; then
  wn "extension présente mais aucune configuration pour Apache"
else
  sonde=/var/www/html/.opcache-selftest.php
  printf '<?php $s=function_exists("opcache_get_status")?@opcache_get_status(false):false; echo ($s && !empty($s["opcache_enabled"]))?"ACTIF":"INACTIF";' > "$sonde" 2>/dev/null
  etat=$(curl -s --max-time 8 "http://127.0.0.1:2080/.opcache-selftest.php" 2>/dev/null || echo "")
  rm -f "$sonde"
  case "$etat" in
    ACTIF)   ok "OPcache actif dans Apache" ;;
    INACTIF) ko "OPcache configuré mais INACTIF dans Apache" ;;
    *)       wn "état d'OPcache non vérifiable (sonde injoignable)" ;;
  esac
fi

# ── 5) Scripts des postes : encodage et caractères ───────────────────────────
# Une faute d'encodage ne se voit NULLE PART côté serveur : le fichier est bien écrit,
# bien déployé, bien lu par le poste... qui n'arrive pas à l'analyser et n'exécute alors
# pas une seule ligne, pas même sa propre journalisation. C'est arrivé deux fois (photo
# de l'agent, applications). Ce contrôle inspecte le SYSVOL, donc CE QUI EST RÉELLEMENT
# DÉPLOYÉ, et pas seulement les générateurs.
h "Scripts des postes (encodage)"
if [ -x "$SBIN/check-scripts.py" ]; then
  res=$(python3 "$SBIN/check-scripts.py" "$REPO_DIR" 2>&1)
  if [ $? -eq 0 ]; then
    ok "PowerShell/cmd : marque UTF-8 et caractères conformes"
  else
    echo "$res" | sed -n 's/^  X /  KO   /p'
    fail=$((fail + $(echo "$res" | grep -c '^  X ')))
  fi
else
  wn "check-scripts.py non déployé — contrôle d'encodage ignoré"
fi

# ── 5 bis) Scripts ENGENDRÉS par le fabricant d'ISO ──────────────────────────
# Même piège, autre bout de la chaîne : build-iso.sh peut être parfaitement valide
# et écrire dans l'image des scripts qui ne s'exécutent pas. C'est arrivé : le
# script de premier démarrage est mort en UNE SECONDE, et le serveur a démarré sur
# un écran Debian nu. On contrôle donc ce qui est ÉCRIT, pas seulement l'écrivain.
h "Fabricant d'ISO (scripts engendrés)"
BI="$REPO_DIR/provisioning/iso/build-iso.sh"
if [ -f "$BI" ]; then
  if sh -n "$BI" 2>/dev/null; then ok "build-iso.sh : syntaxe"; else
    echo "  KO   build-iso.sh : syntaxe"; fail=$((fail + 1)); fi
  tmpg=$(mktemp -d)
  sed -n "/<<'CPEOF'/,/^CPEOF\$/p" "$BI" | sed '1d;$d' > "$tmpg/copier.sh"
  sed -n "/<<INITEOF/,/^INITEOF\$/p" "$BI" | sed '1d;$d' \
    | sed 's/\\\$/$/g; s/'"'"'\$DEPOT'"'"'/DEPOT/' > "$tmpg/init.sh"
  for g in copier.sh init.sh; do
    if [ ! -s "$tmpg/$g" ]; then
      echo "  KO   $g : absent de build-iso.sh"; fail=$((fail + 1))
    elif sh -n "$tmpg/$g" 2>/dev/null; then ok "$g engendré : syntaxe"
    else echo "  KO   $g engendré : syntaxe"; fail=$((fail + 1)); fi
  done
  # Le préréglage : chaque ligne d'un late_command doit porter sa continuation.
  PS="$REPO_DIR/provisioning/iso/preseed.cfg"
  if [ -f "$PS" ]; then
    awk '/^d-i preseed\/late_command/{f=1} f && /^$/{f=0} f' "$PS" | awk 'NF' > "$tmpg/lc"
    n=$(wc -l < "$tmpg/lc")
    bad=$(head -n $((n - 1)) "$tmpg/lc" | grep -vc '\\$')
    if [ "$bad" = 0 ] && [ "$n" -gt 1 ]; then ok "préréglage : late_command continu ($n lignes)"
    else echo "  KO   préréglage : $bad continuation(s) manquante(s)"; fail=$((fail + 1)); fi
  fi
  rm -rf "$tmpg"
else
  wn "build-iso.sh introuvable — contrôle du fabricant d'ISO ignoré"
fi

# ── 6) Contrôles approfondis (mode full uniquement) ──────────────────────────
if [ "$MODE" != "quick" ]; then
  h "Python (py_compile)"
  for p in gpo-apply gpo-apps gpo-kms gpo-drives gpo-bitlocker gpo-timesync gpo-inventory \
           gpo-wmi gpo-health gpo-photo; do
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
