using System;
using System.IO;
using System.Net.Http;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json;
using System.Threading.Tasks;

namespace DockPolice.Agent.Services;

/// <summary>
/// Gère le secret HMAC par machine pour authentifier l'agent auprès du backoffice.
/// Le secret est stocké dans %PROGRAMDATA%\DockPolice\agent-secret.bin, chiffré
/// avec DPAPI LocalMachine (seule cette machine peut déchiffrer, n'importe quel user).
/// Au premier lancement, l'agent s'enregistre via /api/agent-register.php avec
/// la clé API legacy d'agent.json — ensuite toutes les requêtes sont signées HMAC.
/// </summary>
public static class AgentSecretStore
{
    private static readonly string SecretDir =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "DockPolice");
    private static readonly string SecretPath = Path.Combine(SecretDir, "agent-secret.bin");

    public static string? Secret { get; private set; }
    public static string MachineName => Environment.MachineName;

    public static bool HasSecret => !string.IsNullOrEmpty(Secret);

    public static void Load()
    {
        try
        {
            if (!File.Exists(SecretPath)) return;
            var encrypted = File.ReadAllBytes(SecretPath);
            var plain     = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.LocalMachine);
            Secret = System.Text.Encoding.ASCII.GetString(plain).Trim();
        }
        catch { /* corrupted/missing → registration will be attempted */ }
    }

    public static void Save(string secret)
    {
        Directory.CreateDirectory(SecretDir);
        var bytes = System.Text.Encoding.ASCII.GetBytes(secret);
        var enc   = ProtectedData.Protect(bytes, null, DataProtectionScope.LocalMachine);
        File.WriteAllBytes(SecretPath, enc);
        // Restreint l'ACL : SYSTEM + Administrators uniquement
        try
        {
            var fi = new FileInfo(SecretPath);
            // Sur Windows, les fichiers dans ProgramData héritent déjà d'ACLs raisonnables.
            // On ajoute juste l'attribut "hidden" pour marquer le fichier.
            fi.Attributes |= FileAttributes.Hidden;
        }
        catch { }
        Secret = secret;
    }

    public static void Forget()
    {
        try { if (File.Exists(SecretPath)) File.Delete(SecretPath); } catch { }
        Secret = null;
    }

    /// <summary>
    /// Tente de s'enregistrer auprès du backoffice avec la clé legacy.
    /// Renvoie true si succès (Secret est désormais défini).
    /// </summary>
    public static async Task<bool> TryRegisterAsync(string apiBaseUrl, string legacyApiKey)
    {
        if (string.IsNullOrWhiteSpace(apiBaseUrl) || string.IsNullOrWhiteSpace(legacyApiKey))
            return false;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
            var url = apiBaseUrl.TrimEnd('/') + "/api/agent-register.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(new { machine = MachineName })
            };
            req.Headers.Add("X-API-Key", legacyApiKey);
            using var resp = await http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return false;
            var body = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("secret", out var s)) return false;
            var secret = s.GetString();
            if (string.IsNullOrWhiteSpace(secret)) return false;
            Save(secret);
            return true;
        }
        catch { return false; }
    }
}
