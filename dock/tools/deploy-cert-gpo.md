# Déploiement du certificat DockPolice par GPO

Pour que les postes Windows considèrent `DockPolice.exe` comme **signé par un éditeur de confiance** (et donc évitent les avertissements SmartScreen / "Éditeur inconnu"), il faut installer le **certificat public** (`DockPolice-CodeSign.cer`) dans deux magasins :

1. **Autorités de certification racine de confiance** (Trusted Root Certification Authorities)
2. **Éditeurs approuvés** (Trusted Publishers)

## Méthode 1 — Manuel sur un poste (test)

1. Double-clique sur `DockPolice-CodeSign.cer`
2. **Installer le certificat** → Ordinateur local → Suivant
3. **Placer tous les certificats dans le magasin suivant** → Parcourir → **Autorités de certification racines de confiance** → OK
4. Recommencer pour **Éditeurs approuvés**

## Méthode 2 — Déploiement par GPO (production)

### Côté admin AD

1. **Console GPMC** (Group Policy Management) → créer une nouvelle GPO « DockPolice — Cert »
2. Édition → **Configuration ordinateur > Stratégies > Paramètres Windows > Paramètres de sécurité**
3. **Stratégies de clé publique** :
   - **Autorités de certification racines de confiance** → clic droit → Importer → sélectionner `DockPolice-CodeSign.cer`
   - **Éditeurs approuvés** → idem
4. Lier la GPO à l'OU contenant les postes cibles (ex : `OU=Postes-Agents,OU=DIPN91,DC=police,DC=local`)

### Côté client

Au prochain `gpupdate /force` (ou redémarrage), le certificat est installé automatiquement.

## Vérification sur un poste

```powershell
# Lister les certificats Code Signing approuvés
Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Subject -like "*DockPolice*" }
Get-ChildItem Cert:\LocalMachine\TrustedPublisher | Where-Object { $_.Subject -like "*DockPolice*" }

# Vérifier la signature de l'exe
Get-AuthenticodeSignature "C:\Program Files\DockPolice\DockPolice.exe"
# Doit afficher : Status = Valid, SignerCertificate = ...DockPolice...
```

## Évolution vers un certificat de PKI interne

Si la DGSI / DGPN dispose d'une **PKI interne** (autorité de certification interne déjà déployée par GPO), demander une **émission de certificat « Code Signing »** auprès du service PKI. Aucune action GPO supplémentaire nécessaire — la chaîne de confiance interne est déjà en place sur les postes.

Lors du `sign.ps1`, utiliser le `.pfx` fourni par la PKI à la place du certificat auto-signé.

## Évolution vers un certificat commercial

Pour distribuer en dehors du réseau police (partenaires, agents en télétravail sur postes hors AD), envisager :
- **Code Signing standard** (DigiCert, Sectigo, GlobalSign) — ~250 €/an
- **EV Code Signing** — ~400 €/an, **réputation SmartScreen instantanée** (pas de période de "warm-up")

Procédure identique : le `.pfx` fourni remplace celui auto-signé. Aucune GPO requise puisque la racine du fournisseur est déjà dans tous les Windows.
