# Bastion — Guide de déploiement

Guide d'installation d'une passerelle Bastion sur une machine dédiée.

## 1. Prérequis matériel

- Un **mini-PC ou serveur** (ou VM) avec **2 interfaces réseau** :
  - **WAN** : vers Internet (box fibre, routeur FAI).
  - **LAN** : vers le réseau des utilisateurs (switch / point d'accès Wi-Fi).
- **Debian 12 ou 13** (installation minimale, sans bureau graphique de préférence).
- 2 Go de RAM minimum, 20 Go de disque.
- Accès root (sudo).

## 2. Installation

```bash
# Récupérer le projet sur la passerelle (git clone, scp, clé USB…)
cd Bastion

# 1. Adapter la configuration à votre matériel :
nano provisioning/config.env
#   - WAN_IF / LAN_IF  : noms réels des interfaces (voir : ip -br link)
#   - plan d'adressage LAN si besoin (défaut : 192.168.182.0/24)
#   - ADMIN_PASS       : changez le mot de passe admin par défaut

# 2. Installation complète (paquets + configuration) :
sudo bash provisioning/setup.sh
```

Le script installe et configure automatiquement OpenNDS (portail captif), FreeRADIUS
(authentification, base MariaDB), dnsmasq (DHCP/DNS), Apache/PHP (portail + admin), le NAT
et le pare-feu, puis les quatre piliers (filtrage, quotas, journalisation).

## 3. Vérification

```bash
sudo bash scripts/verify.sh
```

Puis, depuis un appareil branché sur le **LAN** : ouvrez un site → vous êtes redirigé vers le
portail → connectez-vous avec le compte de test `demo` (mot de passe défini dans provisioning/config.env) → accès Internet ouvert.

## 4. Accès à la console d'administration

- URL : `https://<ip-management>:8443/` (HTTPS auto-signé) ou `http://<ip>:8080/`.
- Compte par défaut : `admin` / *(défini dans config.env)*.
- **La console n'est PAS accessible depuis le réseau LAN des clients** (sécurité). Accédez-y
  depuis le réseau de management, le WAN, ou via un tunnel SSH :
  ```bash
  ssh -L 8443:localhost:8443 user@passerelle
  # puis ouvrez https://localhost:8443/
  ```

Depuis l'admin : gérer les comptes, les groupes & quotas, le filtrage, consulter les journaux.

## 5. Checklist de mise en production (sécurité)

- [ ] Changer le mot de passe **admin** (config.env avant install, ou via la base ensuite).
- [ ] Changer / supprimer le compte de test `demo`.
- [ ] Vérifier que le port admin (8080/8443) est **filtré** au pare-feu côté management.
- [ ] Remplacer le certificat auto-signé par un certificat valide si exposition.
- [ ] Définir la **durée de rétention légale** des journaux (défaut 1 an — `/etc/cron.d/proxyfibre-purge`).
- [ ] Sauvegarder la base (`mysqldump radius`) et `/etc/proxyfibre/`.

## 6. Où sont les choses

| Élément | Emplacement |
|---|---|
| Config centrale | `provisioning/config.env` |
| Secrets générés | `/etc/proxyfibre/secrets.env` |
| Portail (clients) | `/var/www/html/portal/` |
| Console admin | `/var/www/admin/` |
| Base de données | MariaDB `radius` (comptes, groupes, blocage, journaux) |
| Blocage DNS | `/etc/dnsmasq.d/proxyfibre-blocklist.conf` (généré) |
| Quotas + journaux | `/usr/lib/opennds/custombinauth.sh` |
| Journaux applicatifs | `journalctl -u opennds -u freeradius -u dnsmasq` |
