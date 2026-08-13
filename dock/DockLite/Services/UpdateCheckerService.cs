using System;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Threading;

namespace DockLite.Services;

/// <summary>
/// Vérifie périodiquement la disponibilité d'une nouvelle version DockPolice
/// auprès du backoffice (api/client-update-check.php) et, le cas échéant,
/// déclenche le déploiement de soi-même via api/client-update-trigger.php
/// (qui passe par le système agent_commands → DockPolice.Agent en SYSTEM
/// applique le swap + relaunch).
///
/// Trois modes selon la config serveur :
///   - notify_user=1, auto_apply=0 (défaut) : déclenche un toast, l'utilisateur clique
///   - auto_apply=1                          : applique sans demander
///   - mandatory=1                          : applique de force, ignore l'horaire
/// </summary>
public static class UpdateCheckerService
{
    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private static DispatcherTimer? _timer;
    private static DateTime _lastCheckUtc = DateTime.MinValue;

    /// <summary>État du dernier check (read-only depuis l'UI).</summary>
    public static UpdateInfo Latest { get; private set; } = new();

    /// <summary>Événement déclenché quand une mise à jour est détectée.</summary>
    public static event Action<UpdateInfo>? OnUpdateAvailable;

    public class UpdateInfo
    {
        public bool     UpdateAvailable    { get; set; }
        public string?  LatestVersion      { get; set; }
        public string?  CurrentVersion     { get; set; }
        public bool     Mandatory          { get; set; }
        public bool     AutoApply          { get; set; }
        public bool     NotifyUser         { get; set; } = true;
        public int      CheckIntervalMin   { get; set; } = 60;
        public string?  Notes              { get; set; }
        public string?  DownloadUrl        { get; set; }
        public string?  Sha256             { get; set; }
        public long     Size               { get; set; }
        public int      ReleaseId          { get; set; }
        public string   Target             { get; set; } = "docklite";
        public DateTime CheckedAt          { get; set; } = DateTime.Now;
        public string?  Error              { get; set; }
    }

    public static void Start()
    {
        if (!AttachmentApi.IsConfigured) return;

        // Premier check 30 s après démarrage (laisse le temps au snapshot
        // initial de partir, et évite de spammer si on relance souvent)
        _ = Task.Run(async () =>
        {
            try { await Task.Delay(30_000); await CheckAsync(); } catch { }
        });

        // Timer périodique (60 min par défaut, ajusté après le 1er check
        // selon la valeur renvoyée par le serveur)
        _timer = new DispatcherTimer { Interval = TimeSpan.FromMinutes(60) };
        _timer.Tick += async (_, _) => await CheckAsync();
        _timer.Start();
    }

    public static void Stop()
    {
        _timer?.Stop();
        _timer = null;
    }

    /// <summary>Force un check immédiat (utilisé par le bouton "Vérifier maintenant").</summary>
    public static Task<UpdateInfo> CheckNowAsync() => CheckAsync();

    private static async Task<UpdateInfo> CheckAsync()
    {
        var info = new UpdateInfo
        {
            CurrentVersion = GetCurrentVersion(),
            CheckedAt      = DateTime.Now,
        };

        if (!AttachmentApi.IsConfigured)
        {
            info.Error = "API non configurée";
            Latest = info;
            return info;
        }

        try
        {
            var url = AttachmentApi.BaseUrl!.TrimEnd('/')
                    + "/api/client-update-check.php"
                    + "?machine="  + Uri.EscapeDataString(Environment.MachineName)
                    + "&current="  + Uri.EscapeDataString(info.CurrentVersion ?? "")
                    + "&target=docklite";

            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            req.Headers.Add("X-Machine", Environment.MachineName);

            using var resp = await _http.SendAsync(req);
            if (!resp.IsSuccessStatusCode)
            {
                info.Error = "HTTP " + (int)resp.StatusCode;
                Latest = info;
                return info;
            }

            var json = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;

            info.UpdateAvailable = root.TryGetProperty("update_available", out var ua) && ua.GetBoolean();
            info.LatestVersion   = root.TryGetProperty("latest_version", out var lv) ? lv.GetString() : null;
            info.Mandatory       = root.TryGetProperty("mandatory",   out var mn) && mn.GetBoolean();
            info.AutoApply       = root.TryGetProperty("auto_apply",  out var aa) && aa.GetBoolean();
            info.NotifyUser      = !root.TryGetProperty("notify_user", out var nu) || nu.GetBoolean();
            info.CheckIntervalMin= root.TryGetProperty("check_interval_min", out var ci) ? ci.GetInt32() : 60;
            info.ReleaseId       = root.TryGetProperty("release_id", out var rid) ? rid.GetInt32() : 0;

            if (info.UpdateAvailable)
            {
                info.DownloadUrl = root.TryGetProperty("download_url", out var du) ? du.GetString() : null;
                info.Sha256      = root.TryGetProperty("sha256",       out var sh) ? sh.GetString() : null;
                info.Size        = root.TryGetProperty("size",         out var sz) ? sz.GetInt64()  : 0;
                info.Notes       = root.TryGetProperty("notes",        out var nt) ? nt.GetString() : null;
            }

            // Ajuste l'intervalle selon le serveur (max +/- factor 2 pour limiter les surprises)
            if (_timer != null && info.CheckIntervalMin >= 5 && info.CheckIntervalMin <= 1440)
            {
                _timer.Interval = TimeSpan.FromMinutes(info.CheckIntervalMin);
            }

            Latest = info;
            _lastCheckUtc = DateTime.UtcNow;

            if (info.UpdateAvailable)
            {
                // Mode imposé OU auto-apply : on déclenche tout de suite
                if (info.Mandatory || info.AutoApply)
                {
                    _ = Task.Run(() => TriggerUpdateAsync(info));
                }
                else
                {
                    // Sinon, on lève l'événement pour que l'UI affiche un toast
                    try { OnUpdateAvailable?.Invoke(info); } catch { }
                }
            }
        }
        catch (Exception ex)
        {
            info.Error = ex.Message;
            Latest = info;
        }

        return info;
    }

    /// <summary>
    /// Déclenche le déploiement de la mise à jour pour cette machine.
    /// Le serveur insère la commande PowerShell dans agent_commands ; le service
    /// DockPolice.Agent (SYSTEM) la ramassera dans &lt; 8 s et appliquera le swap.
    /// </summary>
    public static async Task<bool> TriggerUpdateAsync(UpdateInfo info)
    {
        if (!info.UpdateAvailable || info.ReleaseId <= 0) return false;
        if (!AttachmentApi.IsConfigured) return false;

        try
        {
            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/client-update-trigger.php";
            var body = new
            {
                machine     = Environment.MachineName,
                release_id  = info.ReleaseId,
                target      = info.Target,
            };
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(body),
            };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            req.Headers.Add("X-Machine", Environment.MachineName);

            using var resp = await _http.SendAsync(req);
            return resp.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    private static string GetCurrentVersion()
    {
        try
        {
            var asm = System.Reflection.Assembly.GetExecutingAssembly();
            var infoVer = (System.Reflection.AssemblyInformationalVersionAttribute?)
                System.Attribute.GetCustomAttribute(asm,
                    typeof(System.Reflection.AssemblyInformationalVersionAttribute));
            var v = infoVer?.InformationalVersion ?? asm.GetName().Version?.ToString() ?? "";
            var plus = v.IndexOf('+');
            return plus > 0 ? v.Substring(0, plus) : v;
        }
        catch { return ""; }
    }
}
