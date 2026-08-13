using System;
using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows.Threading;

namespace DockLite.Services;

public static class RemoteCommandService
{
    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(15) };
    private static DispatcherTimer? _timer;
    private static bool _busy;

    public static void Start()
    {
        if (!AttachmentApi.IsConfigured) return;
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(8) };
        _timer.Tick += async (_, _) => await Poll();
        _timer.Start();
    }

    private static async Task Poll()
    {
        if (_busy) return;
        _busy = true;
        try
        {
            var url = AttachmentApi.BaseUrl!.TrimEnd('/')
                    + "/api/agent-poll.php?machine=" + Uri.EscapeDataString(Environment.MachineName);

            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return;

            var body = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("commands", out var cmds)) return;

            foreach (var cmd in cmds.EnumerateArray())
            {
                int id = cmd.GetProperty("id").GetInt32();
                string shell = cmd.GetProperty("shell").GetString() ?? "powershell";
                string command = cmd.GetProperty("command").GetString() ?? "";

                // Commande interne de réactualisation : on ne l'exécute pas comme
                // un script, on force le renvoi immédiat du live + snapshot.
                if (command.Trim().Equals("#DOCK_REFRESH", StringComparison.OrdinalIgnoreCase))
                {
                    _ = HandleRefresh(id);
                    continue;
                }

                _ = ExecuteAndReport(id, shell, command);
            }
        }
        catch { /* silent */ }
        finally { _busy = false; }
    }

    // Limite sur le payload renvoyé au backoffice (évite de saturer mail/db)
    private const int MaxOutputBytes = 512 * 1024; // 512 KiB

    private static async Task ExecuteAndReport(int id, string shell, string command)
    {
        var output = new StringBuilder();
        int exitCode = -1;
        string status = "done";

        try
        {
            ProcessStartInfo psi;
            if (shell.Equals("cmd", StringComparison.OrdinalIgnoreCase))
            {
                psi = new ProcessStartInfo("cmd.exe", "/c " + command);
            }
            else
            {
                // PowerShell : on utilise -EncodedCommand (Base64 UTF-16LE) pour
                // passer les scripts complexes sans souci d'échappement (variables,
                // here-strings, regex, accents). Méthode officielle MS.
                var encoded = Convert.ToBase64String(Encoding.Unicode.GetBytes(command));
                psi = new ProcessStartInfo("powershell.exe",
                    "-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand " + encoded);
            }

            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.StandardOutputEncoding = Encoding.UTF8;
            psi.StandardErrorEncoding = Encoding.UTF8;

            using var proc = Process.Start(psi)!;
            proc.OutputDataReceived += (_, e) => {
                if (e.Data == null) return;
                lock (output) {
                    if (output.Length < MaxOutputBytes) output.AppendLine(e.Data);
                    else if (!output.ToString().EndsWith("[output tronqué]\n"))
                        output.AppendLine("[output tronqué]");
                }
            };
            proc.ErrorDataReceived  += (_, e) => {
                if (e.Data == null) return;
                lock (output) {
                    if (output.Length < MaxOutputBytes) output.AppendLine("[ERR] " + e.Data);
                }
            };
            proc.BeginOutputReadLine();
            proc.BeginErrorReadLine();

            // Timeout 180 s — Get-HotFix + manage-bde + Test-Connection peuvent
            // dépasser 60 s sur un poste lent.
            using var cts = new System.Threading.CancellationTokenSource(TimeSpan.FromSeconds(180));
            try
            {
                await proc.WaitForExitAsync(cts.Token);
                exitCode = proc.ExitCode;
                if (exitCode != 0) status = "done"; // on garde 'done' pour qu'output_text soit lu
            }
            catch (OperationCanceledException)
            {
                try { proc.Kill(true); } catch { }
                lock (output) output.AppendLine("\n[Timeout dépassé : 180 s]");
                status = "timeout";
            }
        }
        catch (Exception ex)
        {
            lock (output) output.AppendLine("Exception : " + ex.Message);
            status = "failed";
        }

        await ReportResultAsync(id, status, exitCode, output.ToString());
    }

    /// <summary>
    /// Traite la commande interne "#DOCK_REFRESH" : force le renvoi immédiat de
    /// l'état de la machine (live + snapshot), puis marque la commande terminée.
    /// </summary>
    private static async Task HandleRefresh(int id)
    {
        string status = "done";
        string output;
        try
        {
            await MachineReporter.ForceRefreshAsync();
            output = "Réactualisation effectuée (live + snapshot repoussés).";
        }
        catch (Exception ex)
        {
            status = "failed";
            output = "Échec réactualisation : " + ex.Message;
        }
        await ReportResultAsync(id, status, status == "done" ? 0 : -1, output);
    }

    private static async Task ReportResultAsync(int id, string status, int exitCode, string output)
    {
        try
        {
            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/agent-result.php";
            var payload = new
            {
                id,
                status,
                exit_code = exitCode,
                output
            };
            using var req = new HttpRequestMessage(HttpMethod.Post, url) { Content = JsonContent.Create(payload) };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            // Plus de timeout côté envoi de résultat (output peut être gros)
            using var sendCts = new System.Threading.CancellationTokenSource(TimeSpan.FromSeconds(60));
            await _http.SendAsync(req, sendCts.Token);
        }
        catch { }
    }
}
