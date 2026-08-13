using System;
using System.Collections.Generic;
using System.DirectoryServices.AccountManagement;
using System.Management;
using System.Runtime.Versioning;
using System.Security.Principal;

namespace DockLite.Services;

/// <summary>
/// Énumère les membres du groupe local "Administrateurs" (BUILTIN\Administrators,
/// SID bien connu S-1-5-32-544) sur la machine — locale ou distante.
///
/// Trois niveaux de remontée :
///   1. <see cref="MemberInfo.Name"/>     — nom canonique "DOMAIN\\user" ou ".\\user"
///   2. <see cref="MemberInfo.Sid"/>      — SID Windows (utile si le compte AD a été supprimé)
///   3. <see cref="MemberInfo.IsOrphan"/> — true si on n'a pas pu résoudre le SID en nom
///
/// Implémentation : WMI Win32_GroupUser via le SID du groupe (S-1-5-32-544).
/// Pas de dépendance PowerShell, fonctionne sous compte service local.
/// </summary>
[SupportedOSPlatform("windows")]
public static class LocalAdminsService
{
    public class MemberInfo
    {
        public string  Name        { get; set; } = "";
        public string? Sid         { get; set; }
        public string  Type        { get; set; } = "Unknown"; // User|Group|Domain|Local|Unknown
        public string? Source      { get; set; }              // Local|Domain|BUILTIN|WellKnown
        public bool?   IsDisabled  { get; set; }
        public bool    IsBuiltin   { get; set; }
        public bool    IsOrphan    { get; set; }
        public DateTime? LastLogonAt { get; set; }
    }

    public class Result
    {
        public bool                Ok        { get; set; }
        public string?             Error     { get; set; }
        public string              Machine   { get; set; } = Environment.MachineName;
        public string              GroupName { get; set; } = "Administrators";
        public DateTime            CapturedAt{ get; set; } = DateTime.Now;
        public List<MemberInfo>    Members   { get; set; } = new();
    }

    /// <summary>
    /// Énumère les admins locaux. Si <paramref name="targetMachine"/> est null/vide
    /// → la machine courante. Sinon → connexion WMI à distance (RPC, port 135).
    /// </summary>
    public static Result Fetch(string? targetMachine = null)
    {
        var res = new Result { Machine = string.IsNullOrWhiteSpace(targetMachine)
            ? Environment.MachineName : targetMachine };

        try
        {
            // 1) Préfère System.DirectoryServices.AccountManagement (plus fiable, état désactivé inclus)
            //    — uniquement pour la machine locale (le distant nécessite RPC SAM, complexe)
            if (string.IsNullOrWhiteSpace(targetMachine) || targetMachine == Environment.MachineName)
            {
                FillFromAccountManagement(res);
                if (res.Members.Count > 0) { res.Ok = true; return res; }
            }

            // 2) Fallback : WMI Win32_GroupUser (fonctionne aussi distant via /machine:HOST)
            FillFromWmi(res, targetMachine);
            res.Ok = true;
        }
        catch (Exception ex)
        {
            res.Error = ex.Message;
            res.Ok    = res.Members.Count > 0; // partial : ok si on a quand même remonté quelques membres
        }

        return res;
    }

    // ---------------------- Méthode 1 : AccountManagement (locale) ----------------------
    private static void FillFromAccountManagement(Result res)
    {
        try
        {
            using var ctx = new PrincipalContext(ContextType.Machine);
            // Le groupe BUILTIN\Administrators a le SID bien connu S-1-5-32-544 dans toutes les langues.
            using var grp = GroupPrincipal.FindByIdentity(ctx, IdentityType.Sid, "S-1-5-32-544");
            if (grp == null) return;

            res.GroupName = grp.SamAccountName ?? "Administrators";

            foreach (var p in grp.GetMembers(recursive: false))
            {
                var info = new MemberInfo
                {
                    Sid       = p.Sid?.Value,
                    Name      = NormalizeName(p),
                    Type      = ClassifyType(p),
                    Source    = ClassifySource(p),
                    IsBuiltin = IsBuiltinSid(p.Sid?.Value),
                };

                // Pour les User locaux, on peut récupérer l'état "disabled"
                if (p is UserPrincipal u)
                {
                    try { info.IsDisabled = !(u.Enabled ?? true); }   catch { }
                    try { info.LastLogonAt = u.LastLogon; }            catch { }
                }
                res.Members.Add(info);
                p.Dispose();
            }
        }
        catch
        {
            // Silencieux : on retombera sur WMI. Le cas typique : le service ne peut pas
            // ouvrir SAM (rare en SYSTEM mais possible si politique restrictive).
        }
    }

