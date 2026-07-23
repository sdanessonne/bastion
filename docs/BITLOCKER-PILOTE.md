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

## Étape 5 — Test de récupération (recommandé)

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
