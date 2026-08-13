using System;
using System.Linq;
using System.Management;
using System.Runtime.Versioning;
using Microsoft.Win32;

namespace DockPolice.Agent.Services;

[SupportedOSPlatform("windows")]
public static class AntivirusInfo
{
    public class Info
    {
        public string Name { get; set; } = "";
        public string State { get; set; } = "";
        public int RawState { get; set; }
        public bool Enabled { get; set; }
        public bool UpToDate { get; set; }
        public string SignatureDate { get; set; } = "";
    }

    /// <summary>
    /// Récupère le statut de l'antivirus principal via le centre de sécurité Windows.
    /// Détection bonus : si Trellix/McAfee est installé, on lit aussi le registre pour
    /// avoir la date des signatures (le centre de sécurité ne la donne pas toujours).
    /// </summary>
    public static Info Get()
    {
        var info = new Info();

        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"\\.\root\SecurityCenter2",
                "SELECT displayName, productState, timestamp FROM AntiVirusProduct");

            var products = searcher.Get().Cast<ManagementObject>().ToList();

            // Si plusieurs antivirus, priorité à Trellix/McAfee, sinon le premier
            var preferred = products.FirstOrDefault(p =>
            {
                var n = (p["displayName"] as string ?? "").ToLowerInvariant();
                return n.Contains("trellix") || n.Contains("mcafee");
            }) ?? products.FirstOrDefault();

            if (preferred != null)
            {
                info.Name = preferred["displayName"] as string ?? "";
                int productState = Convert.ToInt32(preferred["productState"] ?? 0);
                info.RawState = productState;

                // Décodage du bitfield productState
                // Bits 12-13 (0x1000) : enabled/disabled
                // Bits 16-17 (0x10000) : up-to-date / out-of-date
                int enabledFlag = (productState >> 12) & 0xF;   // 10 = ON, 11 = OFF
                int sigFlag     = (productState >> 16) & 0xF;   // 00 = up-to-date, 10 = out-of-date

                info.Enabled = enabledFlag == 0x10 || enabledFlag == 0x11;
                if (enabledFlag == 0x10) info.Enabled = true;
                else if (enabledFlag == 0x11) info.Enabled = false;
                else info.Enabled = enabledFlag != 0;

                info.UpToDate = sigFlag == 0x00;
                info.State = (info.Enabled ? "actif" : "désactivé")
                             + (info.UpToDate ? " - à jour" : " - signatures obsolètes");
            }
        }
        catch (Exception ex)
        {
            info.State = "Erreur WMI : " + ex.Message;
        }

        // Bonus Trellix/McAfee : date signatures via registre
        try
        {
            string?[] candidates =
            {
                Registry.LocalMachine.OpenSubKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("AVDatDate") as string,
                Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Wow6432Node\McAfee\AVEngine")?.GetValue("AVDatDate") as string,
                Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Trellix\Endpoint Security\TheatPrevention\Engine")?.GetValue("AVDatDate") as string,
                Registry.LocalMachine.OpenSubKey(@"SOFTWARE\McAfee\Endpoint\AV")?.GetValue("DATVersion") as string,
            };
            info.SignatureDate = candidates.FirstOrDefault(s => !string.IsNullOrEmpty(s)) ?? "";
        }
        catch { }

        return info;
    }
}
