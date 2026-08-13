using System;
using System.Net.Http;
using System.Net.Http.Json;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows.Threading;

namespace DockLite.Services;

public static class MachineReporter
{
    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private static DispatcherTimer? _liveTimer;
    private static DispatcherTimer? _staticTimer;
    private static DispatcherTimer? _threatsTimer;
    private static DispatcherTimer? _eventLogTimer;
    private static DateTime _lastStaticSentAt = DateTime.MinValue;
    private static DateTime _lastEventLogSentAt = DateTime.MinValue;

    public static void Start()
    {
        if (!AttachmentApi.IsConfigured) return;

        // 1. Snapshot statique : envoyé au démarrage puis toutes les 6 heures
        // (couvre les changements matériel + permet la prise en compte d'une MAJ d'agent)
        _ = SendStaticAsync();
        _staticTimer = new DispatcherTimer { Interval = TimeSpan.FromHours(6) };
        _staticTimer.Tick += async (_, _) => await SendStaticAsync();
        _staticTimer.Start();

        // 2. Live : toutes les 10 secondes
        MachineLive.Capture();
        _liveTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(10) };
        _liveTimer.Tick += async (_, _) => await SendLiveAsync();
        _liveTimer.Start();

        // 3. Détections Trellix : envoyées au démarrage (delta 30j) puis toutes les heures
        // (le serveur déduplique via SHA-1 → on peut renvoyer sans crainte)
        if (OperatingSystem.IsWindows())
        {
            _ = Task.Run(async () => { await Task.Delay(8_000); await SendThreatsAsync(); });
            _threatsTimer = new DispatcherTimer { Interval = TimeSpan.FromHours(1) };
            _threatsTimer.Tick += async (_, _) => await SendThreatsAsync();
            _threatsTimer.Start();
        }

