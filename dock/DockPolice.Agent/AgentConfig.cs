using System;
using System.IO;
using System.Reflection;
using System.Text.Json;

namespace DockPolice.Agent;

public class AgentConfig
{
    public string ApiBaseUrl { get; set; } = "";
    public string ApiKey { get; set; } = "";
    public int TelemetryIntervalSeconds { get; set; } = 10;
    public int CommandPollIntervalSeconds { get; set; } = 8;
    public int StaticSnapshotEveryHours { get; set; } = 6;

    /// <summary>
    /// Chemin vers DockPolice.exe que le service lancera dans chaque session utilisateur.
    /// Vide = recherche dans Program Files\DockPolice\.
    /// </summary>
    public string DockExePath { get; set; } = "";

    public static AgentConfig Load()
    {
        var dir = Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location)
                  ?? AppContext.BaseDirectory;
        var path = Path.Combine(dir, "agent.json");
        if (!File.Exists(path))
            throw new FileNotFoundException("agent.json introuvable à côté de l'exécutable.", path);

        var json = File.ReadAllText(path);
        return JsonSerializer.Deserialize<AgentConfig>(json, new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = true
        }) ?? throw new Exception("agent.json vide ou invalide.");
    }
}
