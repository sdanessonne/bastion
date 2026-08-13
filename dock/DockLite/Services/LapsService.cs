using System;
using System.Collections.Generic;
using System.DirectoryServices;
using System.DirectoryServices.AccountManagement;
using System.IO;
using System.Linq;
using System.Runtime.Versioning;
using System.Text.Json;

namespace DockLite.Services;

/// <summary>
/// Récupération du mot de passe administrateur local LAPS d'un poste,
/// directement depuis le contexte sécurité de l'utilisateur connecté.
///
/// Avantages vs requête LDAP côté serveur :
///   - Utilise les credentials Kerberos/NTLM de l'admin connecté (SSO)
///   - Aucun bind password à stocker côté serveur
///   - Permet de déchiffrer Windows LAPS encrypted (DPAPI-NG)
///   - Fonctionne même si le serveur n'a pas accès au réseau AD
///
/// Sécurité : si l'utilisateur Windows courant n'a pas le droit AD
/// "Read attribute ms-Mcs-AdmPwd" sur l'OU cible, l'attribut sera
/// simplement absent du résultat → erreur "no_password_set".
/// </summary>
[SupportedOSPlatform("windows")]
public static class LapsService
{
    public sealed class LapsResult
    {
        public bool      Ok            { get; set; }
        public string?   Password      { get; set; }
        public string?   SourceAttr    { get; set; }
        public DateTime? ExpirationAt  { get; set; }
        public string?   Error         { get; set; }
        public string?   ErrorDetail   { get; set; }
        public string?   FetchedBy     { get; set; }
        public DateTime  FetchedAt     { get; set; } = DateTime.Now;
    }

    /// <summary>
    /// Cherche la machine dans AD et lit ses attributs LAPS.
    /// </summary>
    public static LapsResult Fetch(string machineName)
    {
        var result = new LapsResult { FetchedBy = Environment.UserName };

        if (string.IsNullOrWhiteSpace(machineName))
        {
            result.Error = "invalid_machine";
            return result;
        }

        // Sanitize : seulement caractères valides pour un nom de poste
        var clean = new string(machineName
            .Where(c => char.IsLetterOrDigit(c) || c == '-' || c == '_' || c == '.')
            .ToArray());
        if (string.IsNullOrEmpty(clean) || clean.Length > 64)
        {
            result.Error = "invalid_machine_name";
            return result;
        }

        try
        {
            // 1. Trouve le DN de la machine via AccountManagement (utilise le domaine courant)
            using var ctx = new PrincipalContext(ContextType.Domain);
            using var computer = ComputerPrincipal.FindByIdentity(ctx, IdentityType.SamAccountName, clean + "$")
                              ?? ComputerPrincipal.FindByIdentity(ctx, IdentityType.Name, clean);

            if (computer == null)
            {
                result.Error = "machine_not_found_in_ad";
                return result;
            }

            // 2. Récupère le DirectoryEntry sous-jacent pour lire les attributs LAPS
            using var entry = (DirectoryEntry)computer.GetUnderlyingObject();
            entry.RefreshCache(new[] {
                "ms-Mcs-AdmPwd", "ms-Mcs-AdmPwdExpirationTime",
                "msLAPS-Password", "msLAPS-PasswordExpirationTime",
                "msLAPS-EncryptedPassword",
            });

            // 3. Windows LAPS (msLAPS-Password) — non chiffré, JSON {n,p,t}
            var winLapsRaw = entry.Properties["msLAPS-Password"]?.Value as string;
            if (!string.IsNullOrEmpty(winLapsRaw))
            {
                string? pwd = winLapsRaw;
                try
                {
                    using var doc = JsonDocument.Parse(winLapsRaw);
                    if (doc.RootElement.TryGetProperty("p", out var p)) pwd = p.GetString();
                    else if (doc.RootElement.TryGetProperty("password", out var p2)) pwd = p2.GetString();
                }
                catch { /* pas du JSON, on garde le raw */ }

                result.Ok           = true;
                result.Password     = pwd;
                result.SourceAttr   = "msLAPS-Password";
                result.ExpirationAt = ReadFiletime(entry, "msLAPS-PasswordExpirationTime");
                return result;
            }

            // 4. Windows LAPS chiffré → essaie via Get-LapsADPassword (PowerShell)
            //    Cela fonctionne uniquement si l'admin a le module LAPS installé ET la clé DPAPI-NG.
            if (entry.Properties["msLAPS-EncryptedPassword"]?.Value != null)
            {
                var psResult = TryGetLapsViaPowerShell(clean);
                if (psResult.Ok) { psResult.SourceAttr = "msLAPS-EncryptedPassword (déchiffré)"; return psResult; }

                // Sinon erreur explicite
                result.Error = "encrypted_unsupported";
                result.ErrorDetail = psResult.ErrorDetail ?? "PowerShell Get-LapsADPassword indisponible. Module LAPS requis.";
                return result;
            }

            // 5. Legacy LAPS (ms-Mcs-AdmPwd) — en clair pour les admins autorisés
            var legacy = entry.Properties["ms-Mcs-AdmPwd"]?.Value as string;
            if (!string.IsNullOrEmpty(legacy))
            {
                result.Ok           = true;
                result.Password     = legacy;
                result.SourceAttr   = "ms-Mcs-AdmPwd";
                result.ExpirationAt = ReadFiletime(entry, "ms-Mcs-AdmPwdExpirationTime");
                return result;
            }

            result.Error = "no_password_set";
            return result;
        }
        catch (UnauthorizedAccessException)
        {
            result.Error = "access_denied";
            result.ErrorDetail = "Vous n'avez pas le droit AD 'Read attribute ms-Mcs-AdmPwd' sur cette OU.";
            return result;
        }
        catch (System.Runtime.InteropServices.COMException ex)
        {
            result.Error = "ad_com_error";
            result.ErrorDetail = ex.Message;
            return result;
        }
        catch (Exception ex)
        {
            result.Error = "fetch_error";
            result.ErrorDetail = ex.GetType().Name + ": " + ex.Message;
            try { File.AppendAllText(LogPath(), $"[{DateTime.Now:O}] LAPS fetch '{clean}' : {ex}\r\n"); } catch { }
            return result;
        }
    }

