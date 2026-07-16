#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
# Bastion — attend que l'ensemble nftables @walledgarden d'OpenNDS soit prêt,
# puis redémarre dnsmasq pour qu'il puisse y ajouter les IP des serveurs de MAJ.
# Appelé après le (re)démarrage d'OpenNDS. Robuste à la lenteur de démarrage.
i=0
while [ $i -lt 60 ]; do
    if nft list set ip nds_filter walledgarden >/dev/null 2>&1; then
        systemctl restart dnsmasq
        # TFTP (PXE) : le helper conntrack marque le flux de données (port éphémère du
        # serveur) comme RELATED ; la règle established/related laisse passer les ACK du
        # client vers ce port. Cela permet d'abandonner « tftp-single-port » (qui ne sert
        # qu'un transfert à la fois → un transfert bloqué retardait tous les suivants de ~4s
        # et faisait échouer le PXE). Transferts désormais parallèles et immédiats.
        modprobe nf_conntrack_tftp 2>/dev/null || true
        nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "established" || \
            nft insert rule ip nds_filter ndsRTR ct state established,related counter accept 2>/dev/null || true
        # Page de blocage : les domaines filtrés résolvent vers la passerelle ; les postes
        # doivent pouvoir joindre le vhost de blocage sur 80/443 du routeur.
        for p in 80 443; do
            nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "dport ${p} " || \
                nft insert rule ip nds_filter ndsRTR tcp dport ${p} counter accept 2>/dev/null || true
        done
        # Contrôleur de domaine AD : accès complet au DC (.2) sans auth captive
        # (jonction, Kerberos, LDAP, SMB). .2 est une IP DU ROUTEUR → l'accès passe
        # par ndsRTR (users_to_router), pas par le walled garden (qui gère le forward).
        if systemctl is-active --quiet samba-ad-dc; then
            nft add element ip nds_filter walledgarden { 192.168.182.2 } 2>/dev/null || true
            nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "192.168.182.2" || \
                nft insert rule ip nds_filter ndsRTR ip daddr 192.168.182.2 counter accept 2>/dev/null || true
        fi
        # Serveur KMS (activation Windows/Office) : port 1688 accessible aux postes.
        if systemctl is-active --quiet proxyfibre-kms; then
            nft list chain ip nds_filter ndsRTR 2>/dev/null | grep -q "dport 1688" || \
                nft insert rule ip nds_filter ndsRTR tcp dport 1688 counter accept 2>/dev/null || true
        fi
        exit 0
    fi
    i=$((i + 1))
    sleep 2
done
exit 0
