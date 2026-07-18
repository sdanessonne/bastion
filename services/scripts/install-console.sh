#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Installe la présentation console de Bastion : bannière de connexion, logo au démarrage,
# amorçage direct sans menu.
#
# ── POURQUOI CE SCRIPT EXISTE ───────────────────────────────────────────────
# La mise à jour depuis Git aligne le DÉPÔT sur la passerelle, mais ne déploie que la
# console web et le portail : les scripts système, eux, sont installés par deploy.sh, qui
# n'est délibérément pas rejoué (il réécrit les configurations de service et coupe tout).
# Sans ce script, il fallait enchaîner des commandes à la main — et une seule erreur de
# copie suffisait à ce que rien ne s'applique, en silence.
#
# Usage : sudo sh services/scripts/install-console.sh
#         sudo sh services/scripts/install-console.sh --defaire
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "À lancer en root :  sudo sh $0" >&2
    exit 1
fi

# Répertoire du dépôt, déduit de l'emplacement de ce script : le script fonctionne quel
# que soit l'endroit d'où on l'appelle.
ICI=$(cd "$(dirname "$0")" && pwd)

if [ "${1:-}" = "--defaire" ]; then
    echo "Retour à l'état d'origine…"
    [ -x /usr/local/sbin/proxyfibre-brand ] && /usr/local/sbin/proxyfibre-brand --defaire || true
    systemctl disable --now proxyfibre-splash.service 2>/dev/null || true
    systemctl disable --now proxyfibre-issue.service 2>/dev/null || true
    rm -f /etc/systemd/system/proxyfibre-splash.service /etc/systemd/system/proxyfibre-issue.service
    systemctl daemon-reload
    # /etc/issue d'origine : on remet celui de la distribution plutôt que de laisser un
    # fichier vide, sinon l'écran de connexion n'affiche plus rien du tout.
    printf '%s \\n \\l\n\n' "$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-Linux}")" > /etc/issue
    echo "Terminé."
    exit 0
fi

echo "Installation de la présentation console Bastion"
echo "  dépôt : $ICI"

# ── Contrôle préalable ───────────────────────────────────────────────────────
# On vérifie que les fichiers attendus sont là AVANT de toucher quoi que ce soit. Sans ce
# contrôle, une mise à jour Git qui n'a pas abouti donnerait une installation à moitié
# faite, sans que rien ne le signale.
manque=""
for f in issue-banner.sh boot-brand.sh; do
    [ -f "$ICI/$f" ] || manque="$manque $f"
done
if [ -n "$manque" ]; then
    echo "ERREUR : fichier(s) absent(s) du dépôt :$manque" >&2
    echo "La mise à jour depuis Git n'a probablement pas abouti." >&2
    echo "Lancez « sudo /usr/local/sbin/proxyfibre-selfupdate _apply » et lisez son journal." >&2
    exit 1
fi

# ── Scripts ──────────────────────────────────────────────────────────────────
install -D -m755 "$ICI/issue-banner.sh" /usr/local/sbin/proxyfibre-issue
install -D -m755 "$ICI/boot-brand.sh"   /usr/local/sbin/proxyfibre-brand
echo "  ✓ scripts installés dans /usr/local/sbin"

# ── Unités systemd ───────────────────────────────────────────────────────────
cat > /etc/systemd/system/proxyfibre-issue.service <<'UNIT'
[Unit]
Description=Bastion - banniere de la console locale
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/proxyfibre-issue
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/proxyfibre-splash.service <<'UNIT'
[Unit]
Description=Bastion - logo au demarrage
DefaultDependencies=no
After=systemd-vconsole-setup.service
Before=sysinit.target
Conflicts=shutdown.target
Before=shutdown.target

[Service]
Type=oneshot
ExecStart=-/usr/local/sbin/proxyfibre-issue --splash
TimeoutStartSec=5
RemainAfterExit=no

[Install]
WantedBy=sysinit.target
UNIT

systemctl daemon-reload
systemctl enable proxyfibre-issue.service  >/dev/null 2>&1
systemctl enable proxyfibre-splash.service >/dev/null 2>&1
echo "  ✓ services systemd activés"

# ── Application immédiate ────────────────────────────────────────────────────
/usr/local/sbin/proxyfibre-issue
echo "  ✓ bannière de connexion écrite dans /etc/issue"

/usr/local/sbin/proxyfibre-brand >/dev/null
echo "  ✓ amorçage direct, sans menu ni mention de la distribution"

echo
echo "Fait. Contrôle immédiat, sans redémarrer :"
echo "    sudo /usr/local/sbin/proxyfibre-issue --splash    (affiche le logo sur tty1)"
echo "    Alt+F2                                            (voir la bannière de connexion)"
echo
echo "ACCÈS DE SECOURS après redémarrage : maintenez MAJ pour rappeler le menu"
echo "d'amorçage. Vérifiez-le une fois à froid, pas le jour d'une panne."
echo
echo "Tout défaire :  sudo sh $0 --defaire"
