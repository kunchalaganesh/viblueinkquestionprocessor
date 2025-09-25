<?php
// C:\mcq-extractor\public\app\config.php

// Increase PHP time limit to avoid 120s fatal for big docs
define('PHP_TIME_LIMIT', (int)(getenv('PHP_TIME_LIMIT') ?: 300));
@set_time_limit(PHP_TIME_LIMIT);

// Where sessions (full.html, questions.json, assets/) will be written
define('SESSIONS_DIR', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'sessions');

// Binaries (override with environment variables if needed)
define('PANDOC_BIN', getenv('PANDOC_BIN') ?: 'pandoc');
// We’ll auto-detect ImageMagick in lib.php (magick or convert)
define('WMF_DPI', (int)(getenv('WMF_DPI') ?: 600)); // higher DPI for crisp small-glyph PNGs

// OCR setting stub (not used unless you wire an OCR step later)
define('OCR_MODE', getenv('OCR_MODE') ?: 'off'); // off | auto

// Math rendering: viewer uses MathJax (MathML) already — nothing to change here.
