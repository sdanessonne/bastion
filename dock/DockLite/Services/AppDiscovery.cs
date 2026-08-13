using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using DockLite.Models;

namespace DockLite.Services;

public static class AppDiscovery
{
    private record Signature(string Display, string[] Patterns);

    private static readonly Signature[] PoliceApps = new[]
    {
        new Signature("LRPPN", new[] { "lrppn" }),
        new Signature("LRP", new[] { "logiciel de rédaction", "logiciel de redaction", "lrp " }),
        new Signature("CHEOPS", new[] { "cheops" }),
        new Signature("ARDOISE", new[] { "ardoise" }),
        new Signature("NEO", new[] { "neo police", "neo gendarmerie", "neo2", "neo " }),
        new Signature("Pulsar", new[] { "pulsar" }),
        new Signature("Athéna", new[] { "athena", "athéna" }),
        new Signature("Mercure", new[] { "mercure" }),
        new Signature("PVe", new[] { "pvé", "p.v.e", "proces-verbal electronique", "pv électronique" }),
        new Signature("GEVI", new[] { "gevi" }),
        new Signature("Agorha", new[] { "agorha" }),
        new Signature("ANACRIM", new[] { "anacrim" }),
        new Signature("ANABEL", new[] { "anabel" }),
        new Signature("TAJ", new[] { "traitement antécédents", "traitement antecedents", " taj " }),
        new Signature("FPR", new[] { "fichier personnes recherchees", "fichier des personnes recherchées" }),
        new Signature("FVV", new[] { "fichier véhicules volés", "fichier vehicules voles" }),
        new Signature("SIV", new[] { "système d'immatriculation", "systeme d'immatriculation" }),
        new Signature("LUPIN", new[] { "lupin" }),
        new Signature("SALVAC", new[] { "salvac" }),
        new Signature("ICARE", new[] { "icare" }),
        new Signature("ACROPOL", new[] { "acropol" }),
        new Signature("OSIRIS", new[] { "osiris" }),
        new Signature("ORIGINE", new[] { "origine" }),
        new Signature("STIC", new[] { "stic " }),
        new Signature("JUDEX", new[] { "judex" }),
    };

    public static List<DockItem> Discover()
    {
        var result = new Dictionary<string, DockItem>(StringComparer.OrdinalIgnoreCase);

        var menus = new[]
        {
            Environment.GetFolderPath(Environment.SpecialFolder.CommonStartMenu),
            Environment.GetFolderPath(Environment.SpecialFolder.StartMenu)
        };

        foreach (var menu in menus)
        {
            if (string.IsNullOrEmpty(menu) || !Directory.Exists(menu)) continue;

            // IgnoreInaccessible : skip silencieux sur les sous-dossiers dont l'user
            // n'a pas le droit de lecture (ex: "Start Menu\Programmes" héritée par un
            // profil roaming sans ACL complète). Sans ça, Directory.EnumerateFiles
            // lève une UnauthorizedAccessException au milieu de l'énumération.
            var options = new EnumerationOptions
            {
                RecurseSubdirectories = true,
                IgnoreInaccessible    = true,
                AttributesToSkip      = FileAttributes.Hidden | FileAttributes.System,
            };

            IEnumerable<string> shortcuts;
            try
            {
                shortcuts = Directory.EnumerateFiles(menu, "*.lnk", options);
            }
            catch { continue; }

            foreach (var lnk in SafeEnumerate(shortcuts))
            {
                var name = Path.GetFileNameWithoutExtension(lnk);
                if (string.IsNullOrWhiteSpace(name)) continue;

                foreach (var sig in PoliceApps)
                {
                    if (result.ContainsKey(sig.Display)) continue;

                    foreach (var pattern in sig.Patterns)
                    {
                        if (name.Contains(pattern, StringComparison.OrdinalIgnoreCase))
                        {
                            result[sig.Display] = new DockItem { Name = sig.Display, Path = lnk };
                            break;
                        }
                    }
                }
            }
        }

        return result.Values.OrderBy(i => i.Name).ToList();
    }

    // Ceinture et bretelles : même avec IgnoreInaccessible, certaines exceptions
    // (ex: PathTooLongException) peuvent remonter. Skip-les sans planter.
    private static IEnumerable<string> SafeEnumerate(IEnumerable<string> source)
    {
        using var e = source.GetEnumerator();
        while (true)
        {
            bool hasNext;
            try { hasNext = e.MoveNext(); }
            catch { break; }
            if (!hasNext) yield break;
            yield return e.Current;
        }
    }

    public static int MergeInto(DockConfig config)
    {
        var existing = new HashSet<string>(
            config.Items.Select(i => i.Path),
            StringComparer.OrdinalIgnoreCase);

        var existingNames = new HashSet<string>(
            config.Items.Select(i => i.Name),
            StringComparer.OrdinalIgnoreCase);

        int added = 0;
        foreach (var item in Discover())
        {
            if (existing.Contains(item.Path) || existingNames.Contains(item.Name)) continue;
            config.Items.Add(item);
            added++;
        }
        return added;
    }
}
