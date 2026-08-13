using System;
using System.IO;
using System.Net.Http;
using System.Reflection;
using System.Text.Json;
using System.Threading.Tasks;

namespace DockLite.Services;

public static class UpdateChecker
{
    public class UpdateInfo
    {
        public string Version { get; set; } = "";
        public string DownloadUrl { get; set; } = "";
        public string ReleaseNotes { get; set; } = "";
        public bool Mandatory { get; set; }
    }

    private static string CurrentVersion =>
        Assembly.GetExecutingAssembly().GetName().Version?.ToString(3) ?? "0.0.0";

    /// <summary>
    /// Vérifie si une mise à jour est disponible.
    /// Le fichier latest.json doit être servi par le backoffice ou un partage UNC :
    /// { "version": "1.1.0", "downloadUrl": "http://serveur/dockpolice-1.1.0.zip",
    ///   "releaseNotes": "...", "mandatory": false }
    /// </summary>
    public static async Task<UpdateInfo?> CheckAsync(string updateUrl)
    {
        if (string.IsNullOrWhiteSpace(updateUrl)) return null;

        try
        {
            string content;
            if (updateUrl.StartsWith("http", StringComparison.OrdinalIgnoreCase))
            {
                using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
                content = await http.GetStringAsync(updateUrl);
            }
            else
            {
                if (!File.Exists(updateUrl)) return null;
                content = await File.ReadAllTextAsync(updateUrl);
            }

            var info = JsonSerializer.Deserialize<UpdateInfo>(content, new JsonSerializerOptions
            {
                PropertyNameCaseInsensitive = true
            });

            if (info == null || string.IsNullOrEmpty(info.Version)) return null;

            return IsNewer(info.Version, CurrentVersion) ? info : null;
        }
        catch { return null; }
    }

    private static bool IsNewer(string remote, string local)
    {
        try
        {
            var r = new Version(NormalizeVersion(remote));
            var l = new Version(NormalizeVersion(local));
            return r > l;
        }
        catch { return false; }
    }

    private static string NormalizeVersion(string v)
    {
        var parts = v.Split('.');
        while (parts.Length < 3)
            v += ".0";
        return v;
    }

    public static string GetCurrentVersion() => CurrentVersion;
}
