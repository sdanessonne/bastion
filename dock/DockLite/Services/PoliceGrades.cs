using System.Collections.Generic;

namespace DockLite.Services;

/// <summary>
/// Catalogue officiel des grades de la Police Nationale française
/// (corps actifs + non-actifs + PTS + administratifs + techniques + réserve).
/// Synchronisé avec includes/police-grades.php côté backoffice.
/// </summary>
public static class PoliceGrades
{
    /// <summary>Renvoie tous les grades regroupés par corps.</summary>
    public static readonly List<(string Corps, string[] Grades)> ByCorps = new()
    {
        ("Corps d'encadrement et d'application (CEA)", new[]
        {
            "Élève gardien de la paix",
            "Gardien de la paix",
            "Gardien de la paix (échelon excep.)",
            "Brigadier de police",
            "Brigadier-chef de police",
            "Major de police",
            "Major (échelon exceptionnel)",
            "Responsable d'unité locale (RULP)",
        }),
        ("Corps de commandement (CC)", new[]
        {
            "Élève officier de police",
            "Lieutenant de police",
            "Lieutenant 1re classe",
            "Capitaine de police",
            "Commandant de police",
            "Commandant divisionnaire",
            "Commandant à l'emploi fonctionnel",
        }),
        ("Corps de conception et de direction (CCD)", new[]
        {
            "Élève commissaire de police",
            "Commissaire de police",
            "Commissaire divisionnaire",
            "Commissaire général",
            "Inspecteur général",
            "Contrôleur général",
            "Directeur des services actifs",
        }),
        ("Policiers adjoints (PA)", new[]
        {
            "Cadet de la République",
            "Policier adjoint",
            "Policier adjoint chef",
        }),
        ("Personnels administratifs", new[]
        {
            "Adjoint administratif",
            "Adjoint administratif principal 2e classe",
            "Adjoint administratif principal 1re classe",
            "Secrétaire administratif",
            "Secrétaire administratif classe supérieure",
            "Secrétaire administratif classe except.",
            "Attaché d'administration de l'État",
            "Attaché principal",
            "Attaché hors classe",
            "Directeur d'administration",
        }),
        ("Personnels techniques (SIC)", new[]
        {
            "Adjoint technique",
            "Adjoint technique principal 2e classe",
            "Adjoint technique principal 1re classe",
            "Technicien SIC",
            "Technicien principal SIC",
            "Technicien chef SIC",
            "Ingénieur SIC",
            "Ingénieur principal SIC",
            "Ingénieur en chef SIC",
        }),
        ("Police technique et scientifique (PTS)", new[]
        {
            "Agent spécialisé de PTS (ASPTS)",
            "Technicien de PTS (TPTS)",
            "Technicien principal de PTS",
            "Ingénieur de PTS (IPTS)",
            "Ingénieur principal de PTS",
            "Ingénieur en chef de PTS",
        }),
        ("Réserve / Autres", new[]
        {
            "Réserviste opérationnel",
            "Réserviste citoyen",
            "Stagiaire",
        }),
    };

    /// <summary>Liste plate de tous les grades (~53 entrées).</summary>
    public static IEnumerable<string> All()
    {
        foreach (var c in ByCorps)
            foreach (var g in c.Grades) yield return g;
    }

    /// <summary>
    /// Liste avec en-tête de groupe (entrée non sélectionnable).
    /// Format : "── Corps d'encadrement (CEA) ──" puis "  Gardien de la paix" etc.
    /// </summary>
    public static List<string> AllWithHeaders()
    {
        var list = new List<string>();
        foreach (var (corps, grades) in ByCorps)
        {
            list.Add("── " + corps + " ──");
            foreach (var g in grades) list.Add("  " + g);
        }
        return list;
    }

    /// <summary>True si la ligne de la liste avec en-têtes est un en-tête de groupe (non sélectionnable).</summary>
    public static bool IsHeader(string item) => item != null && item.StartsWith("── ");

    /// <summary>Nettoie un grade (retire les espaces de tête ajoutés pour l'indentation).</summary>
    public static string Clean(string item) => (item ?? "").TrimStart();
}
