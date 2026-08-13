using System;
using System.DirectoryServices;
using System.DirectoryServices.AccountManagement;
using System.Security.Principal;

namespace DockLite.Services;

/// <summary>
/// Récupère les informations de l'utilisateur Windows courant via Active Directory.
/// Tombe en mode dégradé (juste le nom Windows) si AD inaccessible (poste hors domaine).
/// </summary>
public static class ActiveDirectoryService
{
    public class AdUserInfo
    {
        public string  Matricule    { get; set; } = "";   // SAMAccountName / login Windows
        public string  FirstName    { get; set; } = "";
        public string  LastName     { get; set; } = "";
        public string  DisplayName  { get; set; } = "";
        public string  Email        { get; set; } = "";
        public string  Department   { get; set; } = "";   // service AD si renseigné
        public string  Title        { get; set; } = "";   // fonction AD si renseignée
        public byte[]? Thumbnail    { get; set; }          // photo récupérée depuis AD (jpegPhoto/thumbnailPhoto)
        public bool    FromAd       { get; set; }          // false = lookup AD échoué, fallback Windows
    }

    /// <summary>
    /// Récupère les infos de l'utilisateur courant.
    /// 1) tente UserPrincipal.Current (jointure AD si poste sur le domaine)
    /// 2) sinon, fallback sur Environment.UserName + nettoyage best-effort
    /// </summary>
    public static AdUserInfo GetCurrentUser()
    {
        var info = new AdUserInfo
        {
            Matricule = Environment.UserName ?? "",
        };

        try
        {
            using var ctx = new PrincipalContext(ContextType.Domain);
            using var p   = UserPrincipal.FindByIdentity(ctx, Environment.UserName);
            if (p != null)
            {
                info.Matricule    = string.IsNullOrWhiteSpace(p.SamAccountName) ? (Environment.UserName ?? "") : p.SamAccountName;
                info.FirstName    = p.GivenName  ?? "";
                info.LastName     = p.Surname    ?? "";
                info.DisplayName  = p.DisplayName ?? $"{info.FirstName} {info.LastName}".Trim();
                info.Email        = p.EmailAddress ?? "";
                info.FromAd       = true;

                // Récupère la photo depuis l'attribut AD jpegPhoto ou thumbnailPhoto
                try
                {
                    if (p.GetUnderlyingObject() is DirectoryEntry de)
                    {
                        var photo = ReadPhotoBytes(de, "jpegPhoto") ?? ReadPhotoBytes(de, "thumbnailPhoto");
                        if (photo != null && photo.Length > 0) info.Thumbnail = photo;
                    }
                }
                catch
                {
                    // Pas grave : pas de photo AD
                }

                // Service AD si présent
                try
                {
                    if (p.GetUnderlyingObject() is DirectoryEntry de2)
                    {
                        info.Department = de2.Properties["department"]?.Value?.ToString() ?? "";
                        info.Title      = de2.Properties["title"]?.Value?.ToString() ?? "";
                    }
                }
                catch { }
            }
        }
        catch
        {
            // Pas de domaine joignable, on continue en mode dégradé
        }

        // Fallback dégradé si pas d'AD : essaie de deviner Prénom/Nom depuis le SAM
        if (!info.FromAd)
        {
            // SAM courants : "prenom.nom", "prenom_nom", "p.nom", "nom.p"...
            var sam = info.Matricule;
            string norm = sam.Replace('_', '.');
            if (norm.Contains('.'))
            {
                var parts = norm.Split('.', 2);
                info.FirstName = Capitalize(parts[0]);
                info.LastName  = Capitalize(parts[1]);
            }
            else
            {
                info.FirstName = Capitalize(sam);
            }
            info.DisplayName = $"{info.FirstName} {info.LastName}".Trim();
        }

        // Email : si AD ne le donne pas, on le construit selon la convention
        // prenom.nom@interieur.gouv.fr (en minuscules sans diacritiques)
        if (string.IsNullOrWhiteSpace(info.Email)
            && !string.IsNullOrWhiteSpace(info.FirstName)
            && !string.IsNullOrWhiteSpace(info.LastName))
        {
            var fn = SanitizeForEmail(info.FirstName);
            var ln = SanitizeForEmail(info.LastName);
            if (fn.Length > 0 && ln.Length > 0)
                info.Email = $"{fn}.{ln}@interieur.gouv.fr";
        }

        if (string.IsNullOrWhiteSpace(info.DisplayName))
            info.DisplayName = info.Matricule;

        return info;
    }

    private static byte[]? ReadPhotoBytes(DirectoryEntry de, string attrName)
    {
        try
        {
            var prop = de.Properties[attrName];
            if (prop == null || prop.Count == 0) return null;
            var v = prop.Value;
            if (v is byte[] bytes && bytes.Length > 0) return bytes;
            if (v is object[] arr && arr.Length > 0 && arr[0] is byte[] first) return first;
        }
        catch { }
        return null;
    }

    private static string Capitalize(string? s)
    {
        var v = (s ?? "").Trim();
        if (v.Length == 0) return v;
        return char.ToUpperInvariant(v[0]) + v.Substring(1).ToLowerInvariant();
    }

    private static string SanitizeForEmail(string? s)
    {
        var v = (s ?? "").Trim().ToLowerInvariant();
        s = v;
        // Remplacer les caractères accentués courants
        var map = new (char from, char to)[]
        {
            ('à','a'),('á','a'),('â','a'),('ä','a'),('ã','a'),
            ('é','e'),('è','e'),('ê','e'),('ë','e'),
            ('í','i'),('ì','i'),('î','i'),('ï','i'),
            ('ó','o'),('ò','o'),('ô','o'),('ö','o'),('õ','o'),
            ('ú','u'),('ù','u'),('û','u'),('ü','u'),
            ('ý','y'),('ÿ','y'),
            ('ç','c'),
            ('ñ','n'),
            (' ','-'),('\'','-'),
        };
        foreach (var (from, to) in map) s = s.Replace(from, to);
        // Garde uniquement [a-z0-9.\-]
        var sb = new System.Text.StringBuilder(s.Length);
        foreach (var c in s)
            if ((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '.' || c == '-')
                sb.Append(c);
        return sb.ToString().Trim('-', '.');
    }
}
