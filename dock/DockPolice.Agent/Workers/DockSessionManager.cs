using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.ServiceProcess;
using System.Threading;
using System.Threading.Tasks;
using DockPolice.Agent.Services;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Hosting.WindowsServices;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace DockPolice.Agent.Workers;

/// <summary>
/// Lifetime étendu qui s'abonne aux notifications SESSION_CHANGE de Windows
/// pour démarrer/redémarrer DockPolice.exe dans la session de l'utilisateur connecté.
/// </summary>
public class DockSessionManager : WindowsServiceLifetime
{
    private readonly ILogger<DockSessionManager> _log;
    private readonly AgentConfig _cfg;
    private readonly ConcurrentDictionary<uint, int> _runningPids = new();
    private Timer? _watchdogTimer;

    public DockSessionManager(
        IHostEnvironment env,
        Microsoft.Extensions.Hosting.IHostApplicationLifetime appLifetime,
        ILoggerFactory loggerFactory,
        IOptions<HostOptions> hostOptions,
        IOptions<WindowsServiceLifetimeOptions> serviceOptions,
        AgentConfig cfg)
        : base(env, appLifetime, loggerFactory, hostOptions, serviceOptions)
    {
        _log = loggerFactory.CreateLogger<DockSessionManager>();
        _cfg = cfg;
        CanHandleSessionChangeEvent = true;
    }

    protected override void OnStart(string[] args)
    {
        base.OnStart(args);

        // Lance le dock pour les sessions déjà actives au démarrage du service
        Task.Run(() =>
        {
            try
            {
                // Petit délai pour laisser le service finir son démarrage
                Thread.Sleep(2000);

                foreach (var sessionId in UserSessionLauncher.GetActiveSessions())
                {
                    LaunchDock(sessionId, "service-start");
                }

                // Watchdog : vérifie toutes les 30s que les docks sont vivants
                _watchdogTimer = new Timer(_ => Watchdog(), null,
                    TimeSpan.FromSeconds(30), TimeSpan.FromSeconds(30));
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Erreur initial scan sessions");
            }
        });
    }

    protected override void OnSessionChange(SessionChangeDescription changeDescription)
    {
        base.OnSessionChange(changeDescription);
        _log.LogInformation("Session change : reason={reason}, sessionId={sid}",
            changeDescription.Reason, changeDescription.SessionId);

        switch (changeDescription.Reason)
        {
            case SessionChangeReason.SessionLogon:
            case SessionChangeReason.RemoteConnect:
                // Légère pause pour que le profil utilisateur soit complètement chargé
                Task.Delay(1500).ContinueWith(_ =>
                    LaunchDock((uint)changeDescription.SessionId, "session-logon"));
                break;

            case SessionChangeReason.SessionLogoff:
            case SessionChangeReason.RemoteDisconnect:
                _runningPids.TryRemove((uint)changeDescription.SessionId, out _);
                break;
        }
    }

    private void LaunchDock(uint sessionId, string trigger)
    {
        try
        {
            var exePath = ResolveDockExePath();
            if (string.IsNullOrEmpty(exePath))
            {
                _log.LogWarning("DockExePath introuvable. Configurer agent.json ou installer le dock.");
                return;
            }

            // Évite les doublons : si un dock tourne déjà dans cette session, skip
            if (_runningPids.TryGetValue(sessionId, out int existingPid))
            {
                try
                {
                    var p = Process.GetProcessById(existingPid);
                    if (!p.HasExited)
                    {
                        _log.LogInformation("Dock déjà en cours dans session {sid} (pid {pid})", sessionId, existingPid);
                        return;
                    }
                }
                catch { /* process disparu, on relance */ }
            }

            int pid = UserSessionLauncher.LaunchInSession(sessionId, exePath);
            _runningPids[sessionId] = pid;
            _log.LogInformation("Dock lancé (trigger={trigger}, session={sid}, pid={pid})", trigger, sessionId, pid);
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Échec lancement dock dans session {sid}", sessionId);
        }
    }

    private void Watchdog()
    {
        try
        {
            // 1. Pour chaque session active, vérifier qu'un dock tourne
            var activeSessions = UserSessionLauncher.GetActiveSessions();
            foreach (var sid in activeSessions)
            {
                if (!IsDockAliveInSession(sid))
                {
                    _log.LogInformation("Watchdog : dock absent en session {sid}, relance", sid);
                    LaunchDock(sid, "watchdog");
                }
            }

            // 2. Nettoie les entries de sessions plus actives
            foreach (var key in new List<uint>(_runningPids.Keys))
            {
                if (!activeSessions.Contains(key))
                    _runningPids.TryRemove(key, out _);
            }
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Erreur watchdog");
        }
    }

    private bool IsDockAliveInSession(uint sessionId)
    {
        if (!_runningPids.TryGetValue(sessionId, out int pid)) return false;
        try
        {
            var p = Process.GetProcessById(pid);
            return !p.HasExited;
        }
        catch { return false; }
    }

    private string ResolveDockExePath()
    {
        if (!string.IsNullOrEmpty(_cfg.DockExePath) && File.Exists(_cfg.DockExePath))
            return _cfg.DockExePath;

        // Fallbacks classiques
        var candidates = new[]
        {
            @"C:\Program Files\DockPolice\DockPolice.exe",
            @"C:\Program Files (x86)\DockPolice\DockPolice.exe",
        };
        foreach (var c in candidates) if (File.Exists(c)) return c;
        return "";
    }

    protected override void OnStop()
    {
        _watchdogTimer?.Dispose();
        base.OnStop();
    }
}
