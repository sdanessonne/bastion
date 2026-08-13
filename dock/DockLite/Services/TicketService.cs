using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using DockLite.Models;
using MySqlConnector;

namespace DockLite.Services;

public static class TicketService
{
    public static string? ConnectionString { get; set; }

    public static bool IsConfigured => !string.IsNullOrWhiteSpace(ConnectionString);

    public static async Task<bool> TestConnectionAsync()
    {
        if (!IsConfigured) return false;
        try
        {
            await using var conn = new MySqlConnection(ConnectionString);
            await conn.OpenAsync();
            return true;
        }
        catch { return false; }
    }

    public static async Task<int> CreateTicketAsync(SupportTicket t)
    {
        if (!IsConfigured)
            throw new InvalidOperationException("Chaîne de connexion MySQL non configurée.");

        const string sql = @"
INSERT INTO tickets
    (machine_name, user_name, email, ip_address, commissariat_id, category, priority, subject, description, status)
VALUES
    (@machine, @user, @email, @ip, @cpn, @cat, @pri, @subj, @desc, 'Ouvert');
SELECT LAST_INSERT_ID();";

        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@machine", Truncate(t.MachineName, 100));
        cmd.Parameters.AddWithValue("@user", Truncate(t.UserName, 100));
        cmd.Parameters.AddWithValue("@email", string.IsNullOrWhiteSpace(t.Email) ? (object)DBNull.Value : Truncate(t.Email, 150));
        cmd.Parameters.AddWithValue("@ip", Truncate(t.IpAddress, 45));
        cmd.Parameters.AddWithValue("@cpn", (object?)t.CommissariatId ?? DBNull.Value);
        cmd.Parameters.AddWithValue("@cat", Truncate(t.Category, 50));
        cmd.Parameters.AddWithValue("@pri", Truncate(t.Priority, 20));
        cmd.Parameters.AddWithValue("@subj", Truncate(t.Subject, 200));
        cmd.Parameters.AddWithValue("@desc", t.Description ?? "");

        var result = await cmd.ExecuteScalarAsync();
        return Convert.ToInt32(result);
    }

    public static async Task<List<Commissariat>> GetCommissariatsAsync()
    {
        var list = new List<Commissariat>();
        if (!IsConfigured) return list;

        const string sql = @"SELECT id, parent_id, code, name, coverage
                             FROM commissariats
                             WHERE active = 1
                             ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, name";
        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        await using var reader = await cmd.ExecuteReaderAsync();
        while (await reader.ReadAsync())
        {
            list.Add(new Commissariat
            {
                Id = reader.GetInt32(0),
                ParentId = reader.IsDBNull(1) ? null : reader.GetInt32(1),
                Code = reader.GetString(2),
                Name = reader.GetString(3),
                Coverage = reader.IsDBNull(4) ? null : reader.GetString(4),
            });
        }
        return list;
    }

    public static async Task<Commissariat?> FindCommissariatByCodeAsync(string code)
    {
        if (string.IsNullOrWhiteSpace(code) || !IsConfigured) return null;
        var all = await GetCommissariatsAsync();
        return all.FirstOrDefault(c => string.Equals(c.Code, code, StringComparison.OrdinalIgnoreCase));
    }

    public static async Task<Commissariat?> ResolveByIpAsync(string ipv4)
    {
        if (string.IsNullOrWhiteSpace(ipv4) || !IsConfigured) return null;

        const string sql = @"
SELECT c.id, c.parent_id, c.code, c.name, c.coverage
FROM commissariat_ip_ranges r
JOIN commissariats c ON c.id = r.commissariat_id
WHERE INET_ATON(@ip) BETWEEN r.ip_start AND r.ip_end
  AND c.active = 1
ORDER BY (r.ip_end - r.ip_start) ASC
LIMIT 1";

        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@ip", ipv4);

        await using var reader = await cmd.ExecuteReaderAsync();
        if (!await reader.ReadAsync()) return null;

        return new Commissariat
        {
            Id = reader.GetInt32(0),
            ParentId = reader.IsDBNull(1) ? null : reader.GetInt32(1),
            Code = reader.GetString(2),
            Name = reader.GetString(3),
            Coverage = reader.IsDBNull(4) ? null : reader.GetString(4),
        };
    }

