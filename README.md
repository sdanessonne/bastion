# Bastion

Contrôleur d'accès réseau (NAC) / portail captif open source — inspiré d'[Alcasar](https://alcasar.net/).

Bastion s'installe sur une passerelle Linux à **deux interfaces réseau** et impose une
authentification avant tout accès à Internet, avec filtrage de contenu, quotas et
journalisation légale.

> **Approche :** on n'écrit pas un moteur de portail captif « from scratch ». On orchestre des
> briques open source éprouvées (OpenNDS, FreeRADIUS, dnsmasq, nftables…) et on y ajoute une
> couche d'administration/orchestration moderne. C'est le modèle d'Alcasar, et c'est ce qui
> garantit la fiabilité réseau et la validité légale des journaux.

## Les 4 piliers — tous fonctionnels ✅

| Pilier | Statut | Briques |
|---|---|---|
| 1. Portail captif + authentification | ✅ | OpenNDS + FreeRADIUS (MariaDB) + dnsmasq |
| 2. Filtrage de contenu | ✅ | Blocage DNS (dnsmasq) piloté depuis l'admin |
| 3. Quotas & horaires | ✅ | binauth OpenNDS (durée, débit ↓/↑, quota, plages horaires) |
| 4. Journalisation légale | ✅ | Journal connexions + volumes, export CSV RGPD, purge auto |

Plus : une **console d'administration** web (comptes, groupes, filtrage, journaux, sessions live)
et un **tableau de bord utilisateur** self-service — le tout en PHP/MariaDB sur la passerelle.

## Démarrage rapide

```bash
nano provisioning/config.env      # interfaces WAN/LAN, mot de passe admin
sudo bash provisioning/setup.sh   # installation complète en une commande
```
Détails : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) · Architecture : [docs/architecture.md](docs/architecture.md)

## Topologie réseau

```
   Internet (Fibre)
        │
     [ WAN ]  ← interface WAN (DHCP FAI ou IP fixe)
   ┌──────────────────────────────┐
   │        Bastion (NAC)       │
   │   OpenNDS · FreeRADIUS ·      │
   │   dnsmasq · MariaDB · Apache  │
   └──────────────────────────────┘
     [ LAN ]  ← interface LAN = 192.168.182.1/24
        │
   ┌────┴─────┬──────────┬─────────┐
  PC        Tablette   Smartphone  …   (clients à authentifier)
```

Tant qu'un client n'est pas authentifié, tout son trafic est intercepté par CoovaChilli
et redirigé vers la page de login (portail captif). Après authentification réussie auprès de
FreeRADIUS, le pare-feu ouvre l'accès Internet pour cet utilisateur.

## Prérequis

- Une **VM ou mini-PC sous Debian 12** avec **2 cartes réseau** (WAN + LAN).
- Voir [docs/architecture.md](docs/architecture.md) pour les détails et [docs/RUN.md](docs/RUN.md)
  pour la mise en route pas-à-pas.

> ⚠️ Le développement se fait sous Windows mais **le NAC ne peut s'exécuter que sous Linux**
> (pare-feu, interfaces réseau). Utilisez la VM décrite dans `docs/RUN.md` pour tester.

## Arborescence

```
Bastion/
├── docs/                  Documentation (architecture, mise en route)
├── provisioning/          Installation & configuration de la VM
│   ├── config.env         Variables (interfaces, réseau, secrets)
│   └── install.sh         Script d'installation idempotent
├── services/              Modèles de configuration des briques
│   ├── chilli/            CoovaChilli (moteur portail captif)
│   ├── freeradius/        Authentification + schéma SQL
│   ├── dnsmasq/           DNS/DHCP du LAN
│   └── sysctl/            Routage IP
├── portal/                Page de login servie aux clients (UAM)
└── scripts/               Utilitaires (vérification, gestion utilisateurs)
```

## Licence

**Copyright © 2026 Mickaël MONESTIER (Mle 110.480) — Tous droits réservés.**

Le code original de Bastion (couche d'administration, d'orchestration, interfaces, scripts et
documentation de ce dépôt) est mis **gratuitement à disposition du ministère de l'Intérieur** et
de ses services (notamment la Police nationale) pour leurs besoins internes. L'auteur en conserve
l'**intégralité du droit d'auteur** ; toute autre utilisation requiert son autorisation écrite.
Voir [`LICENCE.txt`](LICENCE.txt) pour les termes complets.

> Les briques tierces intégrées (OpenNDS, FreeRADIUS, dnsmasq, nftables…) restent régies par leurs
> licences respectives (GPL/BSD). La présente licence ne s'applique qu'à l'œuvre originale de l'auteur.
