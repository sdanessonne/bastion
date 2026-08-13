using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace DockLite.Services;

/// <summary>
/// Détection en temps réel de l'insertion d'une carte à puce dans un lecteur PC/SC
/// (boîtier Xiring + middleware MI principalement) ET extraction des certificats
/// "Carte Agent" depuis le magasin Windows de l'utilisateur courant.
///
/// Reposo sur deux sources :
///   1. WinSCard.dll (API PC/SC native) → présence physique d'une carte
///   2. X509Store(My, CurrentUser)      → certificats publiés par le middleware MI
///                                         (issuer "AC PERSONNE AUTHENTIFICATION", etc.)
///
/// Quand une carte est détectée, on lit le CN du certificat AUTH pour en extraire
///   - PRÉNOM NOM MATRICULE  (format "CYRIL BACUET 1145335")
///   - prenom.nom.rio        (UPN/email, ex: "mickael.monestier.1012053")
///
/// Expose un mini-serveur HTTP local sur http://127.0.0.1:43782/ avec CORS pour
/// que la page login.php (HTTPS) puisse poller l'état via fetch et mettre à jour
/// l'UI du bouton "Carte agent" en temps réel.
///
/// Aucune dépendance externe — pure stdlib .NET 8 + WinSCard P/Invoke.
/// Le PIN n'est JAMAIS lu/transmis (impossible sans pilote dédié de toute façon).
/// </summary>
public sealed class SmartCardService : IDisposable
{
    public const int LocalPort = 43782;
    private const string ListenerPrefix = "http://127.0.0.1:43782/";