    // ---------------------- Méthode 2 : WMI (locale ou distante) ----------------------
    private static void FillFromWmi(Result res, string? targetMachine)
    {
        var scopePath = string.IsNullOrWhiteSpace(targetMachine)
            ? @"\\.\root\cimv2"
            : $@"\\{targetMachine}\root\cimv2";
        var scope = new ManagementScope(scopePath);
        scope.Connect();

        // Récupère le nom du groupe Administrators via son SID bien connu
        var grpQuery = new ObjectQuery(
            "SELECT Name FROM Win32_Group WHERE SID = 'S-1-5-32-544' AND LocalAccount = TRUE"
        );
        string adminGroupName = "Administrators";
        using (var grpSearcher = new ManagementObjectSearcher(scope, grpQuery))
        {
            foreach (ManagementObject g in grpSearcher.Get())
            {
                adminGroupName = (g["Name"] as string) ?? "Administrators";
                break;
            }
        }
        res.GroupName = adminGroupName;

        // Énumère les membres du groupe via la classe d'association Win32_GroupUser
        var hostName = string.IsNullOrWhiteSpace(targetMachine)
            ? Environment.MachineName : targetMachine;
        var assocQuery = new ObjectQuery(
            $"ASSOCIATORS OF {{Win32_Group.Domain='{hostName}',Name='{adminGroupName.Replace("'","''")}'}} " +
            "WHERE AssocClass=Win32_GroupUser Role=GroupComponent"
        );
        using var assocSearcher = new ManagementObjectSearcher(scope, assocQuery);

        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (ManagementObject m in assocSearcher.Get())
        {
            try
            {
                var domain = m["Domain"] as string ?? "";
                var name   = m["Name"]   as string ?? "";
                if (string.IsNullOrEmpty(name)) continue;

                var canonical = string.IsNullOrEmpty(domain) ? name : $"{domain}\\{name}";
                if (!seen.Add(canonical)) continue;

                string? sid = null;
                try { sid = m["SID"] as string; } catch { }

                var info = new MemberInfo
                {
                    Name      = canonical,
                    Sid       = sid,
                    Type      = (m.ClassPath?.ClassName ?? "").Equals("Win32_UserAccount", StringComparison.OrdinalIgnoreCase)
                                  ? "User" : "Group",
                    Source    = ClassifySourceFromDomain(domain, sid, hostName),
                    IsBuiltin = IsBuiltinSid(sid),
                };

                // État du compte si dispo (User local seulement)
                try
                {
                    if (m["Disabled"] is bool db) info.IsDisabled = db;
                }
                catch { }

                res.Members.Add(info);
            }
            catch { /* membre suivant */ }
        }

        // Détection des orphelins : si un SID n'a pas résolu côté Win32_GroupUser,
        // on tente une seconde passe via Win32_SID directement.
        DetectOrphans(res, scope, adminGroupName);
    }

    private static void DetectOrphans(Result res, ManagementScope scope, string adminGroupName)
    {
        // Win32_GroupUser ne renvoie que les SID résolus. Pour récupérer aussi les
        // orphelins (compte AD supprimé) il faut interroger directement la classe
        // d'association non-filtrée — pas trivial sans WMI méta. On marque simplement
        // les SID au format "S-1-5-21-*-*-*-*" qui ressemblent à des SID AD non résolus.
        foreach (var m in res.Members)
        {
            if (string.IsNullOrEmpty(m.Sid)) continue;
            // Heuristique : le nom est juste le SID lui-même (Windows fait ça quand il ne résout pas)
            if (m.Name.EndsWith(m.Sid, StringComparison.OrdinalIgnoreCase) ||
                m.Name.StartsWith("S-1-", StringComparison.OrdinalIgnoreCase))
            {
                m.IsOrphan = true;
            }
        }
    }

    // ---------------------- Helpers ----------------------
    private static string NormalizeName(Principal p)
    {
        // p.SamAccountName peut être nul pour des principals exotiques
        var name = p.SamAccountName ?? p.Name ?? p.Sid?.Value ?? "?";
        // Préfixe domaine/contexte pour éviter les ambiguïtés
        if (name.Contains('\\')) return name;
        if (p.ContextType == ContextType.Machine) return @".\" + name;
        if (p.Context.Name != null && !name.Contains('\\'))
            return $"{p.Context.Name.Split('.')[0]}\\{name}";
        return name;
    }

    private static string ClassifyType(Principal p) => p switch
    {
        UserPrincipal  _ => "User",
        GroupPrincipal _ => "Group",
        ComputerPrincipal _ => "Computer",
        _                => "Unknown"
    };

    private static string ClassifySource(Principal p)
    {
        if (p.ContextType == ContextType.Machine) return "Local";
        if (IsBuiltinSid(p.Sid?.Value))            return "BUILTIN";
        return "Domain";
    }

    private static string ClassifySourceFromDomain(string? domain, string? sid, string localHost)
    {
        if (IsBuiltinSid(sid))                                                         return "BUILTIN";
        if (string.Equals(domain, localHost, StringComparison.OrdinalIgnoreCase))      return "Local";
        if (string.Equals(domain, "NT AUTHORITY", StringComparison.OrdinalIgnoreCase)) return "WellKnown";
        if (!string.IsNullOrEmpty(domain))                                             return "Domain";
        return "Unknown";
    }

    private static bool IsBuiltinSid(string? sid)
    {
        if (string.IsNullOrEmpty(sid)) return false;
        // S-1-5-32-* = BUILTIN
        // S-1-5-18  = LocalSystem ; S-1-5-19/20 = LocalService/NetworkService
        // S-1-5-21-...-500 = compte Administrator local "intégré"
        return sid.StartsWith("S-1-5-32-", StringComparison.OrdinalIgnoreCase)
            || sid == "S-1-5-18" || sid == "S-1-5-19" || sid == "S-1-5-20"
            || sid.EndsWith("-500", StringComparison.OrdinalIgnoreCase);
    }
}
