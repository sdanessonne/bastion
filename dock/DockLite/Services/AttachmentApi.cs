using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;
using System.Threading.Tasks;

namespace DockLite.Services;

public class AttachmentInfo
{
    public int Id { get; set; }
    public string OriginalName { get; set; } = "";
    public string MimeType { get; set; } = "";
    public long SizeBytes { get; set; }
    public string UploadedBy { get; set; } = "";
    public DateTime UploadedAt { get; set; }
}

public static class AttachmentApi
{
    public static string? BaseUrl { get; set; }
    public static string? ApiKey { get; set; }

    public static bool IsConfigured =>
        !string.IsNullOrWhiteSpace(BaseUrl) && !string.IsNullOrWhiteSpace(ApiKey);

    private static readonly HttpClient _http = new()
    {
        Timeout = TimeSpan.FromMinutes(2)
    };

    private static readonly JsonSerializerOptions _jsonOpts = new()
    {
        PropertyNameCaseInsensitive = true
    };

    private static string Url(string path) =>
        BaseUrl!.TrimEnd('/') + "/api/" + path;

    public static async Task<AttachmentInfo> UploadAsync(int ticketId, string userName, byte[] data, string fileName, string mimeType = "application/octet-stream")
    {
        EnsureConfigured();

        using var content = new MultipartFormDataContent();
        content.Add(new StringContent(ticketId.ToString()), "ticket_id");
        content.Add(new StringContent(userName), "user");

        var fileContent = new ByteArrayContent(data);
        fileContent.Headers.ContentType = new MediaTypeHeaderValue(mimeType);
        content.Add(fileContent, "file", fileName);

        using var req = new HttpRequestMessage(HttpMethod.Post, Url("upload.php")) { Content = content };
        req.Headers.Add("X-API-Key", ApiKey);

        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"Upload échoué ({(int)resp.StatusCode}) : {body}");

        var doc = JsonDocument.Parse(body);
        var root = doc.RootElement;
        return new AttachmentInfo
        {
            Id = root.GetProperty("id").GetInt32(),
            OriginalName = root.GetProperty("original_name").GetString() ?? "",
            MimeType = root.GetProperty("mime_type").GetString() ?? "",
            SizeBytes = root.GetProperty("size_bytes").GetInt64(),
        };
    }

    public static async Task<List<AttachmentInfo>> ListAsync(int ticketId, string userName)
    {
        EnsureConfigured();

        var url = Url($"attachments.php?ticket_id={ticketId}&user={Uri.EscapeDataString(userName)}");
        using var req = new HttpRequestMessage(HttpMethod.Get, url);
        req.Headers.Add("X-API-Key", ApiKey);

        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"List échoué ({(int)resp.StatusCode}) : {body}");

        return JsonSerializer.Deserialize<List<AttachmentInfo>>(body, _jsonOpts) ?? new();
    }

    public static async Task<byte[]> DownloadAsync(int attachmentId, string userName)
    {
        EnsureConfigured();

        var url = Url($"download.php?id={attachmentId}&user={Uri.EscapeDataString(userName)}");
        using var req = new HttpRequestMessage(HttpMethod.Get, url);
        req.Headers.Add("X-API-Key", ApiKey);

        using var resp = await _http.SendAsync(req);
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"Download échoué ({(int)resp.StatusCode})");
        return await resp.Content.ReadAsByteArrayAsync();
    }

    public static string DownloadUrl(int attachmentId, string userName) =>
        Url($"download.php?id={attachmentId}&user={Uri.EscapeDataString(userName)}&api_key={Uri.EscapeDataString(ApiKey!)}");

    private static void EnsureConfigured()
    {
        if (!IsConfigured)
            throw new InvalidOperationException("API non configurée (ApiBaseUrl et ApiKey requis dans apps.json).");
    }
}
