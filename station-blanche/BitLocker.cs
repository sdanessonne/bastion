using System.Diagnostics;
using System.Security.Principal;

namespace Bastion.StationBlanche;

/// <summary>
/// Chiffrement BitLocker To Go d'une clé USB de service (fonction « préparation de clé »).
///
/// CETTE CLASSE MODIFIE LE SUPPORT. Elle n'est appelée QUE depuis la fenêtre de préparation
/// de clé, derrière un avertissement explicite — jamais depuis l'analyse (la station ne
/// modifie jamais une clé remise pour constat, qui peut être un scellé).
///
/// Le TPM ne s'applique pas aux lecteurs amovibles : la clé se déverrouille par MOT DE PASSE
/// (BitLocker To Go). Une clé de RÉCUPÉRATION est aussi générée — à conserver (affichée à
/// l'agent + escrowée sur la passerelle), sinon un mot de passe oublié condamne la clé.
/// </summary>
public static class BitLockerCle
{
    public sealed record Support(string Lettre, string Nom, long Taille);
    public sealed record Resultat(bool Ok, string Message, string? CleRecuperation);

    /// <summary>La session a-t-elle les droits administrateur (exigés par Enable-BitLocker) ?</summary>
    public static bool EstAdministrateur()
    {
        try
        {
            using var id = WindowsIdentity.GetCurrent();
            return new WindowsPrincipal(id).IsInRole(WindowsBuiltInRole.Administrator);
        }
        catch { return false; }
    }

    /// <summary>Lecteurs AMOVIBLES prêts (clé/disque USB) — jamais le disque système.</summary>
    public static IReadOnlyList<Support> LecteursAmovibles()
    {
        var systeme = Path.GetPathRoot(Environment.SystemDirectory)?.TrimEnd('\\');  // "C:"
        var liste = new List<Support>();
        foreach (var d in DriveInfo.GetDrives())
        {
            try
            {
                if (d.DriveType != DriveType.Removable || !d.IsReady) continue;
                var lettre = d.Name.TrimEnd('\\');                                    // "E:"
                if (string.Equals(lettre, systeme, StringComparison.OrdinalIgnoreCase)) continue;
                liste.Add(new Support(lettre,
                    string.IsNullOrWhiteSpace(d.VolumeLabel) ? "sans nom" : d.VolumeLabel, d.TotalSize));
            }
            catch { /* lecteur retiré entre-temps : on l'ignore */ }
        }
        return liste;
    }

    /// <summary>
    /// Chiffre le lecteur amovible <paramref name="lettre"/> (ex. « E: ») en BitLocker To Go,
    /// protégé par <paramref name="motDePasse"/>, et génère une clé de récupération.
    ///
    /// Le mot de passe part par VARIABLE D'ENVIRONNEMENT (jamais sur la ligne de commande, qui
    /// serait lisible dans la liste des processus). Opération bloquante — à lancer hors du fil UI.
    /// </summary>
    public static Resultat Chiffrer(string lettre, string motDePasse)
    {
        lettre = (lettre ?? "").TrimEnd('\\').Trim();
        if (lettre.Length != 2 || lettre[1] != ':') return new Resultat(false, "Lecteur invalide.", null);
        if (!EstAdministrateur())
            return new Resultat(false,
                "Droits administrateur requis. Lancez la station « en tant qu'administrateur » pour préparer une clé.", null);

        // Chiffre, ajoute une clé de récupération, puis l'affiche (préfixe RECOVERY= / ERREUR=).
        var ps =
            "$ErrorActionPreference='Stop';" +
            "try{" +
            $"$m='{lettre}';" +
            "$sec=ConvertTo-SecureString $env:BLPWD -AsPlainText -Force;" +
            "Enable-BitLocker -MountPoint $m -EncryptionMethod XtsAes256 -UsedSpaceOnly -PasswordProtector -Password $sec -SkipHardwareTest | Out-Null;" +
            "Add-BitLockerKeyProtector -MountPoint $m -RecoveryPasswordProtector | Out-Null;" +
            "$rp=(Get-BitLockerVolume -MountPoint $m).KeyProtector | Where-Object {$_.KeyProtectorType -eq 'RecoveryPassword'} | Select-Object -First 1;" +
            "Write-Output ('RECOVERY=' + $rp.RecoveryPassword)" +
            "}catch{Write-Output ('ERREUR=' + $_.Exception.Message)}";

        var psi = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command -",   // script lu sur stdin
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        psi.EnvironmentVariables["BLPWD"] = motDePasse;

        try
        {
            using var p = Process.Start(psi) ?? throw new InvalidOperationException("powershell.exe introuvable");
            // Lire stderr en parallèle : lire les deux flux en série peut interbloquer si l'un
            // remplit son tampon pendant qu'on attend l'autre.
            var errTask = p.StandardError.ReadToEndAsync();
            p.StandardInput.Write(ps);
            p.StandardInput.Close();
            var sortie = p.StandardOutput.ReadToEnd();
            p.WaitForExit(15 * 60 * 1000);   // le chiffrement de l'espace utilisé peut prendre du temps
            var err = errTask.GetAwaiter().GetResult();

            foreach (var ligne in sortie.Replace("\r", "").Split('\n'))
            {
                var l = ligne.Trim();
                if (l.StartsWith("RECOVERY=", StringComparison.Ordinal))
                    return new Resultat(true, "Clé chiffrée.", l.Substring("RECOVERY=".Length).Trim());
                if (l.StartsWith("ERREUR=", StringComparison.Ordinal))
                    return new Resultat(false, l.Substring("ERREUR=".Length).Trim(), null);
            }
            return new Resultat(false, "Chiffrement : réponse inattendue de Windows. " + err.Trim(), null);
        }
        catch (Exception ex) { return new Resultat(false, "Impossible de lancer le chiffrement : " + ex.Message, null); }
    }
}
