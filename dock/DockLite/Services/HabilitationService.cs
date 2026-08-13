using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;
using System.Threading.Tasks;

namespace DockLite.Services;

public class HabilitationSoftware
{
    public string Key { get; set; } = "";
    public string Label { get; set; } = "";
    public List<string> Profiles { get; set; } = new();
    public override string ToString() => Label;
}

public class HabilitationCreateResult
{
    public bool Ok { get; set; }
    public int Id { get; set; }
    public string Ref { get; set; } = "";
    public string Message { get; set; } = "";
}

/// <summary>
/// Représente une habilitation pour l'affichage dans la liste DockPolice.
/// </summary>
public class HabilitationItem
{
    public int Id { get; set; }
    public string Ref { get; set; } = "";
    public string Software { get; set; } = "";
    public string Profile { get; set; } = "";
    public string Scope { get; set; } = "";
    public string Status { get; set; } = "";       // demande / en_cours / active / suspendue / expiree / revoquee
    public string StatusLabel { get; set; } = "";
    public int ProgressPct { get; set; }
    public DateTime? GrantedAt { get; set; }
    public DateTime? ExpiryDate { get; set; }
    public DateTime? CreatedAt { get; set; }
    public string GrantedBy { get; set; } = "";
    public string Authority { get; set; } = "";
    public bool HasPdf { get; set; }
    public string CpnName { get; set; } = "";
}

public static class HabilitationService
{
    public static string? BaseUrl { get; set; }
    public static string? ApiKey { get; set; }

    public static bool IsConfigured =>
        !string.IsNullOrWhiteSpace(BaseUrl) && !string.IsNullOrWhiteSpace(ApiKey);

    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromMinutes(2) };
    private static readonly JsonSerializerOptions _opts = new() { PropertyNameCaseInsensitive = true };

    private static string Url(string path) => BaseUrl!.TrimEnd('/') + "/api/" + path;

    public static async Task<List<HabilitationSoftware>> GetCatalogAsync()
    {
        if (!IsConfigured) throw new InvalidOperationException("API non configurée.");
        using var req = new HttpRequestMessage(HttpMethod.Get, Url("habilitation-catalog.php"));
        req.Headers.Add("X-API-Key", ApiKey);
        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"Catalogue habilitations indisponible ({(int)resp.StatusCode}).");
        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;
        var list = new List<HabilitationSoftware>();
        if (root.TryGetProperty("catalog", out var arr) && arr.ValueKind == JsonValueKind.Array)
        {
            foreach (var s in arr.EnumerateArray())
            {
                var profiles = new List<string>();
                if (s.TryGetProperty("profiles", out var pa))
                    foreach (var p in pa.EnumerateArray()) profiles.Add(p.GetString() ?? "");
                list.Add(new HabilitationSoftware
                {
                    Key = s.GetProperty("key").GetString() ?? "",
                    Label = s.GetProperty("label").GetString() ?? "",
                    Profiles = profiles,
                });
            }
        }
        return list;
    }

    /// <summary>
    /// Récupère la liste des habilitations rattachées à un matricule (max 50).
    /// </summary>
    public static async Task<List<HabilitationItem>> ListMineAsync(string matricule)
    {
        var list = new List<HabilitationItem>();
        if (!IsConfigured || string.IsNullOrWhiteSpace(matricule)) return list;

        var url = Url($"habilitation-list.php?matricule={Uri.EscapeDataString(matricule)}");
        using var req = new HttpRequestMessage(HttpMethod.Get, url);
        req.Headers.Add("X-API-Key", ApiKey);
        using var resp = await _http.SendAsync(req);
        if (!resp.IsSuccessStatusCode) return list;

        var body = await resp.Content.ReadAsStringAsync();
        using var doc = JsonDocument.Parse(body);
        if (!doc.RootElement.TryGetProperty("habilitations", out var arr)) return list;

        foreach (var h in arr.EnumerateArray())
        {
            DateTime? GetDate(string prop)
            {
                if (!h.TryGetProperty(prop, out var v) || v.ValueKind == JsonValueKind.Null) return null;
                var s = v.GetString();
                return DateTime.TryParse(s, out var dt) ? dt : (DateTime?)null;
            }
            list.Add(new HabilitationItem
            {
                Id          = h.GetProperty("id").GetInt32(),
                Ref         = h.GetProperty("ref").GetString() ?? "",
                Software    = h.GetProperty("software").GetString() ?? "",
                Profile     = h.TryGetProperty("profile_level", out var p) ? p.GetString() ?? "" : "",
                Scope       = h.TryGetProperty("scope", out var sc) ? sc.GetString() ?? "" : "",
                Status      = h.GetProperty("status").GetString() ?? "",
                StatusLabel = h.GetProperty("status_label").GetString() ?? "",
                ProgressPct = h.GetProperty("progress_pct").GetInt32(),
                GrantedAt   = GetDate("granted_at"),
                ExpiryDate  = GetDate("expiry_date"),
                CreatedAt   = GetDate("created_at"),
                GrantedBy   = h.TryGetProperty("granted_by", out var gb) ? gb.GetString() ?? "" : "",
                Authority   = h.TryGetProperty("authority", out var au) ? au.GetString() ?? "" : "",
                HasPdf      = h.TryGetProperty("has_pdf", out var hp) && hp.GetBoolean(),
                CpnName     = h.TryGetProperty("cpn_name", out var cn) && cn.ValueKind != JsonValueKind.Null
                                ? cn.GetString() ?? "" : "",
            });
        }
        return list;
    }

    public static async Task<HabilitationCreateResult> CreateAsync(
        string matricule, string agentName, string software, string profile,
        string scope, string notes, string? pdfPath)
    {
        if (!IsConfigured) throw new InvalidOperationException("API non configurée.");

        using var content = new MultipartFormDataContent();
        content.Add(new StringContent(matricule),    "matricule");
        content.Add(new StringContent(agentName),    "agent_name");
        content.Add(new StringContent(software),     "software");
        content.Add(new StringContent(profile),      "profile_level");
        content.Add(new StringContent(scope ?? ""),  "scope");
        content.Add(new StringContent(notes ?? ""),  "notes");

        if (!string.IsNullOrEmpty(pdfPath) && File.Exists(pdfPath))
        {
            var bytes = await File.ReadAllBytesAsync(pdfPath);
            var f = new ByteArrayContent(bytes);
            f.Headers.ContentType = new MediaTypeHeaderValue("application/pdf");
            content.Add(f, "pdf", Path.GetFileName(pdfPath));
        }

        using var req = new HttpRequestMessage(HttpMethod.Post, Url("habilitation-create.php")) { Content = content };
        req.Headers.Add("X-API-Key", ApiKey);

        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"Erreur API ({(int)resp.StatusCode}) : {body}");
        using var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;
        return new HabilitationCreateResult
        {
            Ok = root.TryGetProperty("ok", out var okEl) && okEl.GetBoolean(),
            Id = root.TryGetProperty("id", out var idEl) ? idEl.GetInt32() : 0,
            Ref = root.TryGetProperty("ref", out var rEl) ? (rEl.GetString() ?? "") : "",
            Message = root.TryGetProperty("message", out var mEl) ? (mEl.GetString() ?? "") : "",
        };
    }
}
