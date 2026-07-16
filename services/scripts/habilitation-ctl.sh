#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — cachet électronique des fiches d'habilitation.
# Signature RSA-2048/SHA-256 via une autorité locale. La clé privée reste
# accessible au seul root ; la console (www-data) appelle ce script via sudo.
#   init         → crée la paire clé/certificat si absente
#   sign         → signe les données lues sur l'entrée standard (base64 en sortie)
#   verify <b64> → vérifie la signature (base64) des données lues sur stdin → VALID|INVALID
#   fingerprint  → empreinte SHA-256 du certificat de l'autorité
#   cert         → certificat X.509 de l'autorité (PEM)
set -eu

D=/etc/proxyfibre
KEY=$D/habilitation.key
CRT=$D/habilitation.crt

ensure() {
    [ -f "$KEY" ] && [ -f "$CRT" ] && return 0
    mkdir -p "$D"
    openssl req -x509 -newkey rsa:2048 -keyout "$KEY" -out "$CRT" -days 3650 -nodes \
        -subj "/O=Bastion/OU=Habilitations/CN=Bastion - Autorite d Habilitation" >/dev/null 2>&1
    chmod 600 "$KEY"; chmod 644 "$CRT"
}

case "${1:-}" in
    init)        ensure; echo ok ;;
    sign)        ensure; openssl dgst -sha256 -sign "$KEY" | openssl base64 -A ;;
    fingerprint) ensure; openssl x509 -in "$CRT" -noout -fingerprint -sha256 | sed 's/.*=//' ;;
    cert)        ensure; cat "$CRT" ;;
    verify)
        ensure
        [ -n "${2:-}" ] || { echo INVALID; exit 0; }
        pub=$(mktemp); sigf=$(mktemp)
        openssl x509 -in "$CRT" -pubkey -noout > "$pub" 2>/dev/null
        printf '%s' "$2" | openssl base64 -d -A > "$sigf" 2>/dev/null || true
        if openssl dgst -sha256 -verify "$pub" -signature "$sigf" >/dev/null 2>&1; then echo VALID; else echo INVALID; fi
        rm -f "$pub" "$sigf" ;;
    *) echo "usage: init | sign | verify <b64> | fingerprint | cert" >&2; exit 2 ;;
esac
