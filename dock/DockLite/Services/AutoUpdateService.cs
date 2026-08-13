using System;
using System.IO;
using System.Net.Http;
using System.Threading.Tasks;

namespace DockLite.Services;

/// <summary>
/// Auto-update transparent : vérifie la version dispo, télécharge en arrière-plan
/// dans %LocalAppData%\DockPolice\update\, et planifie un swap au prochain démarrage.
/// </summary>
public static class AutoUpdateService
{
    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromMinutes(5) };

    public static string StagingDir =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                     "DockPolice", "update");

    public static string LauncherPath => Path.Combine(StagingDir, "swap-on-next-launch.cmd");

    /// <summary>
    /// Vérifie + télécharge la nouvelle version en silencieux.
    /// Retourne le numéro de version téléchargée (ou null si rien à faire).
    /// </summary>
    public static async Task<string?> CheckAndDownloadAsync()
    {
        try
        {
            var info = await UpdateCheckerService.CheckNowAsync();
            if (info == null || string.IsNullOrEmpty(info.DownloadUrl)) return null;
            if (!info.UpdateAvailable) return null;

            // Téléchargement
            Directory.CreateDirectory(StagingDir);
            var localExe = Path.Combine(StagingDir, "DockPolice.new.exe");
            using var resp = await _http.GetAsync(info.DownloadUrl);
            if (!resp.IsSuccessStatusCode) return null;
            await using (var fs = new FileStream(localExe, FileMode.Create, FileAccess.Write))
                await resp.Content.CopyToAsync(fs);

            // Vérifie la signature SHA-256 si fournie
            if (!string.IsNullOrEmpty(info.Sha256))
            {
                using var sha = System.Security.Cryptography.SHA256.Create();
                await using var stream = File.OpenRead(localExe);
                var hash = Convert.ToHexString(sha.ComputeHash(stream)).ToLowerInvariant();
                if (!string.Equals(hash, info.Sha256, StringComparison.OrdinalIgnoreCase))
                {
                    File.Delete(localExe);
                    return null;
                }
            }

            // Génère le script qui swappera au prochain lancement
            var currentExe = Environment.ProcessPath ?? "";
            if (string.IsNullOrEmpty(currentExe)) return info.LatestVersion;

            var script = $@"@echo off
rem Auto-update DockPolice — script de swap au démarrage
timeout /t 2 /nobreak >nul
move /Y ""{currentExe}"" ""{currentExe}.old"" >nul 2>&1
move /Y ""{localExe}"" ""{currentExe}"" >nul 2>&1
del ""{currentExe}.old"" >nul 2>&1
start """" ""{currentExe}""
del ""%~f0"" >nul 2>&1
";
            await File.WriteAllTextAsync(LauncherPath, script);

            // Marqueur "mise à jour pending" pour affichage UI
            await File.WriteAllTextAsync(Path.Combine(StagingDir, "pending.txt"), info.LatestVersion ?? "");
            return info.LatestVersion;
        }
        catch { return null; }
    }

    /// <summary>
    /// Au démarrage : si une mise à jour est pending, exécute le swap puis quitte.
    /// </summary>
    public static bool TryApplyPendingUpdate()
    {
        try
        {
            if (!File.Exists(LauncherPath)) return false;
            // Lance le script et quitte le process actuel pour libérer le binaire
            var psi = new System.Diagnostics.ProcessStartInfo
            {
                FileName = "cmd.exe",
                Arguments = "/c \"" + LauncherPath + "\"",
                UseShellExecute = true,
                CreateNoWindow = true,
                WindowStyle = System.Diagnostics.ProcessWindowStyle.Hidden,
            };
            System.Diagnostics.Process.Start(psi);
            return true;
        }
        catch { return false; }
    }

    public static bool HasPendingUpdate() => File.Exists(Path.Combine(StagingDir, "pending.txt"));

    public static string? PendingVersion()
    {
        try
        {
            var f = Path.Combine(StagingDir, "pending.txt");
            return File.Exists(f) ? File.ReadAllText(f).Trim() : null;
        }
        catch { return null; }
    }

}
