using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;
using DockLite.Models;
using DockLite.Views;

namespace DockLite.Services;

/// <summary>
/// Récupère périodiquement les bandeaux du backoffice et pilote
/// la fenêtre BroadcastBannerWindow affichée en haut de l'écran.
/// </summary>
public class BroadcastService
{
    private static readonly JsonSerializerOptions JsonOpts = new() { PropertyNameCaseInsensitive = true };
    private static readonly string DismissFile = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "DockPolice", "broadcasts-dismissed.json");

    private readonly string _apiBase;
    private readonly string _apiKey;
    private readonly string _cpnCode;
    private readonly DispatcherTimer _timer;
    private readonly HashSet<int> _dismissed;

    private BroadcastBannerWindow? _window;
    private List<Broadcast> _active = new();

    public BroadcastService(string apiBaseUrl, string apiKey, string cpnCode)
    {
        _apiBase = apiBaseUrl ?? "";
        _apiKey  = apiKey    ?? "";
        _cpnCode = cpnCode   ?? "";
        _dismissed = LoadDismissed();

        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(30) };
        _timer.Tick += async (_, __) => await PollAsync();
    }

    public void Start()
    {
        if (string.IsNullOrWhiteSpace(_apiBase) || string.IsNullOrWhiteSpace(_apiKey))
            return;
        _timer.Start();
        _ = PollAsync();
    }

    public void Stop()
    {
        _timer.Stop();
        _window?.Close();
        _window = null;
    }

    public void Dismiss(int id)
    {
        _dismissed.Add(id);
        SaveDismissed();
        ApplyToWindow();
    }

    // ----- HTTP -----

    private async Task PollAsync()
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            var url = _apiBase.TrimEnd('/') + "/api/broadcasts.php?cpn=" + Uri.EscapeDataString(_cpnCode);
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", _apiKey);
            using var resp = await http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return;

            var body = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("broadcasts", out var arr)) return;

            var list = arr.Deserialize<List<BroadcastPayload>>(JsonOpts) ?? new();
            _active = list.Select(p => new Broadcast
            {
                Id          = p.Id,
                Message     = p.Message,
                Level       = p.Level,
                SpeedPxSec  = p.Speed_Px_Sec > 0 ? p.Speed_Px_Sec : 80,
                Dismissible = p.Dismissible,
                Scope       = p.Scope ?? "commissariat",
            }).ToList();

            Application.Current?.Dispatcher.Invoke(ApplyToWindow);
        }
        catch { /* best-effort */ }
    }

    private void ApplyToWindow()
    {
        var visible = _active.Where(b => b.Level == "urgent" || !_dismissed.Contains(b.Id)).ToList();

        if (visible.Count == 0)
        {
            _window?.Close();
            _window = null;
            return;
        }

        if (_window == null)
        {
            _window = new BroadcastBannerWindow(this);
            _window.Closed += (_, __) => _window = null;
            _window.SetBroadcasts(visible);
            _window.Show();
        }
        else
        {
            _window.SetBroadcasts(visible);
        }
    }

    // ----- Dismiss persistant -----

    private static HashSet<int> LoadDismissed()
    {
        try
        {
            if (File.Exists(DismissFile))
            {
                var ids = JsonSerializer.Deserialize<int[]>(File.ReadAllText(DismissFile));
                return new HashSet<int>(ids ?? Array.Empty<int>());
            }
        }
        catch { }
        return new HashSet<int>();
    }

    private void SaveDismissed()
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(DismissFile)!);
            File.WriteAllText(DismissFile, JsonSerializer.Serialize(_dismissed.ToArray()));
        }
        catch { }
    }

    // Payload JSON brut (noms snake_case imposés par l'API PHP)
    private class BroadcastPayload
    {
        public int    Id           { get; set; }
        public string Message      { get; set; } = "";
        public string Level        { get; set; } = "info";
        public int    Speed_Px_Sec { get; set; } = 80;
        public bool   Dismissible  { get; set; } = true;
        public string? Scope       { get; set; }
    }
}