    // ---------- WinSCard P/Invoke (style éprouvé du projet LireRFID) ----------
    private const uint   SCARD_SCOPE_USER    = 0;
    private const uint   SCARD_STATE_UNAWARE = 0x00000000;
    private const uint   SCARD_STATE_PRESENT = 0x00000020;
    private const uint   SCARD_STATE_EMPTY   = 0x00000010;
    private const uint   SCARD_STATE_MUTE    = 0x00000200;
    private const uint   SCARD_STATE_INUSE   = 0x00000100;
    private const uint   SCARD_E_NO_READERS_AVAILABLE = 0x8010002E;
    private const uint   SCARD_E_TIMEOUT     = 0x8010000A;
    private const int    SCARD_S_SUCCESS     = 0;

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct SCARD_READERSTATE
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string szReader;
        public IntPtr pvUserData;
        public uint dwCurrentState;
        public uint dwEventState;
        public uint cbAtr;
        [MarshalAs(UnmanagedType.ByValArray, SizeConst = 36)] public byte[] rgbAtr;
    }

    [DllImport("winscard.dll")]
    private static extern int SCardEstablishContext(uint dwScope, IntPtr pvReserved1, IntPtr pvReserved2, out IntPtr phContext);

    [DllImport("winscard.dll")]
    private static extern int SCardReleaseContext(IntPtr hContext);

    [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
    private static extern int SCardListReadersW(IntPtr hContext, string? mszGroups, byte[]? mszReaders, ref uint pcchReaders);

    [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
    private static extern int SCardGetStatusChangeW(IntPtr hContext, uint dwTimeout, [In, Out] SCARD_READERSTATE[] rgReaderStates, uint cReaders);

    // ---------- État interne ----------
    private readonly object _lock = new();
    private CardSnapshot _snapshot = new() { AgentRunning = true, GeneratedAt = DateTime.UtcNow };
    private CancellationTokenSource? _cts;
    private HttpListener? _listener;
    private Task? _httpTask;
    private Task? _watcherTask;

    // Événement (utilisable par d'autres services pour ex. afficher un toast à l'insertion)
    public event Action<CardSnapshot>? StatusChanged;

    public void Start()
    {
        if (_cts != null) return;
        _cts = new CancellationTokenSource();

        _watcherTask = Task.Run(() => WatchLoop(_cts.Token), _cts.Token);

        try
        {
            _listener = new HttpListener();
            _listener.Prefixes.Add(ListenerPrefix);
            _listener.Start();
            _httpTask = Task.Run(() => ServeLoop(_cts.Token), _cts.Token);
        }
        catch (Exception ex)
        {
            LogError($"HttpListener start failed: {ex.Message}");
        }
    }

    public CardSnapshot Current
    {
        get { lock (_lock) return _snapshot.Clone(); }
    }

    private static string LogPath()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DockPolice");
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, "smartcard.log");
    }

    private static void LogError(string msg)
    {
        try { File.AppendAllText(LogPath(), $"[{DateTime.Now:O}] {msg}\r\n"); } catch { }
    }

    // ---------- Boucle de surveillance des lecteurs ----------
    private void WatchLoop(CancellationToken ct)
    {
        IntPtr ctx = IntPtr.Zero;
        try
        {
            while (!ct.IsCancellationRequested)
            {
                if (ctx == IntPtr.Zero)
                {
                    if (SCardEstablishContext(SCARD_SCOPE_USER, IntPtr.Zero, IntPtr.Zero, out ctx) != SCARD_S_SUCCESS)
                    {
                        UpdateSnapshot(new CardSnapshot
                        {
                            AgentRunning = true,
                            Error = "Service Windows 'Smart Card' indisponible",
                            GeneratedAt = DateTime.UtcNow
                        });
                        try { Task.Delay(5000, ct).Wait(ct); } catch { }
                        continue;
                    }
                }

                // 1) Liste des lecteurs disponibles
                List<string> readers;
                try { readers = ListReaders(ctx); }
                catch (Exception ex)
                {
                    LogError($"ListReaders: {ex.Message}");
                    SafeReleaseContext(ref ctx);
                    try { Task.Delay(2000, ct).Wait(ct); } catch { }
                    continue;
                }

                if (readers.Count == 0)
                {
                    UpdateSnapshot(new CardSnapshot
                    {
                        AgentRunning = true,
                        CardPresent = false,
                        Readers = new List<ReaderInfo>(),
                        GeneratedAt = DateTime.UtcNow
                    });
                    try { Task.Delay(2000, ct).Wait(ct); } catch { }
                    continue;
                }

                // 2) Etats — timeout 1500ms = retour quasi instantané sur insertion
                var states = new SCARD_READERSTATE[readers.Count];
                for (int i = 0; i < readers.Count; i++)
                {
                    states[i] = new SCARD_READERSTATE
                    {
                        szReader = readers[i],
                        dwCurrentState = SCARD_STATE_UNAWARE,
                        rgbAtr = new byte[36],
                    };
                }

                int rc = SCardGetStatusChangeW(ctx, 1500, states, (uint)states.Length);
                if (rc != SCARD_S_SUCCESS && (uint)rc != SCARD_E_TIMEOUT)
                {
                    LogError($"SCardGetStatusChange rc=0x{rc:X8}");
                    SafeReleaseContext(ref ctx);
                    try { Task.Delay(1000, ct).Wait(ct); } catch { }
                    continue;
                }

                // 3) Construit le snapshot
                var snap = new CardSnapshot
                {
                    AgentRunning = true,
                    Readers = new List<ReaderInfo>(),
                    GeneratedAt = DateTime.UtcNow
                };
                bool anyPresent = false;
                foreach (var s in states)
                {
                    bool present = (s.dwEventState & SCARD_STATE_PRESENT) != 0;
                    bool empty   = (s.dwEventState & SCARD_STATE_EMPTY)   != 0;
                    bool mute    = (s.dwEventState & SCARD_STATE_MUTE)    != 0;
                    bool inUse   = (s.dwEventState & SCARD_STATE_INUSE)   != 0;
                    string atrHex = (present && s.cbAtr > 0 && s.rgbAtr != null)
                        ? BitConverter.ToString(s.rgbAtr, 0, (int)Math.Min(s.cbAtr, 36)).Replace("-", "")
                        : "";
                    string brand = DetectBrand(s.szReader);

                    snap.Readers.Add(new ReaderInfo
                    {
                        Name     = s.szReader,
                        Brand    = brand,
                        Present  = present,
                        Empty    = empty,
                        Mute     = mute,
                        InUse    = inUse,
                        AtrHex   = atrHex,
                        IsXiring = brand.Equals("Xiring", StringComparison.OrdinalIgnoreCase),
                    });
                    if (present && !mute) anyPresent = true;
                }
                snap.CardPresent = anyPresent;

                // 4) Si carte présente, lit les certifs Carte Agent depuis le magasin Windows
                if (anyPresent)
                {
                    try
                    {
                        var certs = ReadAgentCertificates();
                        snap.AgentCertificates = certs;
                        var primary = certs.FirstOrDefault();
                        if (primary != null)
                        {
                            snap.Identity   = $"{primary.Prenom} {primary.Nom}".Trim();
                            snap.Matricule  = primary.Matricule;
                            snap.IdentityUpn = primary.Upn;
                            snap.IdentityEmail = primary.Email;
                        }
                    }
                    catch (Exception ex)
                    {
                        LogError($"ReadAgentCertificates: {ex.Message}");
                    }
                }

                UpdateSnapshot(snap);
            }
        }
        catch (OperationCanceledException) { /* normal */ }
        catch (Exception ex) { LogError($"WatchLoop crash: {ex}"); }
        finally { SafeReleaseContext(ref ctx); }
    }

    private static void SafeReleaseContext(ref IntPtr ctx)
    {
        if (ctx != IntPtr.Zero)
        {
            try { SCardReleaseContext(ctx); } catch { }
            ctx = IntPtr.Zero;
        }
    }

    private static List<string> ListReaders(IntPtr ctx)
    {
        uint size = 0;
        int rc = SCardListReadersW(ctx, null, null, ref size);
        if (rc == unchecked((int)SCARD_E_NO_READERS_AVAILABLE)) return new List<string>();
        if (rc != SCARD_S_SUCCESS || size == 0) return new List<string>();

        var buffer = new byte[size * 2];
        rc = SCardListReadersW(ctx, null, buffer, ref size);
        if (rc != SCARD_S_SUCCESS) return new List<string>();

        var multi = Encoding.Unicode.GetString(buffer, 0, (int)(size * 2));
        return multi.Split('\0', StringSplitOptions.RemoveEmptyEntries).ToList();
    }

    private static string DetectBrand(string readerName)
    {
        if (string.IsNullOrEmpty(readerName)) return "";
        var s = readerName.ToLowerInvariant();
        if (s.Contains("xiring"))                          return "Xiring";
        if (s.Contains("gemalto") || s.Contains("idprime")) return "Gemalto";
        if (s.Contains("oberthur"))                        return "Oberthur";
        if (s.Contains("safran"))                          return "Safran";
        if (s.Contains("cherry"))                          return "Cherry";
        if (s.Contains("scm"))                             return "SCM";
        if (s.Contains("omnikey"))                         return "OMNIKEY";
        if (s.Contains("acs"))                             return "ACS";
        return "Générique";
    }

    // ---------- Lecture des certificats "Carte Agent" depuis Windows ----------

    /// <summary>
    /// Récupère les certifs émis par la PKI ministérielle (IN Groupe / Imprimerie Nationale)
    /// depuis le magasin personnel de l'utilisateur courant. Quand la carte est insérée,
    /// le middleware MI publie automatiquement les certificats AUTH/SIGN/CONF dans ce store.
    /// </summary>
    private static List<CertInfo> ReadAgentCertificates()
    {
        var results = new List<CertInfo>();
        using var store = new X509Store(StoreName.My, StoreLocation.CurrentUser);
        store.Open(OpenFlags.ReadOnly);

        foreach (var cert in store.Certificates)
        {
            string issuer = cert.Issuer ?? "";
            // PKI Ministère de l'Intérieur (Imprimerie Nationale)
            if (!IsAgentCertIssuer(issuer)) continue;
            if (DateTime.UtcNow > cert.NotAfter || DateTime.UtcNow < cert.NotBefore) continue;

            var (nom, prenom, matricule) = ParseCN(cert.Subject);
            string upn   = ExtractSanUpn(cert);
            string email = ExtractSanEmail(cert);
            string purpose = DeterminePurpose(issuer);

            results.Add(new CertInfo
            {
                Thumbprint  = cert.Thumbprint ?? "",
                Subject     = cert.Subject ?? "",
                Issuer      = issuer,
                Serial      = cert.SerialNumber ?? "",
                NotBefore   = cert.NotBefore,
                NotAfter    = cert.NotAfter,
                Purpose     = purpose,
                Nom         = nom,
                Prenom      = prenom,
                Matricule   = matricule,
                Upn         = upn,
                Email       = email,
            });
        }

        // Priorise les certifs d'authentification (PIN 4 chiffres) sur les certifs de signature
        results.Sort((a, b) =>
        {
            int rank(CertInfo c) => c.Purpose switch
            {
                "auth" => 0,
                "any"  => 1,
                "conf" => 2,
                "sign" => 3,
                _      => 4,
            };
            return rank(a).CompareTo(rank(b));
        });
        return results;
    }

    private static bool IsAgentCertIssuer(string issuer)
    {
        // Détection des autorités MI (IGC-MI, AC PERSONNE, etc.)
        if (issuer.Contains("AC PERSONNE", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("PERSONNE AUTHENTIFICATION", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("PERSONNE CONFIDENTIALITE", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("PERSONNE SIGNATURE", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("AC AGENT MI", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("IGC/A", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("IGC-MI", StringComparison.OrdinalIgnoreCase)) return true;
        if (issuer.Contains("INTERIEUR", StringComparison.OrdinalIgnoreCase) && issuer.Contains("MINIST", StringComparison.OrdinalIgnoreCase)) return true;
        return false;
    }

    private static string DeterminePurpose(string issuer)
    {
        if (issuer.Contains("AUTHENTIFICATION", StringComparison.OrdinalIgnoreCase)) return "auth";
        if (issuer.Contains("SIGNATURE",       StringComparison.OrdinalIgnoreCase)) return "sign";
        if (issuer.Contains("CONFIDENTIALITE", StringComparison.OrdinalIgnoreCase)) return "conf";
        return "any";
    }

    /// <summary>
    /// Parse le CN MI au format "PRENOM NOM MATRICULE" (espaces) ou "prenom.nom.rio" (points).
    /// Le matricule est le DERNIER bloc numérique (4-10 chiffres).
    /// </summary>
    private static (string nom, string prenom, string matricule) ParseCN(string? subject)
    {
        if (string.IsNullOrEmpty(subject)) return ("", "", "");
        string cn = "";
        foreach (var part in subject.Split(','))
        {
            var t = part.Trim();
            if (t.StartsWith("CN=", StringComparison.OrdinalIgnoreCase)) { cn = t[3..].Trim(); break; }
        }
        if (string.IsNullOrEmpty(cn)) return ("", "", "");

        // Format dotté "prenom.nom.rio" (UPN-style)
        if (cn.Contains('.') && !cn.Contains(' '))
        {
            var dotParts = cn.Split('.', StringSplitOptions.RemoveEmptyEntries);
            if (dotParts.Length >= 3 && dotParts[^1].All(char.IsDigit))
            {
                string mat = dotParts[^1];
                string pre = TitleCase(dotParts[0]);
                string n   = string.Join(" ", dotParts.Skip(1).Take(dotParts.Length - 2).Select(TitleCase));
                return (n, pre, mat);
            }
        }

        // Format espacé "PRENOM NOM MATRICULE"
        var parts = cn.Split(' ', StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length == 0) return ("", "", "");

        string matricule = "";
        int matIdx = parts.Length;
        for (int i = parts.Length - 1; i >= 0; i--)
        {
            if (parts[i].All(char.IsDigit) && parts[i].Length >= 4)
            {
                matricule = parts[i];
                matIdx = i;
                break;
            }
        }
        string prenom = parts.Length > 0 ? TitleCase(parts[0]) : "";
        string nom    = string.Join(" ", parts.Skip(1).Take(matIdx - 1).Select(TitleCase));
        return (nom, prenom, matricule);
    }

    private static string TitleCase(string s)
    {
        if (string.IsNullOrEmpty(s)) return s;
        var lower = s.ToLowerInvariant();
        return char.ToUpper(lower[0]) + lower[1..];
    }

    /// <summary>
    /// Extrait le UPN (User Principal Name) d'un SAN (1.3.6.1.4.1.311.20.2.3).
    /// </summary>
    private static string ExtractSanUpn(X509Certificate2 cert)
    {
        try
        {
            var san = cert.Extensions["2.5.29.17"];
            if (san == null) return "";
            string str = new AsnEncodedData(san.Oid!, san.RawData).Format(true);
            // Le format texte contient "Autre nom : Nom du principal=mickael.monestier.1012053@..."
            var lines = str.Split(new[] { "\r\n", "\n" }, StringSplitOptions.RemoveEmptyEntries);
            foreach (var l in lines)
            {
                if (l.Contains("Principal", StringComparison.OrdinalIgnoreCase)
                 || l.Contains("UPN",       StringComparison.OrdinalIgnoreCase)
                 || l.Contains("User Principal Name", StringComparison.OrdinalIgnoreCase))
                {
                    int eq = l.IndexOf('=');
                    if (eq > 0) return l[(eq + 1)..].Trim();
                }
            }
        }
        catch { }
        return "";
    }

    private static string ExtractSanEmail(X509Certificate2 cert)
    {
        try
        {
            var san = cert.Extensions["2.5.29.17"];
            if (san != null)
            {
                string str = new AsnEncodedData(san.Oid!, san.RawData).Format(true);
                var lines = str.Split(new[] { "\r\n", "\n" }, StringSplitOptions.RemoveEmptyEntries);
                foreach (var l in lines)
                {
                    if (l.Contains("RFC822", StringComparison.OrdinalIgnoreCase)
                     || l.Contains("Adresse de messagerie", StringComparison.OrdinalIgnoreCase)
                     || l.Contains("Email", StringComparison.OrdinalIgnoreCase))
                    {
                        int eq = l.IndexOf('=');
                        if (eq > 0) return l[(eq + 1)..].Trim();
                    }
                }
            }
            // Fallback : emailAddress dans le subject
            foreach (var part in cert.Subject.Split(','))
            {
                var t = part.Trim();
                if (t.StartsWith("E=", StringComparison.OrdinalIgnoreCase)
                 || t.StartsWith("emailAddress=", StringComparison.OrdinalIgnoreCase))
                    return t.Split('=', 2)[1].Trim();
            }
        }
        catch { }
        return "";
    }

    // ---------- Mini-serveur HTTP local (CORS pour login.php) ----------
    private async Task ServeLoop(CancellationToken ct)
    {
        if (_listener == null) return;
        while (!ct.IsCancellationRequested && _listener.IsListening)
        {
            HttpListenerContext context;
            try { context = await _listener.GetContextAsync(); }
            catch (HttpListenerException) { break; }
            catch (ObjectDisposedException) { break; }
            _ = Task.Run(() => HandleRequest(context));
        }
    }

    private void HandleRequest(HttpListenerContext ctx)
    {
        try
        {
            var origin = ctx.Request.Headers["Origin"] ?? "*";
            ctx.Response.AppendHeader("Access-Control-Allow-Origin", origin);
            ctx.Response.AppendHeader("Access-Control-Allow-Methods", "GET, OPTIONS");
            ctx.Response.AppendHeader("Cache-Control", "no-store, no-cache, must-revalidate");
            ctx.Response.AppendHeader("Pragma", "no-cache");

            if (ctx.Request.HttpMethod == "OPTIONS")
            {
                ctx.Response.StatusCode = 204;
                ctx.Response.Close();
                return;
            }

            var path = ctx.Request.Url?.AbsolutePath?.ToLowerInvariant() ?? "/";
            object payload;
            int status = 200;

            if (path == "/" || path == "/card")
            {
                payload = Current;
            }
            else if (path == "/ping")
            {
                payload = new { ok = true, agent = "DockPolice", version = "1.0", port = LocalPort };
            }
            else if (path == "/laps" && OperatingSystem.IsWindows())
            {
                // Récupération LAPS via le contexte AD de l'utilisateur connecté
                var query = ctx.Request.QueryString;
                var machine = query["machine"] ?? "";
                if (string.IsNullOrWhiteSpace(machine))
                {
                    status = 400;
                    payload = new { ok = false, error = "missing_machine" };
                }
                else
                {
                    var res = LapsService.Fetch(machine);
                    payload = res;
                }
            }
            else if (path == "/laps-local" && OperatingSystem.IsWindows())
            {
                // Fallback hors-ligne : tente le cache LAPS local + cmdlet PS locale
                var account = ctx.Request.QueryString["account"] ?? "Administrator";
                payload = LapsService.FetchLocal(account);
            }
            else if (path == "/laps-history" && OperatingSystem.IsWindows())
            {
                // Historique des mots de passe LAPS (msLAPS-EncryptedPasswordHistory)
                var machine = ctx.Request.QueryString["machine"] ?? "";
                if (string.IsNullOrWhiteSpace(machine))
                {
                    status = 400;
                    payload = new { ok = false, error = "missing_machine" };
                }
                else
                {
                    payload = LapsService.FetchHistory(machine);
                }
            }
            else if (path == "/eventlog" && OperatingSystem.IsWindows())
            {
                // Lecture du journal d'événements Windows (local OU distant via RPC)
                //   ?type=summary|logon|system|crash|lockout|rdp
                //   &days=N (défaut 7)
                //   &limit=N (défaut 200)
                //   &machine=NOM-PC  (optionnel, lecture distante via RPC)
                var type    = (ctx.Request.QueryString["type"]    ?? "summary").ToLowerInvariant();
                var days    = int.TryParse(ctx.Request.QueryString["days"],  out var d) ? d : 7;
                var limit   = int.TryParse(ctx.Request.QueryString["limit"], out var l) ? l : 200;
                var machine = ctx.Request.QueryString["machine"];

                if (type == "summary")
                {
                    payload = EventLogService.GetSummary(days, Math.Min(limit, 200), machine);
                }
                else
                {
                    payload = new {
                        ok = true, type, days, limit, machine,
                        events = EventLogService.GetEvents(type, days, limit, machine),
                    };
                }
            }
            else if (path == "/laps-reset" && OperatingSystem.IsWindows()
                     && ctx.Request.HttpMethod == "POST")
            {
                // Reset à chaud du mot de passe admin local (génère + applique via net user)
                var account = ctx.Request.QueryString["account"] ?? "Administrator";
                var lenStr  = ctx.Request.QueryString["length"]  ?? "20";
                if (!int.TryParse(lenStr, out var len)) len = 20;
                payload = LapsService.ResetLocalAdmin(account, len);
            }
            else if (path == "/local-admins" && OperatingSystem.IsWindows())
            {
                // Membres du groupe Administrateurs local — local OU distant via RPC
                //   ?machine=NOM-PC  (optionnel, lecture distante via WMI)
                var target = ctx.Request.QueryString["machine"];
                payload = LocalAdminsService.Fetch(target);
            }
            else
            {
                status = 404;
                payload = new { error = "not_found" };
            }

            var json = JsonSerializer.Serialize(payload, new JsonSerializerOptions
            {
                PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
                WriteIndented = false,
                DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
            });
            var bytes = Encoding.UTF8.GetBytes(json);
            ctx.Response.StatusCode = status;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.LongLength;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
            ctx.Response.Close();
        }
        catch (Exception ex)
        {
            LogError($"HTTP handler: {ex.Message}");
            try { ctx.Response.StatusCode = 500; ctx.Response.Close(); } catch { }
        }
    }

    private void UpdateSnapshot(CardSnapshot snap)
    {
        bool changed;
        lock (_lock)
        {
            changed = _snapshot.CardPresent != snap.CardPresent
                   || _snapshot.Readers.Count != snap.Readers.Count
                   || _snapshot.Identity != snap.Identity
                   || _snapshot.Error != snap.Error;
            _snapshot = snap;
        }
        if (changed)
        {
            try { StatusChanged?.Invoke(snap); } catch { }
        }
    }

    public void Dispose()
    {
        try { _cts?.Cancel(); } catch { }
        try { _listener?.Stop(); } catch { }
        try { _listener?.Close(); } catch { }
        try { _httpTask?.Wait(500); } catch { }
        try { _watcherTask?.Wait(500); } catch { }
        _cts?.Dispose();
    }

    // ---------- DTOs JSON ----------
    public sealed class CardSnapshot
    {
        public bool AgentRunning { get; set; } = true;
        public bool CardPresent  { get; set; }
        public string? Identity      { get; set; }
        public string? Matricule     { get; set; }
        public string? IdentityUpn   { get; set; }
        public string? IdentityEmail { get; set; }
        public List<ReaderInfo> Readers { get; set; } = new();
        public List<CertInfo>?  AgentCertificates { get; set; }
        public string? Error     { get; set; }
        public DateTime GeneratedAt { get; set; }

        public CardSnapshot Clone() => new()
        {
            AgentRunning = AgentRunning,
            CardPresent  = CardPresent,
            Identity     = Identity,
            Matricule    = Matricule,
            IdentityUpn  = IdentityUpn,
            IdentityEmail = IdentityEmail,
            Readers      = Readers.Select(r => r.Clone()).ToList(),
            AgentCertificates = AgentCertificates?.Select(c => c.Clone()).ToList(),
            Error        = Error,
            GeneratedAt  = GeneratedAt,
        };
    }

    public sealed class ReaderInfo
    {
        public string Name     { get; set; } = "";
        public string Brand    { get; set; } = "";
        public bool   Present  { get; set; }
        public bool   Empty    { get; set; }
        public bool   Mute     { get; set; }
        public bool   InUse    { get; set; }
        public string AtrHex   { get; set; } = "";
        public bool   IsXiring { get; set; }

        public ReaderInfo Clone() => new()
        {
            Name = Name, Brand = Brand, Present = Present, Empty = Empty,
            Mute = Mute, InUse = InUse, AtrHex = AtrHex, IsXiring = IsXiring,
        };
    }

    public sealed class CertInfo
    {
        public string Thumbprint { get; set; } = "";
        public string Subject    { get; set; } = "";
        public string Issuer     { get; set; } = "";
        public string Serial     { get; set; } = "";
        public DateTime NotBefore { get; set; }
        public DateTime NotAfter  { get; set; }
        public string Purpose    { get; set; } = "";  // auth | sign | conf | any
        public string Nom        { get; set; } = "";
        public string Prenom     { get; set; } = "";
        public string Matricule  { get; set; } = "";
        public string Upn        { get; set; } = "";
        public string Email      { get; set; } = "";

        public CertInfo Clone() => new()
        {
            Thumbprint = Thumbprint, Subject = Subject, Issuer = Issuer, Serial = Serial,
            NotBefore = NotBefore, NotAfter = NotAfter, Purpose = Purpose,
            Nom = Nom, Prenom = Prenom, Matricule = Matricule, Upn = Upn, Email = Email,
        };
    }
}
