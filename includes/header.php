<!DOCTYPE html>
<html class="light" lang="es">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= $pageTitle ?? 'Gestion de Flota' ?> - Sistema de Gestion de Flota</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
body { font-family: 'Inter', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
</style>
<script>
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "outline": "#75777d",
        "outline-variant": "#c5c6cd",
        "primary-container": "#1e293b",
        "on-secondary": "#ffffff",
        "surface-container-highest": "#e0e3e5",
        "on-secondary-container": "#54647a",
        "on-surface": "#191c1e",
        "on-tertiary-fixed-variant": "#3a485c",
        "on-primary": "#ffffff",
        "tertiary-fixed-dim": "#b9c7e0",
        "on-tertiary-fixed": "#0d1c2f",
        "on-primary-fixed-variant": "#3c475a",
        "on-tertiary-container": "#8290a7",
        "secondary-container": "#d0e1fb",
        "surface-container": "#eceef0",
        "on-primary-container": "#8590a6",
        "tertiary-fixed": "#d5e3fd",
        "surface": "#f7f9fb",
        "primary": "#091426",
        "background": "#f7f9fb",
        "on-error-container": "#93000a",
        "secondary": "#505f76",
        "on-secondary-fixed": "#0b1c30",
        "inverse-primary": "#bcc7de",
        "secondary-fixed": "#d3e4fe",
        "on-surface-variant": "#45474c",
        "surface-tint": "#545f73",
        "on-background": "#191c1e",
        "on-error": "#ffffff",
        "on-tertiary": "#ffffff",
        "secondary-fixed-dim": "#b7c8e1",
        "surface-container-low": "#f2f4f6",
        "primary-fixed-dim": "#bcc7de",
        "surface-container-high": "#e6e8ea",
        "surface-container-lowest": "#ffffff",
        "inverse-surface": "#2d3133",
        "tertiary": "#051426",
        "surface-bright": "#f7f9fb",
        "inverse-on-surface": "#eff1f3",
        "error": "#ba1a1a",
        "error-container": "#ffdad6",
        "on-primary-fixed": "#111c2d",
        "surface-dim": "#d8dadc",
        "surface-variant": "#e0e3e5",
        "tertiary-container": "#1b293c",
        "on-secondary-fixed-variant": "#38485d",
        "primary-fixed": "#d8e3fb"
      },
      borderRadius: { DEFAULT: "0.125rem", lg: "0.25rem", xl: "0.5rem", full: "0.75rem" },
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
body { min-height: max(884px, 100dvh); }
canvas { max-width: 100%; }
@media (max-width: 767px) {
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .table-wrap table { min-width: 650px; }
  .modal-body { max-width: calc(100vw - 2rem) !important; }
}
</style>
</head>
<body class="bg-background text-on-surface font-body-md">
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$section = $_GET['section'] ?? '';
?>
