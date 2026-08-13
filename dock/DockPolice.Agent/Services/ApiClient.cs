using System;
using System.Net.Http;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace DockPolice.Agent.Services;

public class ApiClient
{
    private readonly HttpClient _http;
    private readonly AgentConfig _cfg;

    public ApiClient(AgentConfig cfg)
    {
        _cfg = cfg;
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(20) };
        AgentSecretStore.Load();
        // Enregistrement opportuniste si pas de secret encore : ne bloque pas le démarrage.
        if (!AgentSecretStore.HasSecret)
            _ = EnsureRegisteredAsync();
    }

    private string Url(string path) => _cfg.ApiBaseUrl.TrimEnd('/') + "/api/" + path;

    /// <summary>
    /// Enregistrement HMAC : utilise la clé legacy une seule fois pour générer un
    /// secret persistant par machine. Idempotent (no-op si déjà enregistré).
    /// </summary>
    public async Task<bool> EnsureRegisteredAsync()
    {
        if (AgentSecretStore.HasSecret) return true;
        return await AgentSecretStore.TryRegisterAsync(_cfg.ApiBaseUrl, _cfg.ApiKey);
    }

    /// <summary>
    /// Signe la requête en HMAC-SHA256 si on a un secret, sinon retombe sur la clé legacy.
    /// Le canonical string est : "<timestamp>.<method>.<path>.<sha256(body)>".
    /// </summary>
    private async Task SignAsync(HttpRequestMessage req)
    {
        if (AgentSecretStore.HasSecret)
        {
            var ts       = ((DateTimeOffset)DateTime.UtcNow).ToUnixTimeSeconds().ToString();
            var path     = req.RequestUri!.AbsolutePath;
            var bodyText = req.Content != null ? await req.Content.ReadAsStringAsync() : "";
            var bodyHash = ToHex(SHA256.HashData(Encoding.UTF8.GetBytes(bodyText)));
            var canon    = $"{ts}.{req.Method.Method}.{path}.{bodyHash}";

            byte[] key;
            try   { key = Convert.FromHexString(AgentSecretStore.Secret!); }
            catch { key = Encoding.ASCII.GetBytes(AgentSecretStore.Secret!); }

            using var hmac = new HMACSHA256(key);
            var sign = ToHex(hmac.ComputeHash(Encoding.UTF8.GetBytes(canon)));

            req.Headers.Add("X-Machine",   AgentSecretStore.MachineName);
            req.Headers.Add("X-Timestamp", ts);
            req.Headers.Add("X-Sign",      sign);
        }
        else
        {
            req.Headers.Add("X-API-Key", _cfg.ApiKey);
        }
    }

    private static string ToHex(byte[] bytes)
    {
        var sb = new StringBuilder(bytes.Length * 2);
        foreach (var b in bytes) sb.Append(b.ToString("x2"));
        return sb.ToString();
    }

    public async Task<bool> SendStaticAsync(MachineSnapshot.StaticInfo info, CancellationToken ct)
    {
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, Url("machine-snapshot.php"))
            {
                Content = JsonContent.Create(info)
            };
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            return resp.IsSuccessStatusCode;
        }
        catch { return false; }
    }

    public async Task<bool> SendLiveAsync(string machineName, string? userName,
        MachineLive.LiveSnapshot snap, string sessionState, CancellationToken ct)
    {
        try
        {
            var payload = new
            {
                machine_name = machineName,
                user_name = userName ?? "",
                cpu_percent = snap.CpuPercent,
                ram_used_mb = snap.RamUsedMb,
                ram_total_mb = snap.RamTotalMb,
                idle_seconds = snap.IdleSeconds,
                is_locked = snap.IsLocked ? 1 : 0,
                active_session = snap.ActiveSession,
                session_state = sessionState,
                processes_json = JsonSerializer.Serialize(snap.Processes)
            };
            using var req = new HttpRequestMessage(HttpMethod.Post, Url("machine-live.php"))
            {
                Content = JsonContent.Create(payload)
            };
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            return resp.IsSuccessStatusCode;
        }
        catch { return false; }
    }

    public async Task<JsonElement?> PollCommandsAsync(string machineName, CancellationToken ct)
    {
        try
        {
            var url = Url($"agent-poll.php?machine={Uri.EscapeDataString(machineName)}");
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            if (!resp.IsSuccessStatusCode) return null;
            var body = await resp.Content.ReadAsStringAsync(ct);
            using var doc = JsonDocument.Parse(body);
            return doc.RootElement.Clone();
        }
        catch { return null; }
    }

    public async Task<bool> PostCommandResultAsync(int id, string status, int exitCode, string output, CancellationToken ct)
    {
        try
        {
            var payload = new { id, status, exit_code = exitCode, output };
            using var req = new HttpRequestMessage(HttpMethod.Post, Url("agent-result.php"))
            {
                Content = JsonContent.Create(payload)
            };
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            return resp.IsSuccessStatusCode;
        }
        catch { return false; }
    }

    public async Task<System.Collections.Generic.List<(int id, string action)>?> PollSystemActionsAsync(string machine, CancellationToken ct)
    {
        try
        {
            var url = Url($"system-action-poll.php?machine={Uri.EscapeDataString(machine)}");
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            if (!resp.IsSuccessStatusCode) return null;

            var body = await resp.Content.ReadAsStringAsync(ct);
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("actions", out var actions)) return null;

            var result = new System.Collections.Generic.List<(int, string)>();
            foreach (var el in actions.EnumerateArray())
            {
                result.Add((el.GetProperty("id").GetInt32(), el.GetProperty("action").GetString() ?? ""));
            }
            return result;
        }
        catch { return null; }
    }

    public async Task<bool> PostSystemActionResultAsync(int id, string status, string? error, CancellationToken ct)
    {
        try
        {
            var payload = new { id, status, error };
            using var req = new HttpRequestMessage(HttpMethod.Post, Url("system-action-result.php"))
            {
                Content = JsonContent.Create(payload)
            };
            await SignAsync(req);
            using var resp = await _http.SendAsync(req, ct);
            return resp.IsSuccessStatusCode;
        }
        catch { return false; }
    }
}
