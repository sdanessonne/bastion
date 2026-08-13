using DockPolice.Agent;
using DockPolice.Agent.Services;
using DockPolice.Agent.Workers;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Hosting.WindowsServices;
using Microsoft.Extensions.Logging;

var builder = Host.CreateApplicationBuilder(args);

// Mode service Windows (fallback console pour debug local)
builder.Services.AddWindowsService(options =>
{
    options.ServiceName = "DockPoliceAgent";
});

// Logs : Event Viewer + console (debug)
builder.Logging.AddEventLog(settings =>
{
    settings.SourceName = "DockPolice Agent";
});

// Lifetime customisé : gestion des changements de session (logon/logoff/lock)
// Démarre DockPolice.exe dans chaque session utilisateur
if (WindowsServiceHelpers.IsWindowsService())
{
    builder.Services.AddSingleton<IHostLifetime, DockSessionManager>();
}

// Config + client API
var config = AgentConfig.Load();
builder.Services.AddSingleton(config);
builder.Services.AddSingleton<ApiClient>();

// Workers de fond
builder.Services.AddHostedService<TelemetryWorker>();
builder.Services.AddHostedService<CommandWorker>();
builder.Services.AddHostedService<SystemActionWorker>();

var host = builder.Build();
host.Run();
