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

    public static DockConfig Load()
    {
        var cfg = LoadPosteConfig();
        ApplyUserPrefs(cfg);
        return cfg;
    }

    /// <summary>
    /// Écrit uniquement les prefs utilisateur (cache local immédiat + push serveur async).
    /// apps.json n'est PAS réécrit : c'est la config poste, administrée séparément.
    /// </summary>
    public static void Save(DockConfig config)
    {
        var prefs = UserDockPrefs.From(config);
        UserPrefsSync.SaveCache(prefs);
        UserPrefsSync.PushUserAsync(config.ApiBaseUrl, config.ApiKey, prefs, Environment.MachineName);
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

    /// <summary>
    /// Stratégie de résolution des prefs utilisateur :
    ///  1. Serveur : config perso de cet utilisateur (cas nominal)
    ///  2. Cache local : mode hors-ligne, même utilisateur déjà passé ici
    ///  3. Serveur : dock par défaut configuré dans le backoffice (nouvel utilisateur)
    ///  4. Fallback : AppDiscovery (premier lancement sans réseau ni cache)
    /// </summary>
    private static void ApplyUserPrefs(DockConfig cfg)
    {
        var serverPrefs = UserPrefsSync.FetchUser(cfg.ApiBaseUrl, cfg.ApiKey);
        if (serverPrefs != null)
        {
            serverPrefs.ApplyTo(cfg);
            UserPrefsSync.SaveCache(serverPrefs);
            return;
        }

        var cachePrefs = UserPrefsSync.LoadCache();
        if (cachePrefs != null)
        {
            cachePrefs.ApplyTo(cfg);
            return;
        }

        var defaultPrefs = UserPrefsSync.FetchDefault(cfg.ApiBaseUrl, cfg.ApiKey);
        if (defaultPrefs != null)
        {
            defaultPrefs.ApplyTo(cfg);
            return;
        }

        cfg.Items = new List<DockItem>();
        foreach (var it in AppDiscovery.Discover()) cfg.Items.Add(it);
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
