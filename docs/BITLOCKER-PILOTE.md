# Chiffrement BitLocker — procédure de test pilote

But : valider, **sur un seul poste**, que le disque se chiffre et que la clé de récupération
remonte bien dans l'Active Directory (visible depuis la console Bastion) — avant tout
déploiement sur le parc.

> ⚠️ Ne jamais lancer directement sur tous les postes. On valide d'abord sur un pilote.

---

## Prérequis

- Un poste **Windows 10/11 Professionnel ou Entreprise** (pas *Familiale* : elle n'a pas
  BitLocker), **joint au domaine** `bastion.pn.int`.
- Un **TPM 2.0** présent et prêt sur ce poste. Sur une VM VirtualBox, il faut l'activer (étape 1).
- La passerelle Bastion à jour (le module BitLocker est déjà déployé).

---

## Étape 1 — Activer le TPM 2.0 sur la VM pilote (VirtualBox)

VM **éteinte**, sur l'hôte :

```bash
VBoxManage modifyvm "proxyFibre-client" --tpm-type 2.0
```

(ou dans l'interface : VM → *Configuration* → *Système* → cocher **Activer le TPM**, version **2.0**.)

Vérifier après démarrage de Windows : `Win+R` → `tpm.msc` → « Le TPM est prêt à être utilisé ».
En PowerShell (admin) : `Get-Tpm` doit afficher `TpmPresent : True` et `TpmReady : True`.

---

## Étape 2 — Déployer la GPO depuis la console Bastion

1. Console → **Active Directory** → onglet **Stratégies (GPO)**.
2. Carte 🔐 **« Chiffrement BitLocker des disques »** → **Déployer BitLocker** → confirmer.
3. La carte doit passer à **✓ Déployé**.

---

## Étape 3 — Appliquer sur le poste pilote

Sur le poste, en **invite de commandes administrateur** :

```bat
gpupdate /force
```

Puis **redémarrer le poste** (le script de chiffrement s'exécute au démarrage, en tant que
SYSTEM). Laisser le poste allumé quelques minutes.

---

## Étape 4 — Vérifier

**Sur le poste** (invite admin) :

```bat
manage-bde -status C:
```

Attendu : `État de la conversion : Chiffrement en cours` (puis `Chiffré intégralement`), et
dans les **protecteurs de clé** : `TPM` **et** `Mot de passe numérique` (= clé de récupération).

**Dans la console Bastion** :

- **Active Directory** → onglet **Postes** → cliquer le poste pilote.
- La ligne 🔐 **« Clé(s) de récupération BitLocker »** doit afficher la clé à 48 chiffres.

> La clé est écrite dans l'AD **avant** que le chiffrement ne démarre : elle apparaît donc
> très vite, avant même la fin du chiffrement.

---

## Étape 5 — Vérifier le démarrage HORS RÉSEAU (le point à valider)

C'est le contrôle qui prouve qu'un poste **coupé du réseau démarre quand même** : le
déverrouillage se fait par le **TPM local**, sans domaine ni passerelle.

1. Attendre que `manage-bde -status C:` affiche **`Chiffré intégralement`** (100 %) et
   **`Protection : Activée`**.
2. **Couper le réseau du poste** : débrancher le câble — ou, sur la VM VirtualBox,
   *Configuration → Réseau → décocher « Câble branché »* (ou, hôte éteint côté lien :
   `VBoxManage controlvm "proxyFibre-client" setlinkstate1 off`).
3. **Redémarrer** le poste.

**Attendu :** Windows démarre **directement** sur l'écran d'ouverture de session, **sans
demander ni clé ni code** — exactement comme avec le réseau. Le déverrouillage BitLocker
ne dépend pas du réseau.

> Un agent qui s'est **déjà connecté** sur ce poste peut aussi ouvrir sa session hors
> ligne (identifiants en cache). Rebrancher le réseau ensuite.
>
> ⚠️ Si BitLocker **réclame la clé de récupération** à cet instant, ce n'est **pas** dû au
> réseau : c'est un changement d'intégrité de l'amorçage (BIOS/UEFI, Secure Boot, ordre de
> démarrage) ou, sur VM, un état de TPM qui a changé. Saisir la clé (console → **Active
> Directory → Postes**) et noter le déclencheur.

