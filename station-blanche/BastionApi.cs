using System.Net.Http;
using System.Net.Security;
using System.Text.Json;

namespace Bastion.StationBlanche;

/// <summary>
/// Remontée des analyses vers la console Bastion.
///
/// PRINCIPE : la remontée ne doit JAMAIS gêner l'analyse. Si la passerelle est
/// injoignable, l'agent voit quand même son verdict — c'est lui qui compte. La station
/// signale simplement que la trace n'est pas partie.
/// </summary>
public sealed class BastionApi
{
    private readonly Config _cfg;
    private readonly HttpClient _http;

    public BastionApi(Config cfg)
    {
        _cfg = cfg;
        var h = new HttpClientHandler();
        if (cfg.AccepterCertificatInterne)
        {
            // Le certificat de Bastion est émis par son autorité interne, inconnue d'un
            // poste hors domaine. Sans cela, toute remontée échouerait sur une erreur de
            // certificat. Compromis assumé sur un réseau maîtrisé ; seul un résultat
            // d'analyse transite, aucun secret.
            h.ServerCertificateCustomValidationCallback =
                (_, _, _, _) => true;
        }
        _http = new HttpClient(h) { Timeout = TimeSpan.FromSeconds(8) };
        _http.DefaultRequestHeaders.Add("Authorization", "Bearer " + cfg.Jeton);
    }

    /// <summary>
    /// Dépose un résultat. Rend null si tout s'est bien passé, sinon le motif d'échec —
    /// à afficher, jamais à faire échouer l'analyse.
    /// </summary>
    public async Task<string?> RemonterAsync(Support s, Resultat r, int nbFichiers, CancellationToken jeton)
    {
        if (!_cfg.RemonteeActive) return "Aucune passerelle configurée : analyse non tracée.";

        var detail = r.Menaces.Count > 0
            ? string.Join("\n", r.Menaces.Select(m => $"{m.Nom} — {m.Fichier}"))
            : (r.Erreur ?? "");

        var champs = new Dictionary<string, string>
        {
            ["action"]    = "station.report",
            ["poste"]     = Environment.MachineName,
            ["operateur"] = Environment.UserName,
            ["support"]   = $"{s.Lettre} {s.Materiel} {(string.IsNullOrWhiteSpace(s.Nom) ? "" : "« " + s.Nom + " »")} ({Support.Fmt(s.Taille)})".Trim(),
            ["menaces"]   = r.NbMenaces.ToString(),
            ["fichiers"]  = nbFichiers.ToString(),
            ["abouti"]    = r.Abouti ? "1" : "0",
            ["detail"]    = detail,
        };

        try
        {
            var url = _cfg.Passerelle.TrimEnd('/') + "/api.php";
            using var rep = await _http.PostAsync(url, new FormUrlEncodedContent(champs), jeton);
            var corps = await rep.Content.ReadAsStringAsync(jeton);
            if (!rep.IsSuccessStatusCode)
                return rep.StatusCode switch
                {
                    System.Net.HttpStatusCode.Unauthorized => "Jeton refusé par la passerelle : analyse non tracée.",
                    System.Net.HttpStatusCode.Forbidden    => "Ce jeton n'autorise pas le dépôt : analyse non tracée.",
                    _ => $"La passerelle a répondu {(int)rep.StatusCode} : analyse non tracée.",
                };
            using var doc = JsonDocument.Parse(corps);
            return doc.RootElement.TryGetProperty("ok", out var ok) && ok.GetBoolean()
                ? null
                : "Réponse inattendue de la passerelle : analyse non tracée.";
        }
        catch (TaskCanceledException) { return "Passerelle injoignable (délai dépassé) : analyse non tracée."; }
        catch (Exception ex)          { return "Passerelle injoignable : analyse non tracée. " + ex.Message; }
    }
}
