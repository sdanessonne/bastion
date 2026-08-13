using System;

namespace DockLite.Models;

public class SupportTicket
{
    public int Id { get; set; }
    public DateTime CreatedAt { get; set; }
    public string MachineName { get; set; } = "";
    public string UserName { get; set; } = "";
    public string IpAddress { get; set; } = "";
    public int? CommissariatId { get; set; }
    public string Email { get; set; } = "";
    public string Category { get; set; } = "";
    public string Priority { get; set; } = "";
    public string Subject { get; set; } = "";
    public string Description { get; set; } = "";
    public string Status { get; set; } = "Ouvert";
    public string? AssignedTo { get; set; }
    public DateTime? ResolvedAt { get; set; }
    public int? CsatScore { get; set; }
    public DateTime? CsatAt { get; set; }

    // Infos du technicien assigné (jointure users.username = tickets.assigned_to)
    public string? TechName { get; set; }
    public string? TechPhone { get; set; }
    public string? TechEmail { get; set; }
    public string? TechAvatar { get; set; }   // chemin relatif (uploads/avatars/xxx.jpg)
    public string? TechRole { get; set; }
}
