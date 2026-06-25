<!DOCTYPE html>
<html class="light" lang="es">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= $pageTitle ?? 'Control' ?> - Control de Combustible y Kilometraje</title>
<link rel="preconnect" href="https://cdn.tailwindcss.com"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link rel="preconnect" href="https://cdn.jsdelivr.net"/>
<link rel="dns-prefetch" href="https://cdn.tailwindcss.com"/>
<link rel="dns-prefetch" href="https://fonts.googleapis.com"/>
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
<meta name="mobile-web-app-capable" content="yes"/>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/Logo/Logo_App.png"/>
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/Logo/Logo_App.png"/>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json"/>
<style>
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
body { font-family: 'Inter', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: #091426; color: white; }
* { box-sizing: border-box; }
</style>
<script>
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "outline": "#64748b",
        "outline-variant": "#e2e8f0",
        "primary-container": "#0f172a",
        "on-secondary": "#ffffff",
        "surface-container-highest": "#e2e8f0",
        "on-secondary-container": "#475569",
        "on-surface": "#0f172a",
        "on-tertiary-fixed-variant": "#334155",
        "on-primary": "#ffffff",
        "tertiary-fixed-dim": "#cbd5e1",
        "on-tertiary-fixed": "#0f172a",
        "on-primary-fixed-variant": "#334155",
        "on-tertiary-container": "#64748b",
        "secondary-container": "#e2e8f0",
        "surface-container": "#f1f5f9",
        "on-primary-container": "#64748b",
        "tertiary-fixed": "#e2e8f0",
        "surface": "#f8fafc",
        "primary": "#0f172a",
        "background": "#f8fafc",
        "on-error-container": "#991b1b",
        "secondary": "#475569",
        "on-secondary-fixed": "#0f172a",
        "inverse-primary": "#cbd5e1",
        "secondary-fixed": "#e2e8f0",
        "on-surface-variant": "#475569",
        "surface-tint": "#475569",
        "on-background": "#0f172a",
        "on-error": "#ffffff",
        "on-tertiary": "#ffffff",
        "secondary-fixed-dim": "#cbd5e1",
        "surface-container-low": "#f1f5f9",
        "primary-fixed-dim": "#cbd5e1",
        "surface-container-high": "#e2e8f0",
        "surface-container-lowest": "#ffffff",
        "inverse-surface": "#1e293b",
        "tertiary": "#0f172a",
        "surface-bright": "#f8fafc",
        "inverse-on-surface": "#f1f5f9",
        "error": "#dc2626",
        "error-container": "#fef2f2",
        "on-primary-fixed": "#0f172a",
        "surface-dim": "#e2e8f0",
        "surface-variant": "#f1f5f9",
        "tertiary-container": "#1e293b",
        "on-secondary-fixed-variant": "#334155",
        "primary-fixed": "#e2e8f0"
      },
      borderRadius: { DEFAULT: "0.125rem", lg: "0.375rem", xl: "0.75rem", "2xl": "1rem", full: "9999px" },
      spacing: { base: "4px", xs: "0.25rem", gutter: "1rem", "margin-desktop": "2rem", xl: "2rem", sm: "0.5rem", "margin-mobile": "1rem", md: "1rem", lg: "1.5rem" },
      fontSize: {
        "headline-sm": ["20px", { lineHeight: "1.4", fontWeight: "600" }],
        "headline-lg": ["32px", { lineHeight: "1.2", letterSpacing: "-0.02em", fontWeight: "700" }],
        "body-md": ["14px", { lineHeight: "1.5", fontWeight: "400" }],
        "headline-md": ["24px", { lineHeight: "1.3", letterSpacing: "-0.01em", fontWeight: "600" }],
        "label-caps": ["12px", { lineHeight: "1", letterSpacing: "0.05em", fontWeight: "600" }],
        "body-lg": ["16px", { lineHeight: "1.6", fontWeight: "400" }],
        "data-mono": ["14px", { lineHeight: "1", fontWeight: "500" }]
      }
    }
  }
}
</script>
<style>
body { min-height: 100dvh; }
canvas { max-width: 100%; }
.logo-link { display: inline-flex; outline: none; }
.logo-img { transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.3s ease; }
.logo-img:hover { transform: scale(1.08); filter: brightness(1.15); }
.logo-img:active { transform: scale(0.95); }
@keyframes logoFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }
.logo-img { animation: logoFloat 4s ease-in-out infinite; }
.logo-link:hover .logo-img { animation: none; }

.camion-card, .card-modern { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.camion-card:hover, .card-modern:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08); }

.glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(20px) saturate(1.2); -webkit-backdrop-filter: blur(20px) saturate(1.2); border-bottom: 1px solid rgba(226, 232, 240, 0.8); }

.sidebar-modern { background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%); }
.sidebar-modern a { transition: all 0.2s ease; }
.sidebar-modern a:hover { background: rgba(255, 255, 255, 0.08); }
.sidebar-modern a.active { background: rgba(255, 255, 255, 0.12); border-left: 3px solid #e2e8f0; padding-left: 13px; }

.btn-modern { transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
.btn-modern:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15); }
.btn-modern:active { transform: translateY(0) scale(0.98); }

.modal-modern { animation: modalFadeIn 0.2s ease; }
@keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.stat-card { transition: all 0.3s ease; }
.stat-card:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }

@media (max-width: 767px) {
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table-wrap table { min-width: 650px; }
  .modal-body { max-width: calc(100vw - 2rem) !important; }
}
</style>
<script>
if ('serviceWorker' in navigator) {
navigator.serviceWorker.register('<?= BASE_URL ?>/service-worker.js');
}
</script>
</head>
<body class="bg-background text-on-surface font-body-md">
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$section = $_GET['section'] ?? '';
?>
