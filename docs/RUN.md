# Mise en route — Phase 1 (portail captif)

Objectif : monter une passerelle Bastion fonctionnelle et vérifier qu'un client du LAN
est **bloqué** tant qu'il n'est pas authentifié, puis **autorisé** après connexion.

> Rappel : Bastion est un logiciel réseau **Linux**. Il ne s'exécute pas sous Windows.
> On développe sous Windows, on déploie/teste dans une VM Debian.

## 1. Créer la VM (2 cartes réseau)

Avec VirtualBox, VMware, Hyper-V ou Proxmox — installez **Debian 12 (netinst)** puis ajoutez
**deux interfaces réseau** :

| Interface | Rôle | Mode conseillé (labo) |
|---|---|---|
| 1re carte (`eth0`) | **WAN** (Internet) | NAT / Bridge — obtient Internet |
| 2e carte (`eth1`) | **LAN** (clients) | Réseau interne / hôte-uniquement |

Côté client de test (2e VM, ou un PC/portable), branchez-le sur le **même réseau interne** que
`eth1`. Ne lui configurez **pas** d'IP fixe : il doit recevoir son IP du DHCP de Bastion.

> Vérifiez les noms réels des interfaces dans la VM :
> ```bash
> ip -br link
> ```
> Si ce ne sont pas `eth0`/`eth1` (ex. `ens33`, `enp0s8`), ajustez `WAN_IF`/`LAN_IF` dans
> `provisioning/config.env`.

## 2. Récupérer le projet dans la VM

Copiez le dossier `Bastion/` dans la VM (git, scp, dossier partagé…), par ex. dans
`/opt/Bastion`.

## 3. Adapter la configuration

```bash
cd /opt/Bastion
nano provisioning/config.env      # vérifier WAN_IF, LAN_IF, plan d'adressage
```
Laissez les secrets vides : ils sont générés automatiquement.

## 4. Installer

```bash
sudo bash provisioning/install.sh
```
Le script installe et configure CoovaChilli, FreeRADIUS, dnsmasq, MariaDB et Apache, active le
routage/NAT, et crée l'utilisateur de test `demo` (mot de passe défini dans provisioning/config.env).

## 5. Vérifier l'installation

```bash
sudo bash scripts/verify.sh
```
Tous les points doivent être au vert (services actifs, `tun0` présent, page de login en 200,
`radtest` → `Access-Accept`).

## 6. Test de bout en bout (le vrai test du portail captif)

Sur la **machine cliente** reliée au LAN :

1. Vérifiez qu'elle a bien reçu une IP dans `192.168.182.10-254` (DHCP de chilli).
2. Ouvrez un navigateur et allez sur un site en **http** (ex. `http://neverssl.com`).
3. → Vous devez être **redirigé automatiquement vers la page de login Bastion**.
4. Connectez-vous avec `demo` (mot de passe défini dans provisioning/config.env).
5. → La page confirme la connexion, et l'accès Internet s'ouvre. Rechargez un site pour vérifier.

Avant authentification, toute navigation (hors page de login) doit être bloquée/redirigée.
C'est le comportement attendu du portail captif. ✅

## Dépannage rapide

| Symptôme | Piste |
|---|---|
| Pas d'IP sur le client | `eth1`/`LAN_IF` incorrect, ou le client n'est pas sur le bon réseau interne |
| Pas de redirection | `systemctl status chilli` ; vérifier `ip link show tun0` |
| Login échoue toujours | `radtest demo <TEST_PASS> 127.0.0.1 0 <RADIUS_SECRET>` ; logs `/var/log/freeradius/` |
| Page de login inaccessible | `systemctl status apache2` ; la résolution de `PORTAL_FQDN` (dnsmasq) |
| Voir les sessions actives | `chilli_query list` |

Logs utiles : `journalctl -u chilli -f`, `journalctl -u freeradius -f`,
`/var/log/apache2/proxyfibre-*.log`.

## Et ensuite ?

Phase 1 posée, la suite (voir `docs/architecture.md`) :
- **Phase 2** — interface d'administration web (FastAPI + React) : gestion utilisateurs/groupes,
  sessions en direct, remplacement de l'utilisateur de test par de vrais comptes.
