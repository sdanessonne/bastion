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
        _http = Client(cfg, TimeSpan.FromSeconds(8));
        // Le rapatriement de la base est une autre affaire : main.cvd pèse ~170 Mo, ce qui
        // dépasse largement les 8 secondes accordées à un dépôt de résultat. Un client à
        // part, sinon toute mise à jour expirerait — et la station tournerait indéfiniment
        // avec la base de son installation.
        _telechargement = Client(cfg, TimeSpan.FromMinutes(20));
    }

    /// <summary>
    /// Un client, avec SON handler. Deux HttpClient ne doivent pas se partager un handler :
    /// le premier disposé emporte le handler, et le second tombe en panne sans prévenir.
    /// </summary>
    private static HttpClient Client(Config cfg, TimeSpan delai)
    {
        var h = new HttpClientHandler();
        if (cfg.AccepterCertificatInterne)
        {
            // Le certificat de Bastion est émis par son autorité interne, inconnue d'un
            // poste hors domaine. Sans cela, toute remontée échouerait sur une erreur de
            // certificat. Compromis assumé sur un réseau maîtrisé ; seul un résultat
            // d'analyse transite, aucun secret.
            h.ServerCertificateCustomValidationCallback = (_, _, _, _) => true;
        }
        var c = new HttpClient(h) { Timeout = delai };
        // En-tête conservé parce que c'est la façon correcte de faire — mais il NE SUFFIT
        // PAS : voir Champs(). Il sert le jour où le vhost sera corrigé.
        c.DefaultRequestHeaders.Add("Authorization", "Bearer " + cfg.Jeton);
        return c;
    }

    /// <summary>
    /// Champs de base de toute requête, jeton compris.
    ///
    /// ── POURQUOI LE JETON VOYAGE DANS LE CORPS, ET PAS SEULEMENT EN EN-TÊTE ─────
    /// MESURÉ contre la vraie passerelle : « Authorization: Bearer <jeton> » rend 401,
    /// « token=<jeton> » rend 200. Apache ne transmet pas l'en-tête Authorization à PHP
    /// sans directive explicite (CGIPassAuth / SetEnvIf), et le vhost de Bastion n'en a
    /// aucune. Un client qui ne s'appuierait que sur l'en-tête échouerait sur TOUTES les
    /// passerelles déjà installées — ce qui était le cas de celui-ci.
    ///
    /// Le jeton part en POST et jamais dans l'URL : une chaîne de requête atterrit dans les
    /// journaux d'accès d'Apache, dans ceux des mandataires, et y reste. Un corps de POST,
    /// non.
    /// </summary>
    private Dictionary<string, string> Champs(string action) => new()
    {
        ["action"] = action,
        ["token"]  = _cfg.Jeton,
    };

    private readonly HttpClient _telechargement;

    private sealed record FichierBase(string nom, long taille, long date, string sha256);

    /// <summary>
    /// Rapatrie la base virale ClamAV depuis la passerelle vers <paramref name="dossier"/>.
    /// Ne retélécharge que ce qui a changé.
    /// </summary>
    public async Task<(bool ok, string message)> SynchroniserBaseAsync(string dossier, CancellationToken jeton)
    {
        if (!_cfg.RemonteeActive)
            return (false, "Aucune passerelle configurée : la base virale ne peut pas être mise à jour.");

        var url = _cfg.Passerelle.TrimEnd('/') + "/api.php";
        List<FichierBase> inventaire;
        try
        {
            using var rep = await _telechargement.PostAsync(url,
                new FormUrlEncodedContent(Champs("station.clamdb")), jeton);
            if (!rep.IsSuccessStatusCode)
                return (false, $"La passerelle a répondu {(int) rep.StatusCode} : base virale inchangée.");
            using var doc = JsonDocument.Parse(await rep.Content.ReadAsStringAsync(jeton));
            if (!doc.RootElement.TryGetProperty("base", out var arr))
                return (false, "Réponse inattendue de la passerelle : base virale inchangée.");
            inventaire = arr.EnumerateArray().Select(e => new FichierBase(
                e.GetProperty("nom").GetString() ?? "",
                e.GetProperty("taille").GetInt64(),
                e.GetProperty("date").GetInt64(),
                e.GetProperty("sha256").GetString() ?? "")).ToList();

            // La passerelle sait POURQUOI elle n'a rien à donner (ClamAV absent, ou base
            // illisible par Apache). Reprendre son motif épargne à l'exploitant de fouiller
            // deux machines pour un problème qui se règle en une commande sur la première.
            if (inventaire.Count == 0)
                return (false, doc.RootElement.TryGetProperty("motif", out var mo) && mo.ValueKind == JsonValueKind.String
                    ? mo.GetString()!
                    : "La passerelle n'a aucune base virale à fournir — ClamAV y est-il installé ?");
        }
        catch (TaskCanceledException) { return (false, "Passerelle injoignable (délai dépassé) : base virale inchangée."); }
        catch (Exception ex) { return (false, "Passerelle injoignable : base virale inchangée. " + ex.Message); }

        Directory.CreateDirectory(dossier);
        int recus = 0;
        foreach (var f in inventaire)
        {
            var cible = Path.Combine(dossier, f.nom);
            // On compare l'empreinte, pas la taille ni la date : un fichier tronqué par un
            // téléchargement coupé a la bonne date et une taille plausible. Seul le SHA-256
            // dit qu'on a bien le même fichier que la passerelle.
            if (File.Exists(cible) && await EmpreinteAsync(cible, jeton) == f.sha256) continue;

            var tmp = cible + ".part";
            try
            {
                // « file » reste dans l'URL, seul le JETON passe par le corps.
                //
                // Ce n'est pas un détail de style : les passerelles déjà installées ne lisent
                // « file » qu'en GET. Le poster faisait retomber la requête sur l'inventaire —
                // 468 octets de JSON au lieu de 89 Mo — et la station annonçait « fichier
                // arrivé abîmé », ce qui envoyait chercher un problème de réseau inexistant.
                // Un nom de fichier n'est pas un secret ; le jeton, si. Seul lui doit rester
                // hors des journaux d'accès.
                var corps = new FormUrlEncodedContent(new Dictionary<string, string> { ["token"] = _cfg.Jeton });
                var lien = url + "?action=station.clamdb&file=" + Uri.EscapeDataString(f.nom);
                // « ResponseHeadersRead » : on écrit au fil de l'eau plutôt que de charger
                // 89 Mo en mémoire avant d'ouvrir le fichier de destination.
                using var req = new HttpRequestMessage(HttpMethod.Post, lien) { Content = corps };
                using (var rep = await _telechargement.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, jeton))
                {
                    if (!rep.IsSuccessStatusCode)
                        return (false, $"Téléchargement de {f.nom} refusé ({(int) rep.StatusCode}) : base virale incomplète.");
                    using var src = await rep.Content.ReadAsStreamAsync(jeton);
                    using var dst = File.Create(tmp);
                    await src.CopyToAsync(dst, jeton);
                }

                if (await EmpreinteAsync(tmp, jeton) != f.sha256)
                {
                    File.Delete(tmp);
                    return (false, $"{f.nom} est arrivé abîmé : base virale inchangée.");
                }

                // La date de la passerelle est reportée sur le fichier : c'est elle qui
                // renseigne la fraîcheur RÉELLE des signatures. L'heure du téléchargement
                // ferait passer une base vieille de trois mois pour toute neuve.
                File.SetLastWriteTimeUtc(tmp, DateTimeOffset.FromUnixTimeSeconds(f.date).UtcDateTime);
                // Remplacement en dernier : tant que le fichier n'est pas complet ET
                // vérifié, l'ancienne base reste en place et utilisable.
                File.Move(tmp, cible, overwrite: true);
                recus++;
            }
            catch (OperationCanceledException) { try { File.Delete(tmp); } catch { } throw; }
            catch (Exception ex)
            {
                try { File.Delete(tmp); } catch { }
                return (false, $"Échec sur {f.nom} : {ex.Message}");
            }
        }

        var quand = DateTimeOffset.FromUnixTimeSeconds(inventaire.Max(f => f.date)).LocalDateTime;
        return recus == 0
            ? (true, $"Base virale déjà à jour ({quand:dd/MM/yyyy HH:mm}).")
            : (true, $"Base virale mise à jour depuis Bastion : {recus} fichier(s), signatures du {quand:dd/MM/yyyy HH:mm}.");
    }

    /// <summary>
    /// Demande à la passerelle si ces identifiants autorisent la fermeture de la station.
    ///
    /// La vérification se fait sur la PASSERELLE, jamais ici : la station est un poste en
    /// libre accès, et tout secret qu'elle porterait finirait par être lu. Elle ne connaît
    /// aucun mot de passe, elle pose une question et reçoit oui ou non.
    ///
    /// « exige2fa » rend vrai quand le compte réclame un code : l'écran peut alors le
    /// demander sans l'imposer aux comptes qui n'en ont pas.
    /// </summary>
    public async Task<(bool ok, string message, bool exige2fa)> AuthentifierAsync(
        string utilisateur, string motDePasse, string code, CancellationToken jeton)
    {
        if (!_cfg.RemonteeActive)
            return (false, "Aucune passerelle configurée : impossible de vérifier des identifiants.", false);

        var champs = Champs("station.auth");
        champs["user"] = utilisateur;
        champs["pass"] = motDePasse;
        champs["code"] = code;
        champs["poste"] = Environment.MachineName;   // pour le journal d'audit de la passerelle

        try
        {
            using var rep = await _http.PostAsync(_cfg.Passerelle.TrimEnd('/') + "/api.php",
                new FormUrlEncodedContent(champs), jeton);
            var corps = await rep.Content.ReadAsStringAsync(jeton);
            using var doc = JsonDocument.Parse(corps);

            if (doc.RootElement.TryGetProperty("ok", out var ok) && ok.GetBoolean())
                return (true, "", false);

            var msg = doc.RootElement.TryGetProperty("error", out var e) && e.ValueKind == JsonValueKind.String
                ? e.GetString()! : "Identifiants refusés.";
            var deux = doc.RootElement.TryGetProperty("totp", out var t) && t.ValueKind == JsonValueKind.True;
            return (false, msg, deux);
        }
        catch (TaskCanceledException) { return (false, "Passerelle injoignable : impossible de vérifier les identifiants.", false); }
        catch (Exception ex) { return (false, "Passerelle injoignable : " + ex.Message, false); }
    }

    /// <summary>
    /// Éprouve la passerelle et le jeton, sans rien enregistrer.
    ///
    /// Indispensable à l'écran de réglages : un jeton mal recopié ne se verrait autrement
    /// qu'à la première clé insérée, des jours plus tard, sous la forme d'un « analyse non
    /// tracée » que personne ne relie à une faute de frappe.
    /// </summary>
    public async Task<(bool ok, string message)> TesterAsync(CancellationToken jeton)
    {
        if (string.IsNullOrWhiteSpace(_cfg.Passerelle)) return (false, "Indiquez l'adresse de la passerelle.");
        if (string.IsNullOrWhiteSpace(_cfg.Jeton)) return (false, "Indiquez le jeton.");

        try
        {
            using var rep = await _http.PostAsync(_cfg.Passerelle.TrimEnd('/') + "/api.php",
                new FormUrlEncodedContent(Champs("station.clamdb")), jeton);
            var corps = await rep.Content.ReadAsStringAsync(jeton);

            if (rep.StatusCode == System.Net.HttpStatusCode.Unauthorized)
                return (false, "Jeton refusé. Vérifiez que vous avez copié celui des STATIONS "
                             + "(console → Antivirus), et non celui d'administration.");
            if (rep.StatusCode == System.Net.HttpStatusCode.Forbidden)
                return (false, "Ce jeton n'autorise pas les stations. C'est probablement le jeton "
                             + "d'administration : prenez celui du panneau « Stations blanches ».");
            if (!rep.IsSuccessStatusCode)
                return (false, $"La passerelle a répondu {(int) rep.StatusCode}. Est-ce bien l'adresse de la console ?");

            using var doc = JsonDocument.Parse(corps);
            if (!doc.RootElement.TryGetProperty("base", out var arr))
                return (false, "Réponse inattendue : cette adresse est-elle bien une console Bastion ?");

            var n = arr.GetArrayLength();
            if (n == 0)
                return (true, "Passerelle joignable et jeton accepté — mais elle n'a AUCUNE base virale à "
                            + "fournir. " + (doc.RootElement.TryGetProperty("motif", out var mo)
                                && mo.ValueKind == JsonValueKind.String ? mo.GetString() : ""));

            var d = doc.RootElement.TryGetProperty("date_base", out var db) && db.TryGetInt64(out var t) && t > 0
                ? DateTimeOffset.FromUnixTimeSeconds(t).LocalDateTime.ToString("dd/MM/yyyy HH:mm")
                : "date inconnue";
            return (true, $"Passerelle joignable, jeton accepté. Base virale du {d} ({n} fichier(s)).");
        }
        catch (TaskCanceledException) { return (false, "Passerelle injoignable : délai dépassé. Vérifiez l'adresse et le réseau."); }
        catch (Exception ex) { return (false, "Passerelle injoignable : " + ex.Message); }
    }

    private static async Task<string> EmpreinteAsync(string chemin, CancellationToken jeton)
    {
        using var f = File.OpenRead(chemin);
        var h = await System.Security.Cryptography.SHA256.HashDataAsync(f, jeton);
        return Convert.ToHexString(h).ToLowerInvariant();
    }

    /// <summary>
    /// Dépose un résultat. Rend null si tout s'est bien passé, sinon le motif d'échec —
    /// à afficher, jamais à faire échouer l'analyse.
    /// </summary>
    public async Task<string?> RemonterAsync(Support s, Verdict r, int nbFichiers, CancellationToken jeton)
    {
        if (!_cfg.RemonteeActive) return "Aucune passerelle configurée : analyse non tracée.";

        // Le détail porte les menaces ET les moteurs qui ont tourné, avec la date de leurs
        // signatures. Sans cela, un « 0 menace » dans la console ne dirait pas AVEC QUOI le
        // support a été passé — et une trace qu'on ne peut pas interpréter ne vaut rien.
        var lignes = r.Menaces.Select(m => $"{m.Nom} — {m.Fichier}").ToList();
        lignes.AddRange(r.Ecueils.Select(e => "⚠️ " + e));
        lignes.Add("Moteurs : " + string.Join(", ", r.Analyses.Select(a =>
        {
            var e = a.moteur.LireEtat();
            return $"{a.moteur.Nom} {e.Version}".Trim()
                 + (e.Signatures.HasValue ? $" (sig. {e.Signatures:dd/MM/yyyy})" : " (sig. inconnues)");
        })));
        var detail = string.Join("\n", lignes);

        var champs = Champs("station.report");
        foreach (var (k, v) in new Dictionary<string, string>
        {
            ["poste"]     = Environment.MachineName,
            ["operateur"] = Environment.UserName,
            ["support"]   = $"{s.Lettre} {s.Materiel} {(string.IsNullOrWhiteSpace(s.Nom) ? "" : "« " + s.Nom + " »")} ({Support.Fmt(s.Taille)})".Trim(),
            ["menaces"]   = r.NbMenaces.ToString(),
            ["fichiers"]  = nbFichiers.ToString(),
            ["abouti"]    = r.Abouti ? "1" : "0",
            ["detail"]    = detail,
        }) champs[k] = v;

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
