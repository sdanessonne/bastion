# Architecture Bastion

## Vue d'ensemble

Bastion est une passerelle Linux placée **en coupure** entre le lien Internet (fibre) et le
réseau local des utilisateurs. Tout le trafic LAN → Internet transite obligatoirement par elle,
ce qui permet d'imposer authentification, filtrage, quotas et journalisation.

## Flux d'authentification (portail captif)

```
Client LAN                CoovaChilli              Portail (Apache/PHP)      FreeRADIUS
   │                          │                          │                      │
   │ 1. DHCP (obtient IP)     │                          │                      │
   │─────────────────────────>│                          │                      │
   │ 2. HTTP vers un site     │                          │                      │
   │─────────────────────────>│                          │                      │
   │ 3. Redirection 302 vers le portail (+ challenge)    │                      │
   │<─────────────────────────│                          │                      │
   │ 4. GET page de login ────┼─────────────────────────>│                      │
   │ 5. Saisie login/mdp, POST┼─────────────────────────>│                      │
   │ 6. Redirection vers chilli /logon (réponse CHAP)    │                      │
   │<────────────────────────────────────────────────────│                      │
   │ 7. GET /logon ──────────>│                          │                      │
   │                          │ 8. Access-Request ───────┼─────────────────────>│
   │                          │ 9. Access-Accept/Reject <┼──────────────────────│
   │ 10. Session ouverte : accès Internet autorisé       │                      │
   │<─────────────────────────│                          │                      │
```

- **CoovaChilli** : moteur du portail. Gère le DHCP des clients, crée une interface `tun`,
  intercepte le trafic non authentifié, dialogue avec RADIUS, applique le NAT vers le WAN.
- **FreeRADIUS** : serveur d'authentification. Vérifie les identifiants dans MariaDB et renvoie
  les attributs de la session (quotas, bande passante — piliers 3 & 4).
- **dnsmasq** : résolution DNS pour les clients (et point d'application futur du filtrage DNS).
- **Apache + PHP** : sert la page de login du portail (protocole UAM de CoovaChilli).
- **MariaDB** : base des utilisateurs/groupes (backend SQL de FreeRADIUS) + future config.

## Plan d'adressage (par défaut, modifiable dans `provisioning/config.env`)

| Élément | Valeur |
|---|---|
| Interface WAN | `eth0` (DHCP FAI ou IP fixe) |
| Interface LAN | `eth1` |
| Réseau LAN | `192.168.182.0/24` |
| IP passerelle (LAN) | `192.168.182.1` |
| Plage DHCP clients | `192.168.182.10` → `192.168.182.254` |
| Port UAM (CoovaChilli) | `3990` |
| Serveur RADIUS | `127.0.0.1:1812/1813` |

## Le protocole UAM (Universal Access Method)

CoovaChilli redirige le client vers la page de login avec des paramètres dont un `challenge`.
La page calcule une réponse à partir du mot de passe et du `uamsecret` partagé (CHAP), puis
renvoie le client vers `http://<uamip>:<uamport>/logon`. Le mot de passe **ne circule jamais en
clair** entre le portail et CoovaChilli. C'est le mécanisme standard d'Alcasar.

## Sécurité & légal (préparé pour les phases suivantes)

- Les identifiants sont stockés hachés côté FreeRADIUS/SQL.
- La journalisation légale (pilier 4) enregistrera : horodatage, utilisateur, IP/MAC, durée,
  volumes. Rétention et export RGPD traités en Phase 5.
- Les secrets (`RADIUS_SECRET`, `UAM_SECRET`) sont générés à l'installation et **ne doivent pas
  être committés** — voir `provisioning/config.env`.

## Roadmap technique

| Phase | Contenu |
|---|---|
| 0 | Socle : repo, VM, orchestration |
| **1** | **Portail captif fonctionnel (CoovaChilli + FreeRADIUS + dnsmasq)** |
| 2 | Admin web (FastAPI + React) : utilisateurs, groupes, sessions live |
| 3 | Filtrage (e2guardian + ClamAV), listes noires/blanches, contrôle parental |
| 4 | Quotas & horaires (tc, attributs RADIUS, plages horaires) |
| 5 | Journalisation légale, rétention, export RGPD |
| 6 | Packaging, sauvegarde/restauration, doc client |
