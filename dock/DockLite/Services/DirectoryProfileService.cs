using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Threading.Tasks;
using MySqlConnector;

namespace DockLite.Services;

/// <summary>
/// Lecture / écriture du contact "self" dans la table contacts du backoffice.
/// Identifié par matricule (= login Windows / SAMAccountName).
/// </summary>
public static class DirectoryProfileService
{
    public class ProfileEntry
    {
        public int?    Id              { get; set; }
        public string  Matricule       { get; set; } = "";
        public string  FirstName       { get; set; } = "";
        public string  LastName        { get; set; } = "";
        public string  DisplayName     { get; set; } = "";
        public string  Email           { get; set; } = "";
        public string  Role            { get; set; } = "";   // grade officiel (ex : "Gardien de la paix", "Lieutenant de police")
        public string  Service         { get; set; } = "";   // legacy : libellé texte (rempli depuis le service choisi)
        public int?    ServiceId       { get; set; }          // FK vers commissariat_services
        public int?    CommissariatId  { get; set; }
        public string? CommissariatName{ get; set; }
        public string  PhoneFixed      { get; set; } = "";
        public string  PhoneMobile     { get; set; } = "";
        public string  PhoneNeo        { get; set; } = "";
        public DateTime? LastSelfUpdate{ get; set; }
        public string  Source          { get; set; } = "manuel";
        public string? PhotoPath       { get; set; }   // ex : "ad_micka_3a4f12.jpg" → URL = {ApiBaseUrl}/uploads/contacts/<PhotoPath>
    }

    /// <summary>
    /// Représente un service / brigade affecté à un commissariat (ex : BAC, BSU, GAV).
    /// </summary>
    public class ServiceEntry
    {
        public int     Id        { get; set; }
        public int     CpnId     { get; set; }
        public string  TypeCode  { get; set; } = "";
        public string  TypeName  { get; set; } = "";
        public string? LocalName { get; set; }
        public string? ShortName { get; set; }
        public string  Category  { get; set; } = "";
        public string? Icon      { get; set; }
        public int     SortOrder { get; set; }

        public string DisplayName =>
            !string.IsNullOrWhiteSpace(LocalName) ? LocalName!
            : !string.IsNullOrWhiteSpace(TypeName) ? TypeName
            : (ShortName ?? TypeCode);

        public override string ToString() => DisplayName;
    }

    /// <summary>
    /// Liste les services actifs pour un commissariat donné. Si la table
    /// `commissariat_services` n'existe pas (ancienne installation), renvoie une
    /// liste vide — l'UI peut alors retomber sur la saisie texte libre.
    /// </summary>
    public static async Task<List<ServiceEntry>> GetServicesAsync(int commissariatId)
    {
        var list = new List<ServiceEntry>();
        if (!TicketService.IsConfigured || commissariatId <= 0) return list;

        const string sql = @"
SELECT cs.id, cs.commissariat_id,
       st.code, st.name, cs.local_name, st.short_name, st.category, st.icon, st.sort_order
FROM commissariat_services cs
JOIN service_types st ON st.id = cs.service_type_id
WHERE cs.commissariat_id = @cpn AND cs.active = 1 AND st.active = 1
ORDER BY st.sort_order, st.name";

        try
        {
            await using var conn = new MySqlConnection(TicketService.ConnectionString);
            await conn.OpenAsync();
            await using var cmd = new MySqlCommand(sql, conn);
            cmd.Parameters.AddWithValue("@cpn", commissariatId);
            await using var rd = await cmd.ExecuteReaderAsync();
            while (await rd.ReadAsync())
            {
                list.Add(new ServiceEntry
                {
                    Id        = rd.GetInt32(0),
                    CpnId     = rd.GetInt32(1),
                    TypeCode  = rd.IsDBNull(2) ? "" : rd.GetString(2),
                    TypeName  = rd.IsDBNull(3) ? "" : rd.GetString(3),
                    LocalName = rd.IsDBNull(4) ? null : rd.GetString(4),
                    ShortName = rd.IsDBNull(5) ? null : rd.GetString(5),
                    Category  = rd.IsDBNull(6) ? "" : rd.GetString(6),
                    Icon      = rd.IsDBNull(7) ? null : rd.GetString(7),
                    SortOrder = rd.IsDBNull(8) ? 100 : rd.GetInt32(8),
                });
            }
        }
        catch (MySqlException) { /* table absente : retombe sur free-text */ }
        return list;
    }

