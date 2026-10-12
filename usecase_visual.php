<?php
// usecase_visual.php - UML Use Case Diagram for Talavera LGU Information Management System
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UML Use Case Diagram – Talavera LGU IMS</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f1f5f9; font-family: 'Segoe UI', sans-serif; padding: 20px; }
  h1 { text-align: center; color: #1a4f7a; margin-bottom: 6px; font-size: 1.4rem; }
  .subtitle { text-align: center; color: #555; font-size: 0.85rem; margin-bottom: 16px; }
  .btn-bar { display: flex; gap: 12px; justify-content: center; margin-bottom: 18px; }
  .btn { padding: 8px 22px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; transition: background 0.2s; }
  .btn-svg  { background: #1e40af; color: #fff; }
  .btn-png  { background: #0d3a5c; color: #fff; }
  .btn:hover { opacity: 0.85; }
  .diagram-wrap { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.10); overflow-x: auto; padding: 10px; max-width: 1200px; margin: 0 auto; }
  svg { display: block; }
  .legend-wrap { max-width: 1200px; margin: 18px auto 0; background: #fff; border-radius: 10px; padding: 14px 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); }
  .legend-title { font-weight: 700; color: #1a4f7a; margin-bottom: 10px; font-size: 1rem; }
  .legend-grid { display: flex; flex-wrap: wrap; gap: 16px 32px; }
  .legend-item { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: #334155; }
  .legend-swatch { width: 36px; height: 18px; border-radius: 9px; border: 2px solid #888; flex-shrink: 0; }
</style>
</head>
<body>
<h1>UML Use Case Diagram</h1>
<p class="subtitle">Talavera LGU Information Management System &mdash; System Use Cases &amp; Actor Interactions</p>
<div class="btn-bar">
  <button class="btn btn-svg" onclick="downloadSVG()">⬇ Download SVG</button>
  <button class="btn btn-png" onclick="downloadPNG()">⬇ Download PNG</button>
</div>
<div class="diagram-wrap">
<svg id="ucDiagram" width="1150" height="2200" xmlns="http://www.w3.org/2000/svg">
<defs>
  <!-- Arrow marker for lines -->
  <marker id="arrow" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#555"/>
  </marker>
  <marker id="arrowBlue" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#0284c7"/>
  </marker>
  <marker id="arrowGreen" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#0d3a5c"/>
  </marker>
  <marker id="arrowOrange" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
    <polygon points="0 0, 10 3.5, 0 7" fill="#ca8a04"/>
  </marker>
</defs>
