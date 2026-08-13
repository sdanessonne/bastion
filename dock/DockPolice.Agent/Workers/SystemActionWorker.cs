using System;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using DockPolice.Agent.Services;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace DockPolice.Agent.Workers;

/// <summary>
/// Exécute des actions système privilégiées demandées par le backoffice :
///  - sas   : envoie Ctrl+Alt+Suppr via SendSAS (sas.dll, nécessite SYSTEM)
///  - lock  : verrouille la session console
///  - logoff: déconnecte l'utilisateur console
///  - reboot: redémarre le poste
///  - shutdown: éteint le poste
/// </summary>
[SupportedOSPlatform("windows")]
public class SystemActionWorker : BackgroundService
{
    private readonly ILogger<SystemActionWorker> _log;
    private readonly ApiClient _api;
    private readonly AgentConfig _cfg;

    [DllImport("sas.dll", EntryPoint = "SendSAS", CallingConvention = CallingConvention.Winapi)]
    private static extern void SendSAS([MarshalAs(UnmanagedType.Bool)] bool asUser);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool LockWorkStation();

    public SystemActionWorker(ILogger<SystemActionWorker> log, ApiClient api, AgentConfig cfg)
    {
        _log = log;
        _api = api;
        _cfg = cfg;
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        _log.LogInformation("SystemActionWorker démarré");

        while (!ct.IsCancellationRequested)
        {
            try
            {
                var pending = await _api.PollSystemActionsAsync(Environment.MachineName, ct);
                if (pending != null)
                {
                    foreach (var action in pending)
                    {
                        _ = Task.Run(() => HandleAction(action, ct), ct);
                    }
                }
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Erreur poll system actions");
            }

            try { await Task.Delay(TimeSpan.FromSeconds(3), ct); }
            catch (TaskCanceledException) { break; }
        }
    }

    private async Task HandleAction((int id, string action) a, CancellationToken ct)
    {
        string status = "done";
        string? error = null;

        try
        {
            _log.LogInformation("Exécution action système #{id} : {action}", a.id, a.action);

            switch (a.action)
            {
                case "sas":
                    SendSAS(false);  // false = envoi à toutes les sessions (service)
                    break;

                case "lock":
                    LockWorkStation();
                    break;

                case "logoff":
                    System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                    {
                        FileName = "shutdown.exe",
                        Arguments = "/l",
                        UseShellExecute = false,
                        CreateNoWindow = true
                    });
                    break;

                case "reboot":
                    System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                    {
                        FileName = "shutdown.exe",
                        Arguments = "/r /t 5 /c \"Redémarrage déclenché par le service informatique (DockPolice)\"",
                        UseShellExecute = false,
                        CreateNoWindow = true
                    });
                    break;

                case "shutdown":
                    System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
                    {
                        FileName = "shutdown.exe",
                        Arguments = "/s /t 5 /c \"Arrêt déclenché par le service informatique (DockPolice)\"",
                        UseShellExecute = false,
                        CreateNoWindow = true
                    });
                    break;

                default:
                    status = "failed";
                    error = "Action inconnue : " + a.action;
                    break;
            }
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Échec action #{id}", a.id);
            status = "failed";
            error = ex.Message;
        }

        await _api.PostSystemActionResultAsync(a.id, status, error, ct);
    }
}