---

## Étape 6 — Test de récupération (recommandé)

1. Noter la clé affichée dans la console.
2. Sur le poste : `manage-bde -forcerecovery C:` puis redémarrer → BitLocker demande la clé.
3. Saisir la clé de récupération notée → le poste démarre. Preuve que le séquestre fonctionne.

---

## Dépannage

| Symptôme | Cause probable / action |
|---|---|
| Rien ne se chiffre | TPM absent/non prêt (`Get-Tpm`), ou édition **Familiale** (pas de BitLocker). |
| GPO non appliquée | `gpresult /h C:\gp.html` puis ouvrir le rapport ; vérifier que « Bastion — Chiffrement BitLocker » y figure. Horloge du poste synchronisée (sinon Kerberos échoue). |
| Le script ne s'est pas lancé | Observateur d'événements → *Journaux Windows → Système* (source *Group Policy Scripts*). Le marqueur `HKLM\Software\Bastion\BitLockerDone` indique qu'il a déjà tourné. |
| Disque chiffré **mais** clé absente de la console | Problème de **permission AD** : le compte machine doit pouvoir créer son objet de récupération. Me le signaler — on ajuste l'ACL du domaine. |
| Refaire le test proprement | `manage-bde -off C:` (déchiffre), supprimer `HKLM\Software\Bastion\BitLockerDone`, `gpupdate /force`, redémarrer. |

---

## Après un pilote réussi

Le chiffrement s'appliquera de la même façon à **chaque poste** du domaine (avec TPM prêt)
à son prochain démarrage. Les postes sans TPM et les éditions Familiale sont ignorés. Chaque
clé de récupération apparaît sous son poste dans la console.

Pour **retirer** la stratégie : onglet Stratégies → « Chiffrement BitLocker » → **Désactiver**
(les postes déjà chiffrés le restent ; leurs clés restent dans l'AD).

---

## Modes de déverrouillage (TPM seul / TPM + PIN)

La carte « Chiffrement BitLocker » (onglet Stratégies) propose **trois modes** :

| Mode | Démarrage | Pour qui |
|---|---|---|
| **TPM seul** | transparent, aucun code | postes **fixes** (défaut) |
| **TPM + PIN commun** | un **même code** pour tout le parc, demandé à chaque démarrage | parc homogène, frein anti-vol simple |
| **TPM + PIN par poste** | un code **unique par poste**, demandé à chaque démarrage | **portables** / postes sensibles (le plus sûr) |

> Avec un PIN, le code est demandé **à chaque** démarrage — **sur site comme hors réseau**.
> C'est le durcissement « volé = verrouillé ». Le PIN commun est **lisible dans SYSVOL** par
> les comptes du domaine : à réserver au frein anti-vol opportuniste ; préférez le PIN par
> poste pour les machines réellement sensibles.

### Procédure « PIN par poste » (mode manuel)

Dans ce mode, la GPO **impose** la politique TPM+PIN mais **ne chiffre pas** automatiquement
(elle ne peut pas connaître le PIN de chacun). Sur **chaque** poste, une fois la GPO appliquée
(`gpupdate /force`), en **invite de commandes administrateur** :

```bat
manage-bde -on C: -TPMAndPIN
```

Windows demande alors de **saisir puis confirmer le PIN** (6 à 20 chiffres), ajoute une clé de
récupération — **automatiquement sauvegardée dans l'AD** (visible dans la console) — et lance
le chiffrement. Vérifier ensuite avec `manage-bde -status C:` (protecteurs : `TpmPin` **et**
`Mot de passe numérique`).

> Pour **changer** le PIN d'un poste plus tard : `manage-bde -changepin C:`.

