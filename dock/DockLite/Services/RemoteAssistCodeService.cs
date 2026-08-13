using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;

namespace DockLite.Services;

public static class RemoteAssistCodeService
{
    public static string? BaseUrl { get; set; }
    public static string? ApiKey  { get; set; }

    public static bool IsConfigured =>
        !string.IsNullOrWhiteSpace(BaseUrl) && !string.IsNullOrWhiteSpace(ApiKey);

    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(8) };

    public class CodeResult
    {
        public bool Ok { get; set; }
        public string Code { get; set; } = "";
        public DateTime ExpiresAt { get; set; }
        public int ExpiresIn { get; set; }
    }

    public static async Task<CodeResult?> RequestAsync(string matricule, string machine)
    {
        if (!IsConfigured) throw new InvalidOperationException("API non configurée");
        var url = BaseUrl!.TrimEnd('/') + "/api/remote-assist-code.php";
        var data = new System.Collections.Generic.Dictionary<string, string>
        {
            { "matricule", matricule },
            { "machine",   machine },
        };
        using var content = new FormUrlEncodedContent(data);
        using var req = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        req.Headers.Add("X-API-Key", ApiKey);
        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode) throw new HttpRequestException($"HTTP {(int)resp.StatusCode} : {body}");
        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;
        return new CodeResult
        {
            Ok = root.GetProperty("ok").GetBoolean(),
            Code = root.GetProperty("code").GetString() ?? "",
            ExpiresIn = root.TryGetProperty("expires_in", out var ei) ? ei.GetInt32() : 300,
            ExpiresAt = DateTime.TryParse(root.GetProperty("expires_at").GetString(), out var dt) ? dt : DateTime.Now.AddMinutes(5),
        };
    }
}
