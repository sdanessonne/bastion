using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;

namespace DockLite.Services;

[SupportedOSPlatform("windows")]
public static class ScreenStreamService
{
    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(15) };
    private static DispatcherTimer? _timer;
    private static bool _busy;

    // Bandeau d'information "prise en main en cours"
    private static Views.RemoteControlBanner? _banner;

    // Compression / dimensions
    private const long JpegQuality = 55L;
    private const int  MaxWidth    = 1280;

    public static void Start()
    {
        if (!AttachmentApi.IsConfigured) return;
        // 1 fps pendant session active : compromis latence / bande passante
        _timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
        _timer.Tick += async (_, _) => await Tick();
        _timer.Start();
    }

    private static async Task Tick()
    {
        if (_busy) return;
        _busy = true;
        try
        {
            // 1. Vérifier si un tech est en train de regarder
            var url = AttachmentApi.BaseUrl!.TrimEnd('/')
                    + "/api/screen-status.php?machine=" + Uri.EscapeDataString(Environment.MachineName);

            using var statReq = new HttpRequestMessage(HttpMethod.Get, url);
            statReq.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var statResp = await _http.SendAsync(statReq);
            if (!statResp.IsSuccessStatusCode) return;

            var body = await statResp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            bool active = doc.RootElement.TryGetProperty("active", out var activeEl) && activeEl.GetBoolean();

            if (!active)
            {
                HideBanner();
                return;
            }

            // Info technicien pour le bandeau
            string? techName  = doc.RootElement.TryGetProperty("tech_name", out var tn)  && tn.ValueKind == JsonValueKind.String ? tn.GetString()  : null;
            string? techPhone = doc.RootElement.TryGetProperty("tech_phone", out var tp) && tp.ValueKind == JsonValueKind.String ? tp.GetString() : null;
            ShowBanner(techName, techPhone);

            // 2. Stream actif → capture + upload
            byte[] jpeg = CaptureCompressedJpeg();

            using var content = new System.Net.Http.MultipartFormDataContent();
            content.Add(new System.Net.Http.StringContent(Environment.MachineName), "machine");
            var fileContent = new System.Net.Http.ByteArrayContent(jpeg);
            fileContent.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("image/jpeg");
            content.Add(fileContent, "frame", "frame.jpg");

            var uploadUrl = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/screen-frame.php";
            using var upReq = new HttpRequestMessage(HttpMethod.Post, uploadUrl) { Content = content };
            upReq.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            await _http.SendAsync(upReq);
        }
        catch { /* silent */ }
        finally { _busy = false; }
    }

    // ================================================================
    // Bandeau "prise en main en cours"
    // ================================================================
    private static void ShowBanner(string? name, string? phone)
    {
        Application.Current?.Dispatcher.Invoke(() =>
        {
            if (_banner == null)
            {
                _banner = new Views.RemoteControlBanner();
                _banner.Show();
            }
            _banner.SetTechnician(name, phone);
            if (!_banner.IsVisible) _banner.Show();
        });
    }

    private static void HideBanner()
    {
        if (_banner == null) return;
        Application.Current?.Dispatcher.Invoke(() =>
        {
            try { _banner?.Hide(); } catch { }
        });
    }

    private static byte[] CaptureCompressedJpeg()
    {
        var raw = ScreenCapture.CaptureAllScreensPng();
        using var ms = new MemoryStream(raw);
        using var bmp = (Bitmap)Image.FromStream(ms);

        // Resize si nécessaire
        Bitmap toEncode = bmp;
        bool createdResized = false;
        if (bmp.Width > MaxWidth)
        {
            int newH = bmp.Height * MaxWidth / bmp.Width;
            toEncode = new Bitmap(bmp, MaxWidth, newH);
            createdResized = true;
        }

        try
        {
            using var output = new MemoryStream();
            var jpegCodec = ImageCodecInfo.GetImageEncoders()
                .FirstOrDefault(e => e.MimeType == "image/jpeg")
                ?? throw new Exception("Encodeur JPEG introuvable.");

            var encParams = new EncoderParameters(1);
            encParams.Param[0] = new EncoderParameter(Encoder.Quality, JpegQuality);
            toEncode.Save(output, jpegCodec, encParams);
            return output.ToArray();
        }
        finally
        {
            if (createdResized) toEncode.Dispose();
        }
    }
}