    /// <summary>
    /// Tentative de récupération via Get-LapsADPassword (PowerShell) pour les
    /// password chiffrés DPAPI-NG. Nécessite le module LAPS installé sur le poste.
    /// </summary>
    private static LapsResult TryGetLapsViaPowerShell(string computerName)
    {
        var r = new LapsResult();
        try
        {
            // Check rapide : module LAPS disponible ?
            var psi = new System.Diagnostics.ProcessStartInfo("powershell.exe",
                $"-NoProfile -Command \"Get-LapsADPassword -Identity '{computerName}' -AsPlainText | ConvertTo-Json -Compress\"")
            {
                CreateNoWindow = true, UseShellExecute = false,
                RedirectStandardOutput = true, RedirectStandardError = true,
            };
            using var p = System.Diagnostics.Process.Start(psi);
            if (p == null) { r.Error = "powershell_unavailable"; return r; }
            string stdout = p.StandardOutput.ReadToEnd();
            string stderr = p.StandardError.ReadToEnd();
            p.WaitForExit(8000);
            if (p.ExitCode != 0 || string.IsNullOrWhiteSpace(stdout))
            {
                r.Error = "ps_get_laps_failed";
                r.ErrorDetail = string.IsNullOrEmpty(stderr) ? "ExitCode=" + p.ExitCode : stderr.Trim();
                return r;
            }
            using var doc = JsonDocument.Parse(stdout);
            if (doc.RootElement.TryGetProperty("Password", out var p1)
             || doc.RootElement.TryGetProperty("PasswordPlain", out p1))
            {
                r.Ok = true;
                r.Password = p1.GetString();
            }
            if (doc.RootElement.TryGetProperty("ExpirationTimestamp", out var et))
            {
                if (DateTime.TryParse(et.GetString(), out var dt)) r.ExpirationAt = dt;
            }
            return r;
        }
        catch (Exception ex)
        {
            r.Error = "ps_exception";
            r.ErrorDetail = ex.Message;
            return r;
        }
    }

    private static DateTime? ReadFiletime(DirectoryEntry entry, string attr)
    {
        try
        {
            var v = entry.Properties[attr]?.Value;
            if (v == null) return null;

            long ft;
            if (v is long l) { ft = l; }
            else if (v is string s && long.TryParse(s, out var parsed)) { ft = parsed; }
            else if (System.Runtime.InteropServices.Marshal.IsComObject(v))
            {
                // ADSI LargeInteger COM object : HighPart * 2^32 + LowPart
                var t = v.GetType();
                var high = Convert.ToInt64(
                    t.InvokeMember("HighPart", System.Reflection.BindingFlags.GetProperty, null, v, null) ?? 0);
                var low = Convert.ToInt64(
                    t.InvokeMember("LowPart",  System.Reflection.BindingFlags.GetProperty, null, v, null) ?? 0);
                low &= 0xFFFFFFFFL;  // unsigned 32-bit
                ft = (high << 32) | low;
            }
            else return null;

            if (ft <= 0) return null;
            return DateTime.FromFileTime(ft);
        }
        catch { return null; }
    }