    public static async Task<List<SupportTicket>> GetTicketsByUserAsync(string userName, int limit = 50)
    {
        var list = new List<SupportTicket>();
        if (!IsConfigured) return list;

        // csat_score / csat_at sont ajoutés par migration côté backoffice
        // (api/ticket-rate.php). Si la colonne n'existe pas, on fallback.
        // tech_* viennent de la jointure users (username = tickets.assigned_to)
        const string sql = @"
SELECT t.id, t.created_at, t.machine_name, t.user_name, t.ip_address, t.category, t.priority,
       t.subject, t.description, t.status, t.assigned_to, t.resolved_at,
       /*csat*/ NULL AS csat_score, NULL AS csat_at,
       tu.display_name AS tech_name, tu.phone AS tech_phone,
       tu.email AS tech_email, tu.avatar_path AS tech_avatar, tu.role AS tech_role
FROM tickets t
LEFT JOIN users tu ON tu.username = t.assigned_to
WHERE t.user_name = @user
ORDER BY t.created_at DESC
LIMIT @limit;";

        // Détecte la présence de la colonne csat_score et adapte la requête
        const string sqlWithCsat = @"
SELECT t.id, t.created_at, t.machine_name, t.user_name, t.ip_address, t.category, t.priority,
       t.subject, t.description, t.status, t.assigned_to, t.resolved_at,
       t.csat_score, t.csat_at,
       tu.display_name AS tech_name, tu.phone AS tech_phone,
       tu.email AS tech_email, tu.avatar_path AS tech_avatar, tu.role AS tech_role
FROM tickets t
LEFT JOIN users tu ON tu.username = t.assigned_to
WHERE t.user_name = @user
ORDER BY t.created_at DESC
LIMIT @limit;";

        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();

        // Choisit la requête selon la présence de la colonne csat_score
        string chosenSql = sql;
        try
        {
            await using var probe = new MySqlCommand("SHOW COLUMNS FROM tickets LIKE 'csat_score'", conn);
            var v = await probe.ExecuteScalarAsync();
            if (v != null) chosenSql = sqlWithCsat;
        }
        catch { /* fallback sur sql sans csat */ }

        await using var cmd = new MySqlCommand(chosenSql, conn);
        cmd.Parameters.AddWithValue("@user", userName);
        cmd.Parameters.AddWithValue("@limit", limit);

        await using var reader = await cmd.ExecuteReaderAsync();
        while (await reader.ReadAsync())
        {
            list.Add(new SupportTicket
            {
                Id = reader.GetInt32(0),
                CreatedAt = reader.GetDateTime(1),
                MachineName = reader.IsDBNull(2) ? "" : reader.GetString(2),
                UserName = reader.IsDBNull(3) ? "" : reader.GetString(3),
                IpAddress = reader.IsDBNull(4) ? "" : reader.GetString(4),
                Category = reader.IsDBNull(5) ? "" : reader.GetString(5),
                Priority = reader.IsDBNull(6) ? "" : reader.GetString(6),
                Subject = reader.IsDBNull(7) ? "" : reader.GetString(7),
                Description = reader.IsDBNull(8) ? "" : reader.GetString(8),
                Status = reader.IsDBNull(9) ? "" : reader.GetString(9),
                AssignedTo = reader.IsDBNull(10) ? null : reader.GetString(10),
                ResolvedAt = reader.IsDBNull(11) ? null : reader.GetDateTime(11),
                CsatScore = reader.IsDBNull(12) ? null : reader.GetInt32(12),
                CsatAt = reader.IsDBNull(13) ? null : reader.GetDateTime(13),
                TechName = reader.IsDBNull(14) ? null : reader.GetString(14),
                TechPhone = reader.IsDBNull(15) ? null : reader.GetString(15),
                TechEmail = reader.IsDBNull(16) ? null : reader.GetString(16),
                TechAvatar = reader.IsDBNull(17) ? null : reader.GetString(17),
                TechRole = reader.IsDBNull(18) ? null : reader.GetString(18),
            });
        }
        return list;
    }

    public static async Task<bool> RateTicketAsync(int ticketId, int score, string comment, string author)
    {
        if (!IsConfigured) return false;
        if (score < 1 || score > 5) throw new ArgumentOutOfRangeException(nameof(score));

        // Migration idempotente côté .NET aussi (au cas où l'API n'est pas appelée en premier)
        await using (var conn0 = new MySqlConnection(ConnectionString))
        {
            await conn0.OpenAsync();
            try
            {
                await using var probe = new MySqlCommand("SHOW COLUMNS FROM tickets LIKE 'csat_score'", conn0);
                if (await probe.ExecuteScalarAsync() == null)
                {
                    await using var alter = new MySqlCommand(
                        "ALTER TABLE tickets ADD COLUMN csat_score TINYINT NULL, ADD COLUMN csat_comment TEXT NULL, ADD COLUMN csat_at DATETIME NULL", conn0);
                    await alter.ExecuteNonQueryAsync();
                }
            }
            catch { }
        }

        const string sql = @"UPDATE tickets
            SET csat_score=@s, csat_comment=@c, csat_at=NOW()
            WHERE id=@id AND status IN ('Résolu','Resolu','Clos','Clôturé') AND (csat_score IS NULL OR csat_score = 0)";
        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@s", score);
        cmd.Parameters.AddWithValue("@c", string.IsNullOrWhiteSpace(comment) ? (object)DBNull.Value : comment);
        cmd.Parameters.AddWithValue("@id", ticketId);
        return await cmd.ExecuteNonQueryAsync() == 1;
    }

