using System.Runtime.InteropServices;

namespace Bastion.StationBlanche;

/// <summary>Un support amovible détecté.</summary>
public sealed record Support(string Lettre, string Nom, long Taille, long Libre)
{
    public string Libelle => string.IsNullOrWhiteSpace(Nom)
        ? $"{Lettre} — {Fmt(Taille)}"
        : $"{Lettre} — {Nom} ({Fmt(Taille)})";

    public static string Fmt(long o)
    {
        string[] u = { "o", "Ko", "Mo", "Go", "To" };
        double v = o; int i = 0;
        while (v >= 1024 && i < u.Length - 1) { v /= 1024; i++; }
        return v.ToString(i > 0 ? "0.0" : "0") + " " + u[i];
    }
}

/// <summary>
/// Détection des clés USB.
///
/// POURQUOI WM_DEVICECHANGE ET PAS UN SONDAGE : Windows PRÉVIENT à l'insertion. Un
/// sondage toutes les secondes réveillerait le disque en continu et réagirait avec un
/// retard visible — sur une station où l'on insère une clé et où l'on attend, cette
/// seconde se voit.
///
/// MAIS un sondage de secours reste nécessaire : le message arrive dès que le volume est
/// monté, or la lettre de lecteur et le nom ne sont parfois pas encore lisibles à cet
/// instant. On confirme donc par une relecture différée.
/// </summary>
public sealed class UsbWatcher : NativeWindow, IDisposable
{
    private const int WM_DEVICECHANGE = 0x0219;
    private const int DBT_DEVICEARRIVAL = 0x8000;
    private const int DBT_DEVICEREMOVECOMPLETE = 0x8004;

    private readonly System.Windows.Forms.Timer _relecture = new() { Interval = 1200 };
    private readonly System.Windows.Forms.Timer _secours   = new() { Interval = 3000 };
    private List<string> _connus = new();

    /// <summary>Un support vient d'apparaître.</summary>
    public event Action<Support>? Insere;
    /// <summary>Un support a été retiré (lettre de lecteur).</summary>
    public event Action<string>? Retire;

    public UsbWatcher(Form hote)
    {
        AssignHandle(hote.Handle);
        _connus = Lister().Select(s => s.Lettre).ToList();
        _relecture.Tick += (_, _) => { _relecture.Stop(); Comparer(); };
        // Filet : certains lecteurs de cartes et concentrateurs n'émettent aucun message.
        _secours.Tick += (_, _) => Comparer();
        _secours.Start();
    }

    protected override void WndProc(ref Message m)
    {
        if (m.Msg == WM_DEVICECHANGE)
        {
            var e = m.WParam.ToInt32();
            if (e == DBT_DEVICEARRIVAL || e == DBT_DEVICEREMOVECOMPLETE)
            {
                // Différé : à l'instant du message, le volume n'est pas toujours prêt et
                // DriveInfo.IsReady rend false. Relire trop tôt ferait manquer la clé.
                _relecture.Stop(); _relecture.Start();
            }
        }
        base.WndProc(ref m);
    }

    /// <summary>
    /// Supports amovibles actuellement présents.
    ///
    /// DriveType.Removable NE SUFFIT PAS : bien des disques durs USB se déclarent
    /// « Fixed ». On accepte donc aussi les disques fixes qui ne sont pas le disque
    /// système — sans quoi un disque externe passerait inaperçu sur une station blanche.
    /// </summary>
    public static List<Support> Lister()
    {
        var systeme = Path.GetPathRoot(Environment.SystemDirectory)?.TrimEnd('\\') ?? "C:";
        var liste = new List<Support>();
        foreach (var d in DriveInfo.GetDrives())
        {
            try
            {
                if (!d.IsReady) continue;
                var lettre = d.Name.TrimEnd('\\');
                if (string.Equals(lettre, systeme, StringComparison.OrdinalIgnoreCase)) continue;
                if (d.DriveType != DriveType.Removable && d.DriveType != DriveType.Fixed) continue;
                liste.Add(new Support(lettre, d.VolumeLabel, d.TotalSize, d.AvailableFreeSpace));
            }
            catch { /* lecteur disparu entre-temps : on l'ignore */ }
        }
        return liste;
    }

    private void Comparer()
    {
        var actuels = Lister();
        var lettres = actuels.Select(s => s.Lettre).ToList();
        foreach (var s in actuels.Where(s => !_connus.Contains(s.Lettre))) Insere?.Invoke(s);
        foreach (var l in _connus.Where(l => !lettres.Contains(l))) Retire?.Invoke(l);
        _connus = lettres;
    }

    public void Dispose()
    {
        _relecture.Dispose(); _secours.Dispose();
        ReleaseHandle();
    }
}
