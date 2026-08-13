using System;
using System.Collections.Generic;
using System.IO;
using System.Reflection;
using System.Text.Json;
using DockLite.Models;

namespace DockLite.Services;

public static class ConfigService
{
    private static readonly string ConfigPath = Path.Combine(
        Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location) ?? AppContext.BaseDirectory,
        "apps.json");

    public static string ConfigFilePath => ConfigPath;

    private static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true
    };

    public static DockConfig Load() => LoadPosteConfig();

    /// <summary>
    /// Enregistre la configuration dans « apps.json », à côté de l'exécutable.
    ///
    /// ── POURQUOI CE N'EST PLUS UNE SYNCHRONISATION SERVEUR ────────────────────
    /// Le code d'origine n'écrivait RIEN localement : il poussait les préférences
    /// vers un backoffice, et les relisait au démarrage suivant. Sans ce serveur,
    /// tout réglage de l'agent — une application ajoutée, la barre déplacée —
    /// serait perdu à la fermeture, sans le moindre message. Le dock donnerait
    /// l'impression d'oublier.
    ///
    /// L'écriture locale suppose que le dossier d'installation soit accessible en
    /// écriture : c'est la raison pour laquelle l'installeur pose le logiciel sous
    /// ProgramData et non sous Program Files, comme la station blanche.
    /// </summary>
    public static void Save(DockConfig config)
    {
        try
        {
            var dir = Path.GetDirectoryName(ConfigPath);
            if (!string.IsNullOrEmpty(dir)) Directory.CreateDirectory(dir);
            File.WriteAllText(ConfigPath, JsonSerializer.Serialize(config, Options));
        }
        catch { }
    }

    // ---- internals ----

    private static DockConfig LoadPosteConfig()
    {
        try
        {
            if (File.Exists(ConfigPath))
            {
                var json = File.ReadAllText(ConfigPath);
                var cfg = JsonSerializer.Deserialize<DockConfig>(json, Options);
                if (cfg != null) return cfg;
            }
        }
        catch { }

        var fresh = CreateDefault();
        try
        {
            var dir = Path.GetDirectoryName(ConfigPath);
            if (!string.IsNullOrEmpty(dir)) Directory.CreateDirectory(dir);
            File.WriteAllText(ConfigPath, JsonSerializer.Serialize(fresh, Options));
        }
        catch { }
        return fresh;
    }

    private static DockConfig CreateDefault()
    {
        var cfg = new DockConfig();
        var system32 = Environment.GetFolderPath(Environment.SpecialFolder.System);
        var windir = Environment.GetFolderPath(Environment.SpecialFolder.Windows);

        foreach (var item in AppDiscovery.Discover())
            cfg.Items.Add(item);

        TryAdd(cfg, "Explorateur", Path.Combine(windir, "explorer.exe"));
        TryAdd(cfg, "Bloc-notes", Path.Combine(system32, "notepad.exe"));
        TryAdd(cfg, "Calculatrice", Path.Combine(system32, "calc.exe"));
        TryAdd(cfg, "Invite de commandes", Path.Combine(system32, "cmd.exe"));

        return cfg;
    }

    private static void TryAdd(DockConfig cfg, string name, string path)
    {
        if (File.Exists(path))
            cfg.Items.Add(new DockItem { Name = name, Path = path });
    }
}
