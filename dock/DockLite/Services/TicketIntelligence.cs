using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

namespace DockLite.Services;

/// <summary>
/// Appels aux endpoints backoffice d'auto-classification et de détection de doublons.
/// Utilisés par la fenêtre SAV pour suggérer catégorie/priorité et prévenir d'un doublon
/// avant la création du ticket.
/// </summary>
public static class TicketIntelligence
{
    public class Classification
    {
        [JsonPropertyName("category")]   public string Category   { get; set; } = "";
        [JsonPropertyName("priority")]   public string Priority   { get; set; } = "";
        [JsonPropertyName("confidence")] public int    Confidence { get; set; }
    }

    public class SimilarTicket
    {
        [JsonPropertyName("id")]       public int    Id       { get; set; }
        [JsonPropertyName("subject")]  public string Subject  { get; set; } = "";
        [JsonPropertyName("priority")] public string Priority { get; set; } = "";
        [JsonPropertyName("status")]   public string Status   { get; set; } = "";
        [JsonPropertyName("created_at")] public string CreatedAt { get; set; } = "";
        [JsonPropertyName("score")]    public int    Score    { get; set; }
    }

    public static async Task<Classification?> ClassifyAsync(string subject, string description)
    {
        if (!AttachmentApi.IsConfigured) return null;
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/ticket-classify.php";
            using var req = new HttpRequestMessage(HttpMethod.Post, url)
            {
                Content = JsonContent.Create(new { subject, description })
            };
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return null;
            return await resp.Content.ReadFromJsonAsync<Classification>();
        }
        catch { return null; }
    }

    public static async Task<List<SimilarTicket>> FindSimilarAsync(string machine, string subject, string category)
    {
        if (!AttachmentApi.IsConfigured || string.IsNullOrWhiteSpace(subject))
            return new List<SimilarTicket>();
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
            var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/ticket-similar.php"
                    + "?machine=" + Uri.EscapeDataString(machine)
                    + "&subject=" + Uri.EscapeDataString(subject)
                    + "&category=" + Uri.EscapeDataString(category);
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return new List<SimilarTicket>();
            using var doc = JsonDocument.Parse(await resp.Content.ReadAsStringAsync());
            if (!doc.RootElement.TryGetProperty("matches", out var arr)) return new List<SimilarTicket>();
            return arr.Deserialize<List<SimilarTicket>>() ?? new List<SimilarTicket>();
        }
        catch { return new List<SimilarTicket>(); }
    }
}
