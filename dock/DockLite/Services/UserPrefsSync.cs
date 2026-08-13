using System;
using System.IO;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Threading.Tasks;
using DockLite.Models;

namespace DockLite.Services;

/// <summary>
/// Synchronise les prefs de dock utilisateur avec le backoffice.
/// Identifiant : "DOMAIN\username" (Environment.UserDomainName + "\" + Environment.UserName).
/// Cache local dans %LOCALAPPDATA%\DockPolice\user-<sanitized>.json pour le mode hors-ligne.
/// </summary>
public static class UserPrefsSync
{
    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true,
    };

    public static string UserId => $"{Environment.UserDomainName}\\{Environment.UserName}";

    private static string CacheDir => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "DockPolice");

    private static string CacheFile => Path.Combine(CacheDir, "user-" + Sanitize(UserId) + ".json");

    private static string Sanitize(string s) => s
        .Replace('\\', '_').Replace(':', '_').Replace('/', '_')
        .Replace('<', '_').Replace('>', '_').Replace('|', '_');

    // ---- Cache local ----

    public static UserDockPrefs? LoadCache()
    {
        try
        {
            if (!File.Exists(CacheFile)) return null;
            var json = File.ReadAllText(CacheFile);
            return JsonSerializer.Deserialize<UserDockPrefs>(json, JsonOpts);
        }
        catch { return null; }
    }

    public static void SaveCache(UserDockPrefs prefs)
    {
        try
        {
            Directory.CreateDirectory(CacheDir);
            File.WriteAllText(CacheFile, JsonSerializer.Serialize(prefs, JsonOpts));
        }
        catch { }
    }

    // ---- Pull (bloquant, timeout court pour ne pas geler le démarrage) ----

    public static UserDockPrefs? FetchUser(string apiBaseUrl, string apiKey)
        => Fetch(apiBaseUrl, apiKey, "dock-config-get.php?user=" + Uri.EscapeDataString(UserId));

    public static UserDockPrefs? FetchDefault(string apiBaseUrl, string apiKey)
        => Fetch(apiBaseUrl, apiKey, "dock-config-default.php");

    private static UserDockPrefs? Fetch(string apiBaseUrl, string apiKey, string relative)
    {
        if (string.IsNullOrWhiteSpace(apiBaseUrl) || string.IsNullOrWhiteSpace(apiKey))
            return null;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
            var url = apiBaseUrl.TrimEnd('/') + "/api/" + relative;
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", apiKey);
            using var resp = http.Send(req);
            if (!resp.IsSuccessStatusCode) return null;
            var body = resp.Content.ReadAsStringAsync().Result;
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("config", out var cfgEl)) return null;
            return cfgEl.Deserialize<UserDockPrefs>(JsonOpts);
        }
        catch { return null; }
    }

    // ---- Push (fire-and-forget) ----

    public static void PushUserAsync(string apiBaseUrl, string apiKey, UserDockPrefs prefs, string machineName)
    {
        if (string.IsNullOrWhiteSpace(apiBaseUrl) || string.IsNullOrWhiteSpace(apiKey))
            return;

        _ = Task.Run(async () =>
        {
            try
            {
                using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
                var url = apiBaseUrl.TrimEnd('/') + "/api/dock-config-set.php";
                var payload = new
                {
                    user    = UserId,
                    machine = machineName,
                    config  = prefs,
                };
                using var req = new HttpRequestMessage(HttpMethod.Post, url)
                {
                    Content = JsonContent.Create(payload),
                };
                req.Headers.Add("X-API-Key", apiKey);
                using var resp = await http.SendAsync(req);
            }
            catch { /* best-effort */ }
        });
    }
}