    public static async Task<List<(DateTime CreatedAt, string Author, string Body, bool Internal)>> GetCommentsAsync(int ticketId)
    {
        var list = new List<(DateTime, string, string, bool)>();
        if (!IsConfigured) return list;

        const string sql = @"SELECT created_at, author, body, is_internal
                             FROM ticket_comments
                             WHERE ticket_id = @id AND is_internal = 0
                             ORDER BY created_at ASC";
        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@id", ticketId);
        await using var reader = await cmd.ExecuteReaderAsync();
        while (await reader.ReadAsync())
        {
            list.Add((
                reader.GetDateTime(0),
                reader.IsDBNull(1) ? "" : reader.GetString(1),
                reader.IsDBNull(2) ? "" : reader.GetString(2),
                reader.GetBoolean(3)
            ));
        }
        return list;
    }

    public static async Task<int> AddCommentAsync(int ticketId, string author, string body)
    {
        if (!IsConfigured) throw new InvalidOperationException("Connexion non configurée.");

        const string sql = @"
INSERT INTO ticket_comments (ticket_id, author, body, is_internal)
VALUES (@tid, @author, @body, 0);
SELECT LAST_INSERT_ID();";

        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@tid", ticketId);
        cmd.Parameters.AddWithValue("@author", author?.Length > 100 ? author.Substring(0, 100) : (author ?? ""));
        cmd.Parameters.AddWithValue("@body", body ?? "");

        var result = await cmd.ExecuteScalarAsync();
        return Convert.ToInt32(result);
    }

    public class TicketReplyNotification
    {
        public int CommentId { get; set; }
        public int TicketId { get; set; }
        public string TicketSubject { get; set; } = "";
        public string Author { get; set; } = "";
        public string Body { get; set; } = "";
        public DateTime CreatedAt { get; set; }
    }

    public static async Task<List<TicketReplyNotification>> GetUnreadCommentsForUserAsync(string userName, int sinceId)
    {
        var list = new List<TicketReplyNotification>();
        if (!IsConfigured || string.IsNullOrWhiteSpace(userName)) return list;

        const string sql = @"
SELECT c.id, c.ticket_id, t.subject, c.author, c.body, c.created_at
FROM ticket_comments c
JOIN tickets t ON t.id = c.ticket_id
WHERE t.user_name = @user
  AND c.is_internal = 0
  AND c.id > @since
  AND c.author <> @authorSelf
ORDER BY c.id ASC
LIMIT 20";

        await using var conn = new MySqlConnection(ConnectionString);
        await conn.OpenAsync();
        await using var cmd = new MySqlCommand(sql, conn);
        cmd.Parameters.AddWithValue("@user", userName);
        cmd.Parameters.AddWithValue("@since", sinceId);
        cmd.Parameters.AddWithValue("@authorSelf", userName);

        await using var reader = await cmd.ExecuteReaderAsync();
        while (await reader.ReadAsync())
        {
            list.Add(new TicketReplyNotification
            {
                CommentId = reader.GetInt32(0),
                TicketId = reader.GetInt32(1),
                TicketSubject = reader.IsDBNull(2) ? "" : reader.GetString(2),
                Author = reader.IsDBNull(3) ? "" : reader.GetString(3),
                Body = reader.IsDBNull(4) ? "" : reader.GetString(4),
                CreatedAt = reader.GetDateTime(5),
            });
        }
        return list;
    }

    public static async Task<int> GetMaxCommentIdAsync()
    {
        if (!IsConfigured) return 0;
        try
        {
            await using var conn = new MySqlConnection(ConnectionString);
            await conn.OpenAsync();
            await using var cmd = new MySqlCommand("SELECT COALESCE(MAX(id), 0) FROM ticket_comments", conn);
            var r = await cmd.ExecuteScalarAsync();
            return Convert.ToInt32(r);
        }
        catch { return 0; }
    }

    private static string Truncate(string value, int max) =>
        string.IsNullOrEmpty(value) ? "" : (value.Length <= max ? value : value.Substring(0, max));
}
