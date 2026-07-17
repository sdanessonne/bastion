namespace Bastion.StationBlanche;

static class Program
{
    /// <summary>
    /// Point d'entrée. « --test &lt;chemin&gt; » exerce le moteur SANS interface : c'est ce
    /// qui permet d'éprouver la détection en automatique, sans clic humain.
    /// </summary>
    [STAThread]
    static int Main(string[] args)
    {
        if (args.Length > 0 && args[0] == "--test")
            return TestHarness.Run(args).GetAwaiter().GetResult();

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm());
        return 0;
    }
}