    private static string LogPath()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DockPolice");
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, "laps.log");
    }

    // =========================================================================
    // FALLBACK HORS-LIGNE — utilisé quand le poste est déconnecté d'AD
    //
    // Le mot de passe en clair n'est JAMAIS récupérable depuis le SAM (qui ne
    // contient que des hashes NTLM). Mais Windows LAPS peut le cacher localement
    // dans le registre, et PowerShell Get-LapsLocalManagedAccountPassword peut
    // le retourner pour les comptes locaux gérés.
    // =========================================================================

    public sealed class LapsHistoryEntry
    {
        public string?   Password   { get; set; }
        public DateTime? StoredAt   { get; set; }
        public string?   AccountName{ get; set; }
    }

    public sealed class LapsHistoryResult
    {
        public bool                    Ok           { get; set; }
        public string                  MachineName  { get; set; } = "";
        public List<LapsHistoryEntry>  Entries      { get; set; } = new();
        public string?                 Error        { get; set; }
        public string?                 ErrorDetail  { get; set; }
        public string?                 FetchedBy    { get; set; }
        public DateTime                FetchedAt    { get; set; } = DateTime.Now;
    }

    /// <summary>
    /// Récupère l'historique des mots de passe LAPS d'une machine
    /// (attribut msLAPS-EncryptedPasswordHistory). Nécessite Windows LAPS
    /// avec history activé en politique + droits AD pour lire l'attribut +
    /// droits DPAPI-NG sur la clé de chiffrement.
    ///
    /// Utilise Get-LapsADPassword -IncludeHistory en PowerShell pour le
    /// déchiffrement automatique dans le contexte de l'admin connecté.
    /// </summary>
    public static LapsHistoryResult FetchHistory(string machineName)
    {
        var r = new LapsHistoryResult { MachineName = machineName, FetchedBy = Environment.UserName };

        if (string.IsNullOrWhiteSpace(machineName)
            || !System.Text.RegularExpressions.Regex.IsMatch(machineName, @"^[A-Za-z0-9._\-]{1,64}$"))
        {
            r.Error = "invalid_machine"; return r;
        }

        try
        {
            // Get-LapsADPassword renvoie un tableau (current + history) en JSON
            var psi = new System.Diagnostics.ProcessStartInfo("powershell.exe",
                $"-NoProfile -Command \"Get-LapsADPassword -Identity '{machineName}' -IncludeHistory -AsPlainText | ConvertTo-Json -Depth 5 -Compress\"")
            {
                CreateNoWindow = true, UseShellExecute = false,
                RedirectStandardOutput = true, RedirectStandardError = true,
            };
            using var p = System.Diagnostics.Process.Start(psi);
            if (p == null) { r.Error = "powershell_unavailable"; return r; }
            string stdout = p.StandardOutput.ReadToEnd();
            string stderr = p.StandardError.ReadToEnd();
            p.WaitForExit(15000);

            if (p.ExitCode != 0 || string.IsNullOrWhiteSpace(stdout))
            {
                r.Error = "ps_history_failed";
                r.ErrorDetail = string.IsNullOrEmpty(stderr) ? "ExitCode=" + p.ExitCode : stderr.Trim();
                return r;
            }

            // Le JSON peut être un objet unique (1 seul password) ou un tableau (current + history)
            using var doc = JsonDocument.Parse(stdout);
            var root = doc.RootElement;

            void Add(JsonElement el)
            {
                var entry = new LapsHistoryEntry();
                if (el.TryGetProperty("Password", out var p1) && p1.ValueKind == JsonValueKind.String)
                    entry.Password = p1.GetString();
                else if (el.TryGetProperty("PasswordPlain", out var p2) && p2.ValueKind == JsonValueKind.String)
                    entry.Password = p2.GetString();

                if (el.TryGetProperty("Account", out var a) && a.ValueKind == JsonValueKind.String)
                    entry.AccountName = a.GetString();

                if (el.TryGetProperty("PasswordUpdateTime", out var t1) && t1.ValueKind == JsonValueKind.String
                    && DateTime.TryParse(t1.GetString(), out var dt1)) entry.StoredAt = dt1;
                else if (el.TryGetProperty("ExpirationTimestamp", out var t2) && t2.ValueKind == JsonValueKind.String
                    && DateTime.TryParse(t2.GetString(), out var dt2)) entry.StoredAt = dt2;

                if (!string.IsNullOrEmpty(entry.Password)) r.Entries.Add(entry);
            }

            if (root.ValueKind == JsonValueKind.Array)
                foreach (var el in root.EnumerateArray()) Add(el);
            else
                Add(root);

            // Tri du plus récent au plus ancien
            r.Entries = r.Entries.OrderByDescending(e => e.StoredAt ?? DateTime.MinValue).ToList();

            r.Ok = r.Entries.Count > 0;
            if (!r.Ok)
            {
                r.Error = "no_history";
                r.ErrorDetail = "Aucun historique LAPS disponible — la politique Windows LAPS doit avoir 'PasswordHistorySize' > 0.";
            }
            return r;
        }
        catch (Exception ex)
        {
            r.Error = "history_exception";
            r.ErrorDetail = ex.GetType().Name + ": " + ex.Message;
            return r;
        }
    }

    /// <summary>
    /// Tente de récupérer le mot de passe LAPS du POSTE LOCAL sans contacter AD.
    /// Se base sur 3 sources, dans l'ordre :
    ///   1. Windows LAPS cmdlet local : Get-LapsLocalManagedAccountPassword
    ///   2. Registre HKLM\...\LAPS\State (parfois renseigné selon la politique)
    ///   3. Échec → recommander la régénération via ResetLocalAdmin()
    /// </summary>
    public static LapsResult FetchLocal(string accountName = "Administrator")
    {
        var r = new LapsResult { FetchedBy = Environment.UserName };

        // 1. Windows LAPS — cmdlet local managed account
        var ps = TryGetLocalLapsViaPowerShell(accountName);
        if (ps.Ok) { ps.SourceAttr = "Get-LapsLocalManagedAccountPassword"; return ps; }

        // 2. Registre local : HKLM\Software\Microsoft\Windows\CurrentVersion\LAPS\State
        var reg = TryReadLapsStateFromRegistry();
        if (!string.IsNullOrEmpty(reg.password))
        {
            r.Ok           = true;
            r.Password     = reg.password;
            r.SourceAttr   = "registry:LAPS/State";
            r.ExpirationAt = reg.expiration;
            return r;
        }

        // 3. Échec : pas de cache local
        r.Error = "no_offline_source";
        r.ErrorDetail =
            "Le mot de passe en clair n'est stocké qu'en AD ; le SAM local ne contient que des hashes. " +
            "Solutions : (a) reconnecter le poste à AD ; (b) régénérer le mot de passe via 'Reset local'.";
        return r;
    }

    /// <summary>
    /// RÉINITIALISE le mot de passe d'un compte local en générant une valeur
    /// aléatoire et en appelant `net user`. Renvoie le nouveau password.
    /// L'opération nécessite que l'utilisateur courant soit admin du poste cible.
    /// </summary>
    public static LapsResult ResetLocalAdmin(string accountName = "Administrator", int length = 20)
    {
        var r = new LapsResult { FetchedBy = Environment.UserName };

        if (string.IsNullOrWhiteSpace(accountName) || !System.Text.RegularExpressions.Regex.IsMatch(accountName, @"^[A-Za-z0-9._\-]{1,32}$"))
        {
            r.Error = "invalid_account_name"; return r;
        }
        length = Math.Clamp(length, 14, 64);
        string newPwd = GenerateRandomPassword(length);

        try
        {
            // net user <name> <pwd>
            var psi = new System.Diagnostics.ProcessStartInfo("net.exe", $"user {accountName} \"{newPwd}\"")
            {
                CreateNoWindow = true, UseShellExecute = false,
                RedirectStandardOutput = true, RedirectStandardError = true,
            };
            using var p = System.Diagnostics.Process.Start(psi);
            if (p == null) { r.Error = "net_command_failed"; return r; }

            string stdout = p.StandardOutput.ReadToEnd();
            string stderr = p.StandardError.ReadToEnd();
            p.WaitForExit(8000);

            if (p.ExitCode != 0)
            {
                r.Error = "reset_failed";
                r.ErrorDetail = string.IsNullOrEmpty(stderr) ? stdout.Trim() : stderr.Trim();
                try { File.AppendAllText(LogPath(),
                    $"[{DateTime.Now:O}] Reset {accountName} failed: rc={p.ExitCode}, err={stderr}\r\n"); } catch { }
                return r;
            }

            r.Ok           = true;
            r.Password     = newPwd;
            r.SourceAttr   = "local-reset:net.exe";
            r.ExpirationAt = null;
            try { File.AppendAllText(LogPath(),
                $"[{DateTime.Now:O}] Reset {accountName} OK by {Environment.UserName}\r\n"); } catch { }
            return r;
        }
        catch (Exception ex)
        {
            r.Error = "reset_exception";
            r.ErrorDetail = ex.GetType().Name + ": " + ex.Message;
            return r;
        }
    }

    private static LapsResult TryGetLocalLapsViaPowerShell(string account)
    {
        var r = new LapsResult();
        try
        {
            var psi = new System.Diagnostics.ProcessStartInfo("powershell.exe",
                $"-NoProfile -Command \"Get-LapsLocalManagedAccountPassword -AccountName '{account}' -AsPlainText | ConvertTo-Json -Compress\"")
            {
                CreateNoWindow = true, UseShellExecute = false,
                RedirectStandardOutput = true, RedirectStandardError = true,
            };
            using var p = System.Diagnostics.Process.Start(psi);
            if (p == null) { r.Error = "powershell_unavailable"; return r; }
            string stdout = p.StandardOutput.ReadToEnd();
            p.WaitForExit(6000);
            if (p.ExitCode != 0 || string.IsNullOrWhiteSpace(stdout))
            {
                r.Error = "ps_local_laps_unavailable";
                return r;
            }
            using var doc = JsonDocument.Parse(stdout);
            if (doc.RootElement.TryGetProperty("Password", out var pwd)
             || doc.RootElement.TryGetProperty("PasswordPlain", out pwd))
            {
                r.Ok = true;
                r.Password = pwd.GetString();
            }
            if (doc.RootElement.TryGetProperty("ExpirationTimestamp", out var et)
                && DateTime.TryParse(et.GetString(), out var dt))
            {
                r.ExpirationAt = dt;
            }
            return r;
        }
        catch (Exception ex)
        {
            r.Error = "ps_local_exception";
            r.ErrorDetail = ex.Message;
            return r;
        }
    }

    private static (string? password, DateTime? expiration) TryReadLapsStateFromRegistry()
    {
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine
                .OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\LAPS\State");
            if (key == null) return (null, null);

            // Selon la politique : "Password" ou "PasswordPlain" (rare)
            var pwd = key.GetValue("Password") as string
                   ?? key.GetValue("PasswordPlain") as string;

            DateTime? exp = null;
            var expRaw = key.GetValue("PasswordExpirationTime");
            if (expRaw is long ft && ft > 0) exp = DateTime.FromFileTime(ft);
            else if (expRaw is string expStr && DateTime.TryParse(expStr, out var dt)) exp = dt;

            return (pwd, exp);
        }
        catch { return (null, null); }
    }

    /// <summary>
    /// Génère un mot de passe respectant les politiques de complexité Windows AD :
    /// au moins 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial.
    /// </summary>
    private static string GenerateRandomPassword(int length)
    {
        const string upper = "ABCDEFGHJKLMNPQRSTUVWXYZ";    // sans I O pour lisibilité
        const string lower = "abcdefghjkmnpqrstuvwxyz";    // sans i l o
        const string digit = "23456789";                    // sans 0 1 (confondus)
        const string punct = "!@#$%&*+-=?";

        var rng = System.Security.Cryptography.RandomNumberGenerator.Create();
        var bytes = new byte[length];
        rng.GetBytes(bytes);

        // Garantit au moins un de chaque catégorie
        var pwd = new char[length];
        pwd[0] = upper[bytes[0] % upper.Length];
        pwd[1] = lower[bytes[1] % lower.Length];
        pwd[2] = digit[bytes[2] % digit.Length];
        pwd[3] = punct[bytes[3] % punct.Length];

        var all = upper + lower + digit + punct;
        for (int i = 4; i < length; i++) pwd[i] = all[bytes[i] % all.Length];

        // Mélange (Fisher-Yates avec RNG crypto)
        var mix = new byte[length];
        rng.GetBytes(mix);
        for (int i = length - 1; i > 0; i--)
        {
            int j = mix[i] % (i + 1);
            (pwd[i], pwd[j]) = (pwd[j], pwd[i]);
        }
        return new string(pwd);
    }
}
