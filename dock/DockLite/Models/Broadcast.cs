namespace DockLite.Models;

public class Broadcast
{
    public int Id { get; set; }
    public string Message { get; set; } = "";
    public string Level { get; set; } = "info";           // info | warning | urgent
    public int SpeedPxSec { get; set; } = 80;
    public bool Dismissible { get; set; } = true;
    public string Scope { get; set; } = "commissariat";   // commissariat | department
}
