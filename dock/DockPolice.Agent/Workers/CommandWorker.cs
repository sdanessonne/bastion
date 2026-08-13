using System;
using System.Diagnostics;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using DockPolice.Agent.Services;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace DockPolice.Agent.Workers;

public class CommandWorker : BackgroundService
{
    private readonly ILogger<CommandWorker> _log;
    private readonly ApiClient _api;
    private readonly AgentConfig _cfg;

    public CommandWorker(ILogger<CommandWorker> log, ApiClient api, AgentConfig cfg)
    {
        _log = log;
        _api = api;
        _cfg = cfg;
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        _log.LogInformation("CommandWorker démarré (poll {s}s)", _cfg.CommandPollIntervalSeconds);

        while (!ct.IsCancellationRequested)
        {
            try
            {
                var doc = await _api.PollCommandsAsync(Environment.MachineName, ct);
                if (doc.HasValue && doc.Value.TryGetProperty("commands", out var cmds))
                {
                    foreach (var cmd in cmds.EnumerateArray())
                    {
                        int id = cmd.GetProperty("id").GetInt32();
                        string shell = cmd.GetProperty("shell").GetString() ?? "powershell";
                        string command = cmd.GetProperty("command").GetString() ?? "";
                        _ = ExecuteAndReport(id, shell, command, ct);
                    }
                }
            }
            catch (Exception ex)
            {
                _log.LogError(ex, "Erreur poll commandes");
            }

            try { await Task.Delay(TimeSpan.FromSeconds(_cfg.CommandPollIntervalSeconds), ct); }
            catch (TaskCanceledException) { break; }
        }
    }

    private async Task ExecuteAndReport(int id, string shell, string command, CancellationToken ct)
    {
        var output = new StringBuilder();
        int exitCode = -1;
        string status = "done";

        _log.LogInformation("Exécution commande #{id} ({shell}): {cmd}", id, shell, command);

        try
        {
            var psi = shell.Equals("cmd", StringComparison.OrdinalIgnoreCase)
                ? new ProcessStartInfo("cmd.exe", "/c " + command)
                : new ProcessStartInfo("powershell.exe",
                    "-NoProfile -ExecutionPolicy Bypass -Command \"" + command.Replace("\"", "\\\"") + "\"");

            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.StandardOutputEncoding = Encoding.UTF8;
            psi.StandardErrorEncoding = Encoding.UTF8;

            using var proc = Process.Start(psi)!;
            proc.OutputDataReceived += (_, e) => { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
            proc.ErrorDataReceived  += (_, e) => { if (e.Data != null) lock (output) output.AppendLine("[ERR] " + e.Data); };
            proc.BeginOutputReadLine();
            proc.BeginErrorReadLine();

            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(TimeSpan.FromSeconds(60));
            try
            {
                await proc.WaitForExitAsync(cts.Token);
                exitCode = proc.ExitCode;
            }
            catch (OperationCanceledException)
            {
                try { proc.Kill(true); } catch { }
                lock (output) output.AppendLine("\n[Timeout dépassé : 60s]");
                status = "timeout";
            }
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "Échec exécution commande #{id}", id);
            lock (output) output.AppendLine("Exception : " + ex.Message);
            status = "failed";
        }

        await _api.PostCommandResultAsync(id, status, exitCode, output.ToString(), ct);
        _log.LogInformation("Commande #{id} terminée ({status}, exit={code})", id, status, exitCode);
    }
}
