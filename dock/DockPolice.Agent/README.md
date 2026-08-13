# DockPolice Agent

Service Windows qui s'exécute en SYSTEM, indépendamment de la session utilisateur.

## Rôle

- Remonte la **télémétrie** (CPU, RAM, processus, antivirus, apps installées) toutes les 10 s vers le backoffice — **même quand aucun utilisateur n'est connecté**
- Détecte la session active via WTS API : utilisateur connecté ou non, RDP déconnectée, etc.
- Polle le backoffice pour les **commandes à distance** (cmd / PowerShell) et les exécute en SYSTEM avec timeout 60 s
- Logs dans l'**Observateur d'événements** Windows (source : `DockPolice Agent`)

## Architecture

```
                                                                     
   Service SYSTEM                         Backoffice PHP/MariaDB    
                                                                   
   DockPolice.Agent                      machine_snapshots         
                                                                   
   ┌─────────────────┐                   machine_live              
   │ TelemetryWorker │────POST──────►   agent_commands             
   │ (10s)           │                                              
   ├─────────────────┤                                              
   │ CommandWorker   │────POLL──────►                              
   │ (8s)            │      résultats                              
   └─────────────────┘                                              
                                                                     

                            ▲
                            │ partage la même API
                            ▼
   Session user                                                      
   DockPolice.exe (UI)      ─────POST tickets/PJ────►               
                                                                     
```

Quand le service est installé, le WPF (`DockPolice.exe`) **désactive sa propre télémétrie** pour éviter le doublon. Il ne s'occupe plus que de l'UI (dock, tickets, chat).

## Installation

```powershell
cd C:\pincile\DockLite\DockPolice.Agent
powershell -ExecutionPolicy Bypass -File .\install-agent.ps1
```

Le script :
1. S'auto-élève en admin (UAC)
2. Build + publish single-file self-contained dans `bin\Publish\`
3. Copie vers `C:\Program Files\DockPolice\Agent\`
4. `sc create DockPoliceAgent` avec démarrage automatique
5. Configure le **redémarrage auto** en cas de plantage (5s, 10s, 30s)
6. Démarre le service

## Désinstallation

```powershell
powershell -ExecutionPolicy Bypass -File .\uninstall-agent.ps1
```

## Configuration

`agent.json` (à côté de l'exe) :

```json
{
  "ApiBaseUrl": "http://serveur.dipn91.local",
  "ApiKey": "MEME_CLE_QUE_LE_BACKOFFICE",
  "TelemetryIntervalSeconds": 10,
  "CommandPollIntervalSeconds": 8,
  "StaticSnapshotEveryHours": 6
}
```

La clé API doit être identique à celle du `config.php` du backoffice.

## Vérification

```powershell
Get-Service DockPoliceAgent
# Status : Running

# Logs récents
Get-EventLog -LogName Application -Source "DockPolice Agent" -Newest 20
```

Dans le backoffice, ouvrir n'importe quel ticket → onglet **Information technique** → la pastille de statut doit passer à **vert** dans les 10 s.

## Statuts affichés côté backoffice

| Statut | Signification |
|--------|---------------|
| 🟢 Actif | Utilisateur connecté, session console active, idle < 5 min |
| 🟡 Inactif | Connecté mais pas d'activité depuis > 5 min |
| 🔒 Verrouillé | Session verrouillée (Win+L) |
| 📡 Allumé, aucun utilisateur | PC démarré, agent fonctionne, personne n'est loggé |
| ⚪ RDP déconnectée | Session ouverte mais bureau distant déconnecté |
| 🔴 PC éteint ou agent arrêté | Pas de heartbeat depuis > 60 s |

## Sécurité

- L'agent ne fait **que des requêtes sortantes** (HTTP POST/GET vers le backoffice). Aucun port ouvert sur le poste.
- Auth API par clé partagée (à mettre dans une config GPO en prod)
- Toutes les commandes distantes sont **tracées** dans `agent_commands` (qui, quand, quoi, sortie)
- Timeout 60 s par commande pour éviter les freeze
- L'agent tourne en `LocalSystem` — **toute commande envoyée a les privilèges admin**. À réserver à des techniciens autorisés.

## Debug local

L'exe peut être lancé en console directement (sans installer le service) :

```powershell
cd bin\Debug\net8.0-windows
.\DockPolice.Agent.exe
```

Les logs sortent en console (en plus de l'Event Log).