    /// <summary>Charge le profil par matricule (null si pas encore créé).</summary>
    public static async Task<ProfileEntry?> LoadAsync(string matricule)
    {
        if (!TicketService.IsConfigured || string.IsNullOrWhiteSpace(matricule)) return null;

        const string sql = @"
SELECT c.id, c.matricule, c.first_name, c.last_name, c.display_name, c.email,
       c.service, c.commissariat_id, cp.name AS cpn_name,
       c.phone_fixed, c.phone_mobile, c.phone_neo, c.last_self_update, c.source,
       c.service_id, c.role, c.photo_path
FROM contacts c
LEFT JOIN commissariats cp ON cp.id = c.commissariat_id
WHERE c.matricule = @m LIMIT 1";

        await using var conn = new MySqlConnection(TicketService.ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@m", matricule);
        await using var rd = await cmd.ExecuteReaderAsync();
        if (!await rd.ReadAsync()) return null;

        return new ProfileEntry
        {
            Id              = rd.GetInt32(0),
            Matricule       = rd.IsDBNull(1) ? "" : rd.GetString(1),
            FirstName       = rd.IsDBNull(2) ? "" : rd.GetString(2),
            LastName        = rd.IsDBNull(3) ? "" : rd.GetString(3),
            DisplayName     = rd.IsDBNull(4) ? "" : rd.GetString(4),
            Email           = rd.IsDBNull(5) ? "" : rd.GetString(5),
            Service         = rd.IsDBNull(6) ? "" : rd.GetString(6),
            CommissariatId  = rd.IsDBNull(7) ? (int?)null : rd.GetInt32(7),
            CommissariatName= rd.IsDBNull(8) ? null : rd.GetString(8),
            PhoneFixed      = rd.IsDBNull(9)  ? "" : rd.GetString(9),
            PhoneMobile     = rd.IsDBNull(10) ? "" : rd.GetString(10),
            PhoneNeo        = rd.IsDBNull(11) ? "" : rd.GetString(11),
            LastSelfUpdate  = rd.IsDBNull(12) ? (DateTime?)null : rd.GetDateTime(12),
            Source          = rd.IsDBNull(13) ? "manuel" : rd.GetString(13),
            ServiceId       = rd.IsDBNull(14) ? (int?)null : rd.GetInt32(14),
            Role            = rd.IsDBNull(15) ? "" : rd.GetString(15),
            PhotoPath       = rd.IsDBNull(16) ? null : rd.GetString(16),
        };
    }

    /// <summary>UPSERT par matricule : crée si absent, met à jour si présent.</summary>
    public static async Task UpsertAsync(ProfileEntry p)
    {
        if (!TicketService.IsConfigured) throw new InvalidOperationException("MySQL non configuré.");
        if (string.IsNullOrWhiteSpace(p.Matricule)) throw new ArgumentException("Matricule requis.");

        // INSERT ... ON DUPLICATE KEY UPDATE
        const string sql = @"
INSERT INTO contacts (matricule, first_name, last_name, display_name, email,
                       role, service, service_id, commissariat_id,
                       phone_fixed, phone_mobile, phone_neo,
                       source, last_self_update, created_by)
VALUES (@mat, @fn, @ln, @dn, @em,
        @rl, @sv, @sid, @cp,
        @pf, @pm, @pn,
        'self_dock', NOW(), @cb)
ON DUPLICATE KEY UPDATE
    first_name      = VALUES(first_name),
    last_name       = VALUES(last_name),
    display_name    = VALUES(display_name),
    email           = VALUES(email),
    role            = VALUES(role),
    service         = VALUES(service),
    service_id      = VALUES(service_id),
    commissariat_id = VALUES(commissariat_id),
    phone_fixed     = VALUES(phone_fixed),
    phone_mobile    = VALUES(phone_mobile),
    phone_neo       = VALUES(phone_neo),
    source          = 'self_dock',
    last_self_update= NOW();";

        await using var conn = new MySqlConnection(TicketService.ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@mat", Truncate(p.Matricule, 80));
        cmd.Parameters.AddWithValue("@fn",  Nullable(Truncate(p.FirstName, 80)));
        cmd.Parameters.AddWithValue("@ln",  Nullable(Truncate(p.LastName, 80)));
        cmd.Parameters.AddWithValue("@dn",  Truncate(string.IsNullOrWhiteSpace(p.DisplayName)
                                                ? $"{p.FirstName} {p.LastName}".Trim() : p.DisplayName, 120));
        cmd.Parameters.AddWithValue("@em",  Nullable(Truncate(p.Email, 150)));
        cmd.Parameters.AddWithValue("@rl",  Nullable(Truncate(p.Role, 120)));
        cmd.Parameters.AddWithValue("@sv",  Nullable(Truncate(p.Service, 120)));
        cmd.Parameters.AddWithValue("@sid", (object?)p.ServiceId      ?? DBNull.Value);
        cmd.Parameters.AddWithValue("@cp",  (object?)p.CommissariatId ?? DBNull.Value);
        cmd.Parameters.AddWithValue("@pf",  Nullable(Truncate(p.PhoneFixed, 30)));
        cmd.Parameters.AddWithValue("@pm",  Nullable(Truncate(p.PhoneMobile, 30)));
        cmd.Parameters.AddWithValue("@pn",  Nullable(Truncate(p.PhoneNeo, 30)));
        cmd.Parameters.AddWithValue("@cb",  Truncate(p.Matricule, 100));
        await cmd.ExecuteNonQueryAsync();
    }

    private static object Nullable(string? s)
        => string.IsNullOrWhiteSpace(s) ? (object)DBNull.Value : s;

    private static string Truncate(string? s, int len)
    {
        s = (s ?? "").Trim();
        return s.Length > len ? s.Substring(0, len) : s;
    }

    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromMinutes(2) };

    /// <summary>
    /// Upload de la photo (récupérée d'AD) vers l'API du backoffice.
    /// Renvoie le chemin relatif stocké côté serveur, ou null si l'API n'est pas configurée.
    /// </summary>
    public static async Task<string?> UploadPhotoAsync(string matricule, byte[] photoBytes, string mimeType = "image/jpeg")
    {
        if (string.IsNullOrWhiteSpace(matricule) || photoBytes == null || photoBytes.Length == 0)
            return null;
        if (!AttachmentApi.IsConfigured) return null; // pas d'URL API : on ne peut pas uploader

        using var content = new MultipartFormDataContent();
        content.Add(new StringContent(matricule), "matricule");

        var fileContent = new ByteArrayContent(photoBytes);
        fileContent.Headers.ContentType = new MediaTypeHeaderValue(mimeType);
        // Choix de l'extension selon mime
        var ext = mimeType switch
        {
            "image/png"  => "png",
            "image/webp" => "webp",
            _            => "jpg",
        };
        content.Add(fileContent, "photo", $"ad_{matricule}.{ext}");

        var url = AttachmentApi.BaseUrl!.TrimEnd('/') + "/api/directory-photo.php";
        using var req = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);

        using var resp = await _http.SendAsync(req);
        var body = await resp.Content.ReadAsStringAsync();
        if (!resp.IsSuccessStatusCode)
            throw new HttpRequestException($"Upload photo échoué ({(int)resp.StatusCode}) : {body}");

        // Réponse attendue : {"ok":true,"photo_path":"ad_xxx.jpg",...}
        try
        {
            using var doc = System.Text.Json.JsonDocument.Parse(body);
            if (doc.RootElement.TryGetProperty("photo_path", out var p))
                return p.GetString();
        }
        catch { }
        return null;
    }
}
