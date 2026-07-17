using System.Drawing.Drawing2D;
using System.Reflection;

namespace Bastion.StationBlanche;

/// <summary>
/// Identité visuelle Bastion (écu à créneaux, herse).
///
/// L'icône n'est PAS recopiée dans ce dossier : c'est le même fichier que celui du portail
/// et de la console, embarqué à la compilation. Deux copies finiraient par diverger, et la
/// station afficherait un logo que plus personne ne reconnaît.
/// </summary>
internal static class Marque
{
    private static byte[]? _ico;

    private static byte[] Octets()
    {
        if (_ico != null) return _ico;
        using var f = Assembly.GetExecutingAssembly().GetManifestResourceStream("bastion.ico")
            ?? throw new InvalidOperationException("Icône Bastion absente de l'exécutable.");
        using var ms = new MemoryStream();
        f.CopyTo(ms);
        return _ico = ms.ToArray();
    }

    /// <summary>
    /// Icône à la taille demandée. On vise la déclinaison 64 px : elle est stockée en bitmap
    /// brut dans le fichier, là où la 256 px est compressée en PNG — une variante que le
    /// décodeur d'icônes de .NET rend mal.
    /// </summary>
    public static Icon Icone(int taille)
    {
        using var ms = new MemoryStream(Octets());
        return new Icon(ms, taille, taille);
    }

    /// <summary>Logo détouré, redimensionné proprement pour l'en-tête.</summary>
    public static Bitmap Logo(int cote)
    {
        using var ico = Icone(64);
        using var src = ico.ToBitmap();
        var dst = new Bitmap(cote, cote);
        using var g = Graphics.FromImage(dst);
        // Le réglage par défaut donne un écu crénelé baveux : les créneaux sont ce qui
        // rend le logo reconnaissable, ils ne doivent pas fondre à la réduction.
        g.InterpolationMode = InterpolationMode.HighQualityBicubic;
        g.PixelOffsetMode = PixelOffsetMode.HighQuality;
        g.SmoothingMode = SmoothingMode.HighQuality;
        g.DrawImage(src, new Rectangle(0, 0, cote, cote));
        return dst;
    }
}
