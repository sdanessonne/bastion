using System;
using System.Threading;
using System.Threading.Tasks;
using DockPolice.Agent.Services;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace DockPolice.Agent.Workers;

public class TelemetryWorker : BackgroundService
{
    private readonly ILogger<TelemetryWorker> _log;
    private readonly ApiClient _api;
    private readonly AgentConfig _cfg;

    private DateTime _lastStatic = DateTime.MinValue;

    public TelemetryWorker(ILogger<TelemetryWorker> log, ApiClient api, AgentConfig cfg)
    {
        _log = log;
        _api = api;
        _cfg = cfg;
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        _log.LogInformation("TelemetryWorker démarré (intervalle {s}s)", _cfg.TelemetryIntervalSeconds);

        // Premier appel à GetCpuPercent : amorce les compteurs (renvoie 0)
        MachineLive.Capture();

        while (!ct.IsCancellationRequested)
        {
            try
            {
                var session = SessionInfo.GetActive();
                var userName = session.HasActiveSession
                    ? session.UserName
                    : "SYSTEM";

                // Snapshot statique : au démarrage et toutes les N heures
                if ((DateTime.UtcNow - _lastStatic).TotalHours >= _cfg.StaticSnapshotEveryHours)
                {
                    var info = MachineSnapshot.Collect();
                    info.UserName = userName;  // override : peut être SYSTEM si pas de session active
                    if (await _api.SendStaticAsync(info, ct))
                    {
                        _lastStatic = DateTime.UtcNow;
                        _log.LogInformation("Snapshot statique envoyé ({apps} apps)", info.InstalledApps.Count);
                    }
                }

                // Snapshot live
                var live = MachineLive.Capture();
                live.ActiveSession = session.DisplayName;
                await _api.SendLiveAsync(Environment.MachineName, userName, live, session.State, ct);
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Erreur dans TelemetryWorker");
            }

            try { await Task.Delay(TimeSpan.FromSeconds(_cfg.TelemetryIntervalSeconds), ct); }
            catch (TaskCanceledException) { break; }
        }
    }
}
