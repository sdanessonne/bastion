using System;
using System.IO;
using System.Text.Json;

namespace DockLite.Services;

public class NotificationState
{
    public int LastSeenCommentId { get; set; }
}

public static class NotificationStateService
{
    private static readonly string StateDir =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DockPolice");

    private static readonly string StatePath = Path.Combine(StateDir, "notifications.json");

    public static NotificationState Load()
    {
        try
        {
            if (File.Exists(StatePath))
            {
                var json = File.ReadAllText(StatePath);
                return JsonSerializer.Deserialize<NotificationState>(json) ?? new NotificationState();
            }
        }
        catch { }
        return new NotificationState();
    }

    public static void Save(NotificationState state)
    {
        try
        {
            Directory.CreateDirectory(StateDir);
            File.WriteAllText(StatePath, JsonSerializer.Serialize(state));
        }
        catch { }
    }
}
