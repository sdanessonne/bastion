using System.Text.Json;

namespace Bastion.StationBlanche;

/// <summary>
/// Réglages de la station, lus dans « station.json » à côté de l'exécutable.
///
/// Un fichier plutôt que des valeurs codées en dur : l'adresse de la passerelle et le
/// jeton changent d'un site à l'autre, et le même exécutable doit servir partout.
/// </summary>
public sealed class Config
{
    /// <summary>Adresse de la console Bastion, ex. https://192.168.182.1:8443</summary>
    public string Passerelle { get; set; } = "";

    /// <summary>
    /// Jeton DÉDIÉ aux stations (pf_settings.station_token), jamais celui d'administration :
    /// une station est un poste en libre accès, physiquement exposé. Ce jeton n'ouvre que
    /// le dépôt de résultats.
    /// </summary>
    public string Jeton { get; set; } = "";

    /// <summary>Plein écran, sans bordure — mode borne.</summary>
    public bool Kiosque { get; set; } = true;

    /// <summary>Afficher le bouton d'extinction.</summary>
    public bool BoutonEteindre { get; set; } = true;

    /// <summary>Mettre à jour les signatures au démarrage puis toutes les 4 h.</summary>
    public bool MajAuto { get; set; } = true;

    /// <summary>
    /// Le certificat de Bastion est signé par son autorité interne, inconnue d'un poste
    /// hors domaine : la station refuserait la connexion. Cette option l'accepte quand
    /// même. À n'utiliser que sur un réseau maîtrisé — c'est un compromis assumé, pas un
    /// détail : elle désactive la vérification du certificat.
    /// </summary>
    public bool AccepterCertificatInterne { get; set; } = true;

    private static string Chemin =>
        Path.Combine(AppContext.BaseDirectory, "station.json");

    public static Config Charger()
    {
        try
        {
            if (File.Exists(Chemin))
                return JsonSerializer.Deserialize<Config>(File.ReadAllText(Chemin),
                    new JsonSerializerOptions { PropertyNameCaseInsensitive = true }) ?? new Config();
        }
        catch { /* fichier illisible : on repart des valeurs par défaut plutôt que de refuser de démarrer */ }
        return new Config();
    }

    /// <summary>Écrit un modèle commenté au premier lancement, pour que le fichier existe.</summary>
    public void EcrireModeleSiAbsent()
    {
        try
        {
            if (File.Exists(Chemin)) return;
            File.WriteAllText(Chemin, JsonSerializer.Serialize(this,
                new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { /* dossier en lecture seule : sans importance, les défauts suffisent */ }
    }

    public bool RemonteeActive => !string.IsNullOrWhiteSpace(Passerelle) && !string.IsNullOrWhiteSpace(Jeton);
}
