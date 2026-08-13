using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Threading.Tasks;
using DockLite.Models;

namespace DockLite.Services;

/// <summary>
/// Queue locale pour les tickets qui n'ont pas pu être envoyés (MySQL indisponible).
/// Persistée dans %LOCALAPPDATA%\DockPolice\pending-tickets.json. Le dock retente
/// périodiquement de la flusher.
/// </summary>
public static class OfflineTicketQueue
{
    private static readonly object _lock = new();

    private static readonly string Dir =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DockPolice");
    private static readonly string FilePath = Path.Combine(Dir, "pending-tickets.json");

    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true,
    };

    public class PendingTicket
    {
        public string LocalId { get; set; } = Guid.NewGuid().ToString("N");
        public DateTime QueuedAt { get; set; } = DateTime.Now;
        public int Attempts { get; set; }
        public string LastError { get; set; } = "";
        public SupportTicket Ticket { get; set; } = new();
    }

    /// <summary>Notifié à chaque changement de la queue (UI peut s'y abonner).</summary>
    public static event Action? Changed;

    public static int Count
    {
        get { lock (_lock) return Load().Count; }
    }

    public static List<PendingTicket> Snapshot()
    {
        lock (_lock) return Load();
    }

    public static void Enqueue(SupportTicket t, string error = "")
    {
        lock (_lock)
        {
            var list = Load();
            list.Add(new PendingTicket { Ticket = t, LastError = error });
            Save(list);
        }
        Changed?.Invoke();
    }

    /// <summary>
    /// Tente de renvoyer tous les tickets en attente. Renvoie le nombre de tickets
    /// qui ont été envoyés avec succès lors de cet appel.
    /// </summary>
    public static async Task<int> FlushAsync()
    {
        List<PendingTicket> snap;
        lock (_lock) snap = Load();
        if (snap.Count == 0) return 0;

        var sent = 0;
        var remaining = new List<PendingTicket>();
        foreach (var p in snap)
        {
            try
            {
                p.Attempts++;
                int id = await TicketService.CreateTicketAsync(p.Ticket);
                if (id > 0) { sent++; continue; }
                remaining.Add(p);
            }
            catch (Exception ex)
            {
                p.LastError = ex.Message;
                remaining.Add(p);
            }
        }

        lock (_lock) Save(remaining);
        if (sent > 0) Changed?.Invoke();
        return sent;
    }

    public static void Drop(string localId)
    {
        lock (_lock)
        {
            var list = Load().Where(p => p.LocalId != localId).ToList();
            Save(list);
        }
        Changed?.Invoke();
    }

    // ---- internals ----

    private static List<PendingTicket> Load()
    {
        try
        {
            if (!File.Exists(FilePath)) return new List<PendingTicket>();
            var json = File.ReadAllText(FilePath);
            return JsonSerializer.Deserialize<List<PendingTicket>>(json, JsonOpts) ?? new List<PendingTicket>();
        }
        catch { return new List<PendingTicket>(); }
    }

    private static void Save(List<PendingTicket> list)
    {
        try
        {
            Directory.CreateDirectory(Dir);
            File.WriteAllText(FilePath, JsonSerializer.Serialize(list, JsonOpts));
        }
        catch { }
    }
}