        // 4. Journal d'événements Windows : push initial (delta 7j) puis toutes les 30 min
        // → permet à machine.php d'afficher l'activité même si la machine est offline
        if (OperatingSystem.IsWindows())
        {
            _ = Task.Run(async () => { await Task.Delay(15_000); await SendEventLogAsync(); });
            _eventLogTimer = new DispatcherTimer { Interval = TimeSpan.FromMinutes(30) };
            _eventLogTimer.Tick += async (_, _) => await SendEventLogAsync();
            _eventLogTimer.Start();
        }
    }

    /// <summary>
    /// Réactualisation forcée déclenchée à distance (commande "#DOCK_REFRESH").
    /// Repousse immédiatement le live + le snapshot statique, sans attendre le
    /// cycle de 6 h. Utilisé par le bouton « Réactualiser » de la fiche machine.
    /// </summary>
    public static async Task ForceRefreshAsync()
    {
        if (!AttachmentApi.IsConfigured) return;
        try { MachineLive.Capture(); await SendLiveAsync(); } catch { }
        try { await SendStaticAsync(); } catch { }
        if (OperatingSystem.IsWindows())
        {
            try { await SendThreatsAsync(); } catch { }
        }
    }

    [SupportedOSPlatform("windows")]
    private static async Task SendEventLogAsync()
    {
        try
        {
            // Récupère le résumé des 7 derniers jours
            var summary = EventLogService.GetSummary(7, 200);
            // Concatène tous les événements pour le push
            var allEvents = new List<EventLogService.LogEvent>();
            allEvents.AddRange(summary.RecentLogons);
            allEvents.AddRange(summary.RecentSystem);
            allEvents.AddRange(summary.RecentCrashes);
            allEvents.AddRange(summary.RecentLockouts);

            if (allEvents.Count == 0 && _lastEventLogSentAt != DateTime.MinValue)
            {
                // Rien de nouveau et déjà envoyé une fois → on saute ce tick
                return;
            }

            var payload = new
            {
                machineName = Environment.MachineName,
                userName    = Environment.UserName,
                summary = new {
                    lastBoot         = summary.LastBoot?.ToString("yyyy-MM-dd HH:mm:ss"),
                    lastShutdown     = summary.LastShutdown?.ToString("yyyy-MM-dd HH:mm:ss"),
                    lastLogon        = summary.LastLogon?.ToString("yyyy-MM-dd HH:mm:ss"),
                    lastLogonUser    = summary.LastLogonUser,
                    logonsToday      = summary.LogonsToday,
                    logonsWeek       = summary.LogonsWeek,
                    failedLogonsWeek = summary.FailedLogonsWeek,
                    lockoutsMonth    = summary.LockoutsMonth,
                    crashesWeek      = summary.CrashesWeek,
                    rebootsMonth     = summary.RebootsMonth,
                },
                events = allEvents.Select(e => new {
                    time        = e.Time.ToString("yyyy-MM-dd HH:mm:ss"),
                    source      = e.Source,
                    eventId     = e.EventId,
                    provider    = e.Provider,
                    level       = e.Level,
                    category    = e.Category,
                    userName    = e.UserName,
                    ipAddress   = e.IpAddress,
                    logonType   = e.LogonType,
                    processName = e.ProcessName,
                    summary     = e.Summary,
                    rawMessage  = e.RawMessage,
                    dedupHash   = ComputeSha1($"{Environment.MachineName}|{e.Time:o}|{e.EventId}|{e.UserName}|{e.IpAddress}"),
                }).ToArray(),
            };

            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/machine-eventlog.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url) { Content = JsonContent.Create(payload) };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
            if (resp.IsSuccessStatusCode) _lastEventLogSentAt = DateTime.UtcNow;
        }
        catch { /* silent best-effort */ }
    }

    private static string ComputeSha1(string s)
    {
        using var sha = System.Security.Cryptography.SHA1.Create();
        var bytes = sha.ComputeHash(System.Text.Encoding.UTF8.GetBytes(s ?? ""));
        return Convert.ToHexString(bytes).ToLowerInvariant();
    }

    [SupportedOSPlatform("windows")]
    private static async Task SendThreatsAsync()
    {
        try
        {
            var threats = TrellixInfo.CollectRecentThreats(30);
            if (threats.Count == 0) return;

            var payload = new
            {
                machineName = Environment.MachineName,
                userName    = Environment.UserName,
                threats     = threats.Select(t => new
                {
                    detectedAt      = t.DetectedAt.ToString("yyyy-MM-dd HH:mm:ss"),
                    threatName      = t.ThreatName,
                    threatType      = t.ThreatType,
                    severity        = t.Severity,
                    filePath        = t.FilePath,
                    fileHash        = t.FileHash,
                    processName     = t.ProcessName,
                    userContext     = t.UserContext,
                    actionTaken     = t.ActionTaken,
                    detectionSource = t.DetectionSource,
                    ruleName        = t.RuleName,
                    eventId         = t.EventId,
                    rawMessage      = t.RawMessage,
                    dedupHash       = t.DedupHash,
                }).ToArray(),
            };

            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/trellix-threats.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(payload)
            };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
            // pas de retry : le batch sera renvoyé dans une heure (serveur déduplique)
        }
        catch { /* silent */ }
    }

    private static async Task SendStaticAsync()
    {
        try
        {
            var info = MachineSnapshot.Collect();
            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/machine-snapshot.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(info)
            };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
            if (resp.IsSuccessStatusCode) _lastStaticSentAt = DateTime.UtcNow;
        }
        catch { /* silent */ }
    }

    private static async Task SendLiveAsync()
    {
        try
        {
            // Si le statique n'est jamais passé, on retente
            if (_lastStaticSentAt == DateTime.MinValue) await SendStaticAsync();

            var snap = MachineLive.Capture();
            var payload = new
            {
                machine_name = Environment.MachineName,
                user_name = Environment.UserName,
                cpu_percent = snap.CpuPercent,
                ram_used_mb = snap.RamUsedMb,
                ram_total_mb = snap.RamTotalMb,
                idle_seconds = snap.IdleSeconds,
                is_locked = snap.IsLocked ? 1 : 0,
                active_session = snap.ActiveSession,
                processes_json = JsonSerializer.Serialize(snap.Processes)
            };

            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/machine-live.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(payload)
            };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
        }
        catch { /* silent */ }
    }
}
