using System.Management;

namespace Bastion.StationBlanche;

/// <summary>Un support amovible détecté.</summary>
public sealed record Support(string Lettre, string Nom, long Taille, string Materiel, string Bus)
{
    public string Libelle => string.IsNullOrWhiteSpace(Nom)
        ? $"{Lettre}  —  {Materiel} ({Fmt(Taille)})"
        : $"{Lettre}  —  {Nom} · {Materiel} ({Fmt(Taille)})";

    public static string Fmt(long o)
    {
        string[] u = { "o", "Ko", "Mo", "Go", "To" };
        double v = o; int i = 0;
        while (v >= 1024 && i < u.Length - 1) { v /= 1024; i++; }
        return v.ToString(i > 0 ? "0.0" : "0") + " " + u[i];
    }
}

/// <summary>
/// Détection des supports USB.
///
/// ── POURQUOI INTERROGER LE BUS MATÉRIEL ────────────────────────────────────
/// DriveInfo.DriveType NE SUFFIT PAS, et s'y fier est dangereux :
///   - Beaucoup de clés et de disques USB se déclarent « Fixed », pas « Removable ».
///     Ne garder que « Removable » les manquerait.
///   - Inversement, accepter tout « Fixed » sauf le disque système fait passer les DISQUES
///     INTERNES pour des clés. MESURÉ sur le poste de développement : le second NVMe
///     (1,9 To, bus SCSI) était présenté comme une clé USB à analyser. Une station qui
///     propose d'analyser 1,9 To de disque interne se bloque des heures — et un agent
///     pressé cliquerait.
/// Seul le BUS dit la vérité : Win32_DiskDrive.InterfaceType = 'USB'. On suit ensuite la
/// chaîne disque → partition → lettre de lecteur.
/// </summary>
public sealed class UsbWatcher : NativeWindow, IDisposable
{
    private const int WM_DEVICECHANGE = 0x0219;
    private const int DBT_DEVICEARRIVAL = 0x8000;
    private const int DBT_DEVICEREMOVECOMPLETE = 0x8004;

    private readonly System.Windows.Forms.Timer _relecture = new() { Interval = 1500 };
    private readonly System.Windows.Forms.Timer _secours = new() { Interval = 4000 };
    private List<string> _connus = new();

    public event Action<Support>? Insere;
    public event Action<string>? Retire;

    public UsbWatcher(Form hote)
    {
        AssignHandle(hote.Handle);
        _connus = Lister().Select(s => s.Lettre).ToList();
        _relecture.Tick += (_, _) => { _relecture.Stop(); Comparer(); };
        // Filet : certains concentrateurs et lecteurs de cartes n'émettent aucun message.
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
                // Différé : à l'instant du message, le volume n'est pas monté et n'a pas
                // encore de lettre. Relire aussitôt ne trouverait rien.
                _relecture.Stop(); _relecture.Start();
            }
        }
        base.WndProc(ref m);
    }

    /// <summary>
    /// Supports réellement branchés sur le bus USB.
    ///
    /// La requête WMI coûte ~100 ms : acceptable au rythme où l'on insère une clé, et le
    /// prix d'une détection JUSTE. Un sondage rapide ne vaudrait pas de proposer un disque
    /// interne à l'analyse.
    /// </summary>
    public static List<Support> Lister()
    {
        var liste = new List<Support>();
        var systeme = Path.GetPathRoot(Environment.SystemDirectory)?.TrimEnd('\\') ?? "C:";
        try
        {
            using var disques = new ManagementObjectSearcher(
                "SELECT DeviceID, Model, Size, InterfaceType FROM Win32_DiskDrive WHERE InterfaceType='USB'");
            foreach (ManagementObject d in disques.Get())
            {
                var modele = (d["Model"]?.ToString() ?? "support USB").Trim();
                foreach (ManagementObject p in d.GetRelated("Win32_DiskPartition"))
                    foreach (ManagementObject l in p.GetRelated("Win32_LogicalDisk"))
                    {
                        var lettre = l["DeviceID"]?.ToString();
                        if (string.IsNullOrEmpty(lettre)) continue;
                        // Garde-fou : le disque système n'est JAMAIS un support à analyser,
                        // même branché en USB (poste démarré sur clé, station sur SSD USB).
                        if (string.Equals(lettre, systeme, StringComparison.OrdinalIgnoreCase)) continue;

                        long taille = 0; string nom = "";
                        try
                        {
                            var di = new DriveInfo(lettre);
                            if (!di.IsReady) continue;   // volume chiffré verrouillé, ou en cours de montage
                            taille = di.TotalSize; nom = di.VolumeLabel;
                        }
                        catch { continue; }
                        liste.Add(new Support(lettre, nom, taille, modele, "USB"));
                    }
            }
        }
        catch
        {
            // WMI indisponible (service arrêté, poste durci) : sans lui on ne peut PAS
            // distinguer une clé d'un disque interne. On se rabat sur le seul critère
            // sûr — « Removable » — quitte à manquer les disques USB qui se déclarent
            // « Fixed ». Manquer un support est gênant ; proposer d'analyser le disque
            // interne de la station serait pire.
            foreach (var d in DriveInfo.GetDrives())
            {
                try
                {
                    if (!d.IsReady || d.DriveType != DriveType.Removable) continue;
                    var lettre = d.Name.TrimEnd('\\');
                    if (string.Equals(lettre, systeme, StringComparison.OrdinalIgnoreCase)) continue;
                    liste.Add(new Support(lettre, d.VolumeLabel, d.TotalSize, "support amovible", "?"));
                }
                catch { }
            }
        }
        return liste.OrderBy(s => s.Lettre).ToList();
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
