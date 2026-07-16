#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Signature électronique CMS/PKCS#7 DÉTACHÉE d'un fichier (dossiers de réquisition).
# Émet, au premier usage, un certificat de signature « Bastion — Signature de réquisition »
# issu de l'Autorité de certification Bastion, puis signe le fichier fourni.
# Appelé par la console admin via sudo (liste blanche). Chemins limités au scratch.
#
# Usages :
#   proxyfibre-sign sign <fichier_in> <sortie.p7s>   → signe, écrit la signature détachée
#   proxyfibre-sign info                             → empreinte + validité du cert de signature
set -eu

DIR=/etc/proxyfibre
CA="$DIR/bastion-ca.crt"; CAK="$DIR/bastion-ca.key"
CRT="$DIR/requisition.crt"; KEY="$DIR/requisition.key"

ensure_cert() {
    if [ -s "$CRT" ] && [ -s "$KEY" ]; then return 0; fi
    [ -s "$CA" ] && [ -s "$CAK" ] || { echo "ERROR: AC Bastion introuvable" >&2; exit 1; }
    csr=$(mktemp); ext=$(mktemp)
    printf 'keyUsage=critical,digitalSignature,nonRepudiation\nextendedKeyUsage=emailProtection\nbasicConstraints=critical,CA:FALSE\n' > "$ext"
    openssl req -new -newkey rsa:2048 -nodes -keyout "$KEY" -out "$csr" \
        -subj "/O=Bastion/OU=Reponses judiciaires/CN=Bastion - Signature de requisition" 2>/dev/null
    openssl x509 -req -in "$csr" -CA "$CA" -CAkey "$CAK" -CAcreateserial -days 3652 -sha256 \
        -extfile "$ext" -out "$CRT" 2>/dev/null
    chmod 600 "$KEY"; chmod 644 "$CRT"
    rm -f "$csr" "$ext"
}

case "${1:-}" in
    sign)
        IN="${2:-}"; OUT="${3:-}"
        [ -n "$IN" ] && [ -n "$OUT" ] || { echo "usage: sign <in> <out.p7s>" >&2; exit 2; }
        case "$IN"  in /tmp/*|/dev/shm/*) ;; *) echo "ERROR: chemin d'entree refuse" >&2; exit 2 ;; esac
        case "$OUT" in /tmp/*|/dev/shm/*) ;; *) echo "ERROR: chemin de sortie refuse" >&2; exit 2 ;; esac
        [ -f "$IN" ] || { echo "ERROR: fichier absent" >&2; exit 2; }
        ensure_cert
        openssl cms -sign -binary -in "$IN" -signer "$CRT" -inkey "$KEY" -certfile "$CA" \
            -outform DER -out "$OUT" 2>/dev/null || { echo "ERROR: signature echouee" >&2; exit 1; }
        chmod 644 "$OUT"
        echo "OK $(openssl x509 -in "$CRT" -noout -fingerprint -sha256 2>/dev/null | sed 's/.*=//')"
        ;;
    info)
        ensure_cert
        openssl x509 -in "$CRT" -noout -subject -issuer -serial -enddate -fingerprint -sha256 2>/dev/null
        ;;
    *) echo "action refusee" >&2; exit 2 ;;
esac
