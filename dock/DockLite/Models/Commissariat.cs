namespace DockLite.Models;

public class Commissariat
{
    public int Id { get; set; }
    public int? ParentId { get; set; }
    public string Code { get; set; } = "";
    public string Name { get; set; } = "";
    public string? Coverage { get; set; }
}
