<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ===== ULTRA PERFORMANCE CONFIGURATION =====
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '8G');
ignore_user_abort(true);
ini_set('output_buffering', '4096');

define('FFMPEG_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffmpeg.exe');
define('FFPROBE_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffprobe.exe');
define('MAX_RETRIES', 3);
define('PROGRESS_UPDATE_INTERVAL', 0.5);
define('VIDEO_TIMEOUT', 28800); // 8 hours for large videos
define('STUCK_THRESHOLD', 600); // 10 minutes no output = stuck

class VideoMergerUltraV2
{
  private $inputPath;
  private $outputPath;
  private $tempWorkPath;
  private $logFile;
  private $progressFile;
  private $processIdFile;
  private $currentProcessPid = null;
  private $lastProgressUpdate = 0;
  private $logBuffer = [];
  private $logBufferSize = 50;
  private $outputVideoPath = '';
  private $sanitizationMap = [];
  private $shouldCleanup = false; // CRITICAL: Control cleanup behavior

  public function __construct($inputPath, $outputPath)
  {
    $this->inputPath = rtrim($inputPath, '\\/');
    $this->outputPath = rtrim($outputPath, '\\/');
    $this->tempWorkPath = $this->outputPath . DIRECTORY_SEPARATOR . '_temp_work_' . uniqid();
    $this->logFile = $this->outputPath . DIRECTORY_SEPARATOR . 'merge_log.txt';
    $this->progressFile = $this->outputPath . DIRECTORY_SEPARATOR . 'progress.json';
    $this->processIdFile = $this->outputPath . DIRECTORY_SEPARATOR . 'process_id.txt';
  }

  private function log($message, $forceWrite = false)
  {
    $timestamp = date('Y-m-d H:i:s');
    $this->logBuffer[] = "[$timestamp] $message";

    if (count($this->logBuffer) >= $this->logBufferSize || $forceWrite) {
      $this->flushLogs();
    }
  }

  private function flushLogs()
  {
    if (!empty($this->logBuffer)) {
      @file_put_contents($this->logFile, implode("\n", $this->logBuffer) . "\n", FILE_APPEND | LOCK_EX);
      $this->logBuffer = [];
    }
  }

  public function __destruct()
  {
    $this->flushLogs();

    // ONLY cleanup if explicitly set
    if ($this->shouldCleanup) {
      $this->cleanupTempFolder();
    }
  }

  // ===== AGGRESSIVE FILE NAME SANITIZATION =====
  private function aggressiveSanitize($filename)
  {
    // Get extension first
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

    // Remove BOM
    $nameWithoutExt = preg_replace('/^\xEF\xBB\xBF/', '', $nameWithoutExt);

    // Remove ALL emojis and unicode symbols
    $nameWithoutExt = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $nameWithoutExt);

    // ONLY keep: a-z, A-Z, 0-9, underscore, dash, dot
    // Replace everything else with underscore
    $nameWithoutExt = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $nameWithoutExt);

    // Replace multiple underscores/dashes with single one
    $nameWithoutExt = preg_replace('/[_-]+/', '_', $nameWithoutExt);

    // Remove leading/trailing underscores, dashes, dots
    $nameWithoutExt = trim($nameWithoutExt, '_-. ');

    // If empty after sanitization, generate unique name
    if (empty($nameWithoutExt) || $nameWithoutExt === '') {
      $nameWithoutExt = 'file_' . uniqid() . '_' . time();
    }

    // Limit length
    if (strlen($nameWithoutExt) > 150) {
      $nameWithoutExt = substr($nameWithoutExt, 0, 150);
    }

    $sanitized = $nameWithoutExt . '.' . $extension;

    // Final check: ensure no problematic characters remain
    if (preg_match('/[^a-zA-Z0-9_\-.]/', $sanitized)) {
      $this->log("WARNING: Sanitization failed for: $filename, using fallback");
      $sanitized = 'file_' . md5($filename) . '.' . $extension;
    }

    return $sanitized;
  }

  private function createTempWorkFolder()
  {
    if (!is_dir($this->tempWorkPath)) {
      if (!@mkdir($this->tempWorkPath, 0777, true)) {
        throw new Exception("Cannot create temp work folder: {$this->tempWorkPath}");
      }
      $this->log("✅ Created temp work folder: {$this->tempWorkPath}");
    }
    return true;
  }

  private function cleanupTempFolder()
  {
    if (!is_dir($this->tempWorkPath)) {
      return;
    }

    $this->log("🧹 Cleaning up temp folder...");

    $files = glob($this->tempWorkPath . DIRECTORY_SEPARATOR . '*');
    $deleted = 0;

    foreach ($files as $file) {
      if (is_file($file)) {
        if (@unlink($file)) {
          $deleted++;
        }
      }
    }

    @rmdir($this->tempWorkPath);
    $this->log("✅ Deleted $deleted temp files, removed temp folder");
  }

  // Manual cleanup method for explicit calls
  public function forceCleanup()
  {
    $this->shouldCleanup = true;
    $this->cleanupTempFolder();
  }

  // ===== VALIDATE FFMPEG =====
  private function validateFFmpeg()
  {
    if (!file_exists(FFMPEG_PATH)) {
      throw new Exception("FFmpeg not found at: " . FFMPEG_PATH);
    }
    if (!file_exists(FFPROBE_PATH)) {
      throw new Exception("FFprobe not found at: " . FFPROBE_PATH);
    }

    exec('"' . FFMPEG_PATH . '" -version 2>&1', $output, $returnCode);
    if ($returnCode !== 0) {
      throw new Exception("FFmpeg execution failed. Check permissions.");
    }

    $this->log("✅ FFmpeg validated: " . (isset($output[0]) ? substr($output[0], 0, 100) : 'OK'));
    return true;
  }

  // ===== ENHANCED PROCESS CONTROL =====
  private function killAllFFmpegProcesses()
  {
    $this->log("🔪 Killing all FFmpeg processes...");

    // Method 1: taskkill with force
    exec('taskkill /F /IM ffmpeg.exe /T 2>&1', $output1);
    $this->log("Taskkill result: " . implode("; ", $output1));

    // Method 2: WMIC (more reliable on Windows 11)
    exec('wmic process where name="ffmpeg.exe" delete 2>&1', $output2);

    sleep(2);

    // Verify
    exec('tasklist /FI "IMAGENAME eq ffmpeg.exe" 2>&1', $checkOutput);
    $stillRunning = stripos(implode("\n", $checkOutput), 'ffmpeg.exe') !== false;

    if (!$stillRunning) {
      $this->log("✅ All FFmpeg processes terminated");
    } else {
      $this->log("⚠️ Some FFmpeg processes may still be running, trying harder...");
      exec('taskkill /F /FI "IMAGENAME eq ffmpeg.exe" 2>&1');
      sleep(1);
    }
  }

  private function updateProgress($progress, $status = '', $currentTime = 0, $totalDuration = 0)
  {
    $now = microtime(true);

    if ($now - $this->lastProgressUpdate < PROGRESS_UPDATE_INTERVAL && $progress < 100) {
      return;
    }

    $this->lastProgressUpdate = $now;

    $fileSize = 0;
    if (!empty($this->outputVideoPath) && file_exists($this->outputVideoPath)) {
      $fileSize = filesize($this->outputVideoPath);
    }

    $data = [
      'progress' => round($progress, 2),
      'status' => $status,
      'timestamp' => time(),
      'current_time' => round($currentTime, 2),
      'total_duration' => round($totalDuration, 2),
      'file_size' => $fileSize
    ];

    @file_put_contents($this->progressFile, json_encode($data), LOCK_EX);
  }

  private function saveProcessPid($pid)
  {
    @file_put_contents($this->processIdFile, $pid, LOCK_EX);
    $this->currentProcessPid = $pid;
    $this->log("📢 Saved FFmpeg PID: $pid");
  }

  public function stopCurrentProcess()
  {
    $this->log("🛑 Initiating process termination...", true);

    // Kill by saved PID
    if (file_exists($this->processIdFile)) {
      $pid = @file_get_contents($this->processIdFile);
      if ($pid && is_numeric($pid)) {
        $this->log("Killing PID: $pid and all children");
        exec("taskkill /F /T /PID $pid 2>&1", $output);
        $this->log("Taskkill PID result: " . implode("; ", $output));
      }
      @unlink($this->processIdFile);
    }

    // Kill all FFmpeg as backup
    $this->killAllFFmpegProcesses();

    // Cleanup
    @unlink($this->progressFile);
    $this->shouldCleanup = true;
    $this->cleanupTempFolder();
    $this->flushLogs();
  }

  public function getProgress($outputPath = '', $outputName = '')
  {
    if (file_exists($this->progressFile)) {
      $content = @file_get_contents($this->progressFile);
      if ($content) {
        $data = json_decode($content, true);

        // Check for stalled process
        if (isset($data['timestamp']) && (time() - $data['timestamp']) > STUCK_THRESHOLD) {
          return [
            'progress' => $data['progress'] ?? 0,
            'status' => 'timeout',
            'message' => 'Progress stalled for ' . round(STUCK_THRESHOLD / 60) . ' minutes',
            'file_size' => $data['file_size'] ?? 0
          ];
        }

        // Update file size if path provided
        if (!empty($outputPath) && !empty($outputName)) {
          $videoPath = $outputPath . DIRECTORY_SEPARATOR . $outputName . '.mp4';
          if (file_exists($videoPath)) {
            $data['file_size'] = filesize($videoPath);
          }
        }

        return $data;
      }
    }
    return ['progress' => 0, 'status' => 'not_started', 'file_size' => 0];
  }

  private function checkDiskSpace($estimatedSize)
  {
    $drive = substr($this->outputPath, 0, 2);
    $freeSpace = @disk_free_space($drive);

    if ($freeSpace === false) {
      $this->log("⚠️ Cannot check disk space");
      return true;
    }

    $required = $estimatedSize * 2; // 2x buffer for temp files
    $freeGB = $freeSpace / (1024 * 1024 * 1024);
    $requiredGB = $required / (1024 * 1024 * 1024);

    $this->log("💾 Disk space: " . round($freeGB, 2) . "GB free, need ~" . round($requiredGB, 2) . "GB");

    if ($freeSpace < $required) {
      throw new Exception("Không đủ dung lượng đĩa! Cần " . round($requiredGB, 2) . "GB, chỉ còn " . round($freeGB, 2) . "GB");
    }

    return true;
  }

  private function getVideoDuration($videoPath)
  {
    static $cache = [];
    if (isset($cache[$videoPath])) {
      return $cache[$videoPath];
    }

    $command = sprintf(
      '"%s" -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
      FFPROBE_PATH,
      $videoPath
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
      $duration = trim($output[0]);
      if (is_numeric($duration) && $duration > 0) {
        $cache[$videoPath] = floatval($duration);
        return $cache[$videoPath];
      }
    }

    return 0;
  }

  private function isValidVideo($videoPath)
  {
    if (!file_exists($videoPath)) {
      return false;
    }

    $fileSize = filesize($videoPath);
    if ($fileSize < 1024) {
      return false;
    }

    $duration = $this->getVideoDuration($videoPath);
    return $duration > 0;
  }

  // ===== SCAN AND SANITIZE =====
  public function scanFiles()
  {
    $this->log("=== 🚀 ULTRA SCAN WITH AGGRESSIVE SANITIZATION ===", true);
    $scanStart = microtime(true);

    if (!is_dir($this->inputPath)) {
      throw new Exception("Input directory not found: {$this->inputPath}");
    }

    if (!is_dir($this->outputPath)) {
      if (!@mkdir($this->outputPath, 0777, true)) {
        throw new Exception("Cannot create output directory: {$this->outputPath}");
      }
    }

    $this->validateFFmpeg();
    $this->createTempWorkFolder();

    // DO NOT set shouldCleanup here - temp folder must persist after scan
    $this->log("🔒 Temp folder will persist for merge operations");

    $files = scandir($this->inputPath);
    $videos = [];
    $srt_en = [];
    $srt_vi = [];
    $srt_unknown = [];
    $skippedVideos = [];
    $sanitizedCount = 0;
    $totalSize = 0;
    $totalDuration = 0;

    $this->log("📁 Scanning: {$this->inputPath}");
    $this->log("📁 Temp work: {$this->tempWorkPath}");

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      $originalPath = $this->inputPath . DIRECTORY_SEPARATOR . $file;
      if (!is_file($originalPath)) continue;

      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

      // Sanitize filename
      $sanitizedName = $this->aggressiveSanitize($file);
      $needsSanitization = ($sanitizedName !== $file);

      if ($needsSanitization) {
        $sanitizedCount++;
        $this->log("🧹 Sanitize: '$file' → '$sanitizedName'");
      }

      // Store mapping
      $this->sanitizationMap[$file] = $sanitizedName;

      // Copy to temp folder
      $tempPath = $this->tempWorkPath . DIRECTORY_SEPARATOR . $sanitizedName;

      // Skip if already exists (duplicate sanitized name)
      if (file_exists($tempPath)) {
        $this->log("⚠️ SKIP duplicate: $sanitizedName (already exists in temp)");
        continue;
      }

      if (!@copy($originalPath, $tempPath)) {
        $this->log("❌ FAIL copy: $file → temp folder");
        continue;
      }

      $nameWithoutExt = pathinfo($sanitizedName, PATHINFO_FILENAME);

      // Process videos
      if ($ext === 'mp4') {
        if (preg_match('/^(\d+)/', $nameWithoutExt, $matches)) {
          $order = intval($matches[1]);
          $fileSize = filesize($tempPath);

          $this->log("🔹 Validating: $sanitizedName (" . $this->formatBytes($fileSize) . ")");

          if ($this->isValidVideo($tempPath)) {
            $duration = $this->getVideoDuration($tempPath);

            $videos[$order] = [
              'file' => $sanitizedName,
              'original_file' => $file,
              'order' => $order,
              'path' => $tempPath,
              'size' => $fileSize,
              'duration' => $duration,
              'sanitized' => $needsSanitization
            ];

            $totalSize += $fileSize;
            $totalDuration += $duration;

            $this->log("  ✅ Valid: [$order] $sanitizedName - " . round($duration, 2) . "s");
          } else {
            $skippedVideos[] = $file;
            @unlink($tempPath);
            $this->log("  ✗ SKIPPED: $file (invalid video)");
          }
        }
      }
      // Process SRT files
      elseif ($ext === 'srt') {
        $isEnglish = preg_match('/_en$/i', $nameWithoutExt);
        $isVietnamese = preg_match('/_vi$/i', $nameWithoutExt);

        if ($isEnglish) {
          $baseNameWithoutLang = preg_replace('/_en$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_en[$order] = [
              'file' => $sanitizedName,
              'original_file' => $file,
              'order' => $order,
              'path' => $tempPath,
              'sanitized' => $needsSanitization
            ];
          }
        } elseif ($isVietnamese) {
          $baseNameWithoutLang = preg_replace('/_vi$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_vi[$order] = [
              'file' => $sanitizedName,
              'original_file' => $file,
              'order' => $order,
              'path' => $tempPath,
              'sanitized' => $needsSanitization
            ];
          }
        } else {
          if (preg_match('/^(\d+)/', $nameWithoutExt, $matches)) {
            $order = intval($matches[1]);
            $srt_unknown[$order] = [
              'file' => $sanitizedName,
              'original_file' => $file,
              'order' => $order,
              'path' => $tempPath,
              'sanitized' => $needsSanitization
            ];
          }
        }
      } else {
        // Not video/srt, delete from temp
        @unlink($tempPath);
      }
    }

    ksort($videos);
    ksort($srt_en);
    ksort($srt_vi);
    ksort($srt_unknown);

    if (empty($videos)) {
      $this->shouldCleanup = true;
      throw new Exception("No valid videos found in input folder!");
    }

    $this->checkDiskSpace($totalSize);

    // Verify temp files exist after scan
    $tempFileCount = count(glob($this->tempWorkPath . DIRECTORY_SEPARATOR . '*.mp4'));
    $this->log("🔍 Verification: $tempFileCount MP4 files in temp folder");

    if ($tempFileCount === 0) {
      throw new Exception("Temp folder is empty after scan! Something deleted the files.");
    }

    $scanTime = round(microtime(true) - $scanStart, 2);
    $this->log("✅ Scan complete in {$scanTime}s: " . count($videos) . " videos");
    $this->log("🧹 Sanitized: $sanitizedCount files");
    $this->log("📊 Total: " . $this->formatBytes($totalSize) . ", " . round($totalDuration, 2) . "s");
    $this->log("🔒 Temp folder preserved for merge: {$this->tempWorkPath}");
    $this->log("=== SCAN FINISHED ===\n", true);

    $srt_all = [];
    foreach ($srt_en as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'path' => $data['path'], 'type' => 'en', 'order' => $order];
    }
    foreach ($srt_vi as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'path' => $data['path'], 'type' => 'vi', 'order' => $order];
    }
    foreach ($srt_unknown as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'path' => $data['path'], 'type' => 'unknown', 'order' => $order];
    }

    return [
      'videos' => array_values($videos),
      'srt_all' => $srt_all,
      'srt_info' => [
        'en' => count($srt_en),
        'vi' => count($srt_vi),
        'unknown' => count($srt_unknown),
        'total' => count($srt_en) + count($srt_vi) + count($srt_unknown)
      ],
      'skipped' => $skippedVideos,
      'total_duration' => $totalDuration,
      'estimated_size' => $totalSize * 1.1,
      'stats' => [
        'total_size' => $this->formatBytes($totalSize),
        'total_duration' => $this->formatTime(round($totalDuration)),
        'estimated_output' => $this->formatBytes($totalSize * 1.1),
        'sanitized_count' => $sanitizedCount,
        'temp_folder' => $this->tempWorkPath
      ]
    ];
  }

  // ===== MERGE SRT =====
  public function mergeAllSRT($srtData, $videoData, $outputName = 'merged_output')
  {
    $this->log("=== 📝 MERGING ALL SRT ===");

    $outputName = $this->aggressiveSanitize($outputName);

    $srt_by_type = ['en' => [], 'vi' => [], 'unknown' => []];

    foreach ($srtData as $srt) {
      $type = $srt['type'];
      $srt_by_type[$type][] = $srt;
    }

    $merged_count = 0;

    foreach (['en', 'vi', 'unknown'] as $type) {
      if (!empty($srt_by_type[$type])) {
        $this->log("📝 Merging " . count($srt_by_type[$type]) . " $type SRT files");
        $this->mergeSRT($srt_by_type[$type], $videoData, $outputName, $type === 'unknown' ? '' : $type);
        $merged_count++;
      }
    }

    $this->log("✅ SRT MERGE COMPLETE: $merged_count types\n", true);
    return $merged_count;
  }

  public function mergeSRT($srtData, $videoData, $outputName = 'merged_output', $lang = 'en')
  {
    $this->log("=== 📝 MERGING SRT ($lang) ===");

    if (empty($srtData)) {
      throw new Exception("No SRT files to merge");
    }

    $mergedContent = '';
    $subtitleCounter = 1;
    $timeOffset = 0;

    foreach ($srtData as $srtItem) {
      $srtPath = $srtItem['path'];

      if (!file_exists($srtPath)) {
        $this->log("⚠️ SKIP: SRT not found: " . $srtItem['file']);
        continue;
      }

      $content = @file_get_contents($srtPath);
      if ($content === false) {
        $this->log("⚠️ SKIP: Cannot read SRT: " . $srtItem['file']);
        continue;
      }

      $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

      $subtitles = $this->parseSRT($content);
      $this->log("  ✅ " . $srtItem['file'] . ": " . count($subtitles) . " subs (offset: " . round($timeOffset, 2) . "s)");

      foreach ($subtitles as $subtitle) {
        $startTime = $this->addTimeOffset($subtitle['start'], $timeOffset);
        $endTime = $this->addTimeOffset($subtitle['end'], $timeOffset);

        $mergedContent .= $subtitleCounter . "\n";
        $mergedContent .= $startTime . ' --> ' . $endTime . "\n";
        $mergedContent .= $subtitle['text'] . "\n\n";

        $subtitleCounter++;
      }

      $order = $srtItem['order'];
      foreach ($videoData as $video) {
        if ($video['order'] == $order) {
          $timeOffset += $video['duration'];
          break;
        }
      }
    }

    $suffix = $lang === 'en' ? '_en' : ($lang === 'vi' ? '_vi' : '');
    $outputSRT = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . $suffix . '.srt';

    $bom = "\xEF\xBB\xBF";
    if (!@file_put_contents($outputSRT, $bom . trim($mergedContent), LOCK_EX)) {
      throw new Exception("Cannot write SRT file: $outputSRT");
    }

    $this->log("✅ SRT output: $outputSRT (" . ($subtitleCounter - 1) . " subtitles)");
    return $outputSRT;
  }

  private function parseSRT($content)
  {
    $subtitles = [];
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $blocks = preg_split('/\n\s*\n/', trim($content));

    foreach ($blocks as $block) {
      $block = trim($block);
      if (empty($block)) continue;

      $lines = explode("\n", $block);
      if (count($lines) < 3) continue;

      $timelineLine = $lines[1] ?? '';

      if (preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $timelineLine, $matches)) {
        $subtitles[] = [
          'start' => $matches[1],
          'end' => $matches[2],
          'text' => trim(implode("\n", array_slice($lines, 2)))
        ];
      }
    }

    return $subtitles;
  }

  private function addTimeOffset($timestamp, $offsetSeconds)
  {
    if (preg_match('/(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $timestamp, $matches)) {
      $totalMs = (intval($matches[1]) * 3600 + intval($matches[2]) * 60 + intval($matches[3])) * 1000 + intval($matches[4]);
      $totalMs += round($offsetSeconds * 1000);
      $totalMs = max(0, $totalMs);

      $ms = $totalMs % 1000;
      $totalSeconds = floor($totalMs / 1000);
      $s = $totalSeconds % 60;
      $m = floor($totalSeconds / 60) % 60;
      $h = floor($totalSeconds / 3600);

      return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    return $timestamp;
  }

  // ===== MERGE VIDEOS =====
  public function mergeVideos($videoData, $outputName = 'merged_output', $totalDuration = 0)
  {
    $this->log("=== 🎥 ULTRA FAST VIDEO MERGE STARTED ===", true);
    $mergeStart = microtime(true);

    $outputName = $this->aggressiveSanitize($outputName);

    if (empty($videoData)) {
      throw new Exception("No videos to merge");
    }

    // Verify temp folder still exists
    if (!is_dir($this->tempWorkPath)) {
      throw new Exception("Temp work folder not found! Path: {$this->tempWorkPath}");
    }

    $tempFileCount = count(glob($this->tempWorkPath . DIRECTORY_SEPARATOR . '*.mp4'));
    $this->log("🔍 Pre-merge verification: $tempFileCount MP4 files in temp folder");

    if ($tempFileCount === 0) {
      throw new Exception("Temp folder is empty before merge! All files were deleted somehow.");
    }

    $validVideos = [];
    $calculatedDuration = 0;

    foreach ($videoData as $video) {
      $videoPath = $video['path'];

      if (!file_exists($videoPath)) {
        $this->log("⚠️ SKIP: File not found: " . $video['file'] . " at " . $videoPath);

        // Try to find in temp folder by filename
        $filename = basename($videoPath);
        $altPath = $this->tempWorkPath . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($altPath)) {
          $this->log("  ✓ Found alternative path: $altPath");
          $videoPath = $altPath;
        } else {
          continue;
        }
      }

      $duration = $video['duration'] ?? $this->getVideoDuration($videoPath);

      if ($duration <= 0) {
        $this->log("⚠️ SKIP: Invalid duration: " . $video['file']);
        continue;
      }

      $validVideos[] = [
        'path' => $videoPath,
        'file' => $video['file'],
        'duration' => $duration
      ];
      $calculatedDuration += $duration;
      $this->log("✅ " . $video['file'] . " - " . round($duration, 2) . "s");
    }

    if (empty($validVideos)) {
      throw new Exception("No valid videos found after validation! Check temp folder: {$this->tempWorkPath}");
    }

    $totalDuration = $totalDuration > 0 ? $totalDuration : $calculatedDuration;
    $this->log("📊 Total duration: " . round($totalDuration, 2) . "s (" . count($validVideos) . " videos)");

    $listFile = $this->tempWorkPath . DIRECTORY_SEPARATOR . 'filelist.txt';
    $listContent = '';

    foreach ($validVideos as $video) {
      $videoPath = $video['path'];
      $normalizedPath = str_replace('/', '\\', $videoPath);
      $escapedPath = str_replace("'", "'\\''", $normalizedPath);
      $listContent .= "file '$escapedPath'\n";
    }

    if (!@file_put_contents($listFile, $listContent, LOCK_EX)) {
      throw new Exception("Cannot create filelist: $listFile");
    }

    $this->log("📄 Filelist created: $listFile");

    $outputVideo = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . '.mp4';
    $this->outputVideoPath = $outputVideo;

    if (file_exists($outputVideo)) {
      @unlink($outputVideo);
      $this->log("🗑️ Removed old output file");
    }

    $this->updateProgress(0.1, 'starting', 0, $totalDuration);

    $command = sprintf(
      '"%s" -f concat -safe 0 -i "%s" ' .
        '-c copy ' .
        '-fflags +genpts ' .
        '-avoid_negative_ts make_zero ' .
        '-max_muxing_queue_size 9999 ' .
        '-analyzeduration 100M -probesize 100M ' .
        '-metadata title="%s" ' .
        '-movflags +faststart ' .
        '-progress pipe:1 -y "%s" 2>&1',
      FFMPEG_PATH,
      $listFile,
      addslashes($outputName),
      $outputVideo
    );

    $this->log("🚀 FFmpeg command:");
    $this->log($command, true);

    $descriptorspec = [
      0 => ["pipe", "r"],
      1 => ["pipe", "w"],
      2 => ["pipe", "w"]
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
      throw new Exception("Cannot start FFmpeg process");
    }

    $status = proc_get_status($process);
    if ($status && isset($status['pid'])) {
      $this->saveProcessPid($status['pid']);
    } else {
      throw new Exception("Cannot get FFmpeg PID");
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $lastProgress = 0;
    $errorOutput = '';
    $lastHeartbeat = time();
    $progressStartTime = microtime(true);
    $lastLogTime = 0;
    $lastOutputTime = time();

    while (true) {
      $status = proc_get_status($process);

      if (!$status['running']) {
        break;
      }

      if (time() - $lastHeartbeat >= 30) {
        $elapsed = round(microtime(true) - $progressStartTime);
        $fileSize = file_exists($outputVideo) ? filesize($outputVideo) : 0;
        $fileSizeMB = round($fileSize / (1024 * 1024), 2);
        $this->log("💗 Processing... " . round($lastProgress, 1) . "% ({$elapsed}s, {$fileSizeMB}MB)");
        $lastHeartbeat = time();
        $this->updateProgress($lastProgress, 'encoding', 0, $totalDuration);
      }

      $output = fgets($pipes[1]);
      $error = fgets($pipes[2]);

      if ($error !== false && $error !== '') {
        $errorOutput .= $error;
        $lastOutputTime = time();
      }

      if ($output !== false && $output !== '') {
        $lastOutputTime = time();
        $currentTime = 0;

        if (preg_match('/out_time_us=(\d+)/', $output, $matches)) {
          $currentTime = intval($matches[1]) / 1000000;
        } elseif (preg_match('/out_time_ms=(\d+)/', $output, $matches)) {
          $currentTime = intval($matches[1]) / 1000;
        } elseif (preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $output, $matches)) {
          $currentTime = intval($matches[1]) * 3600 + intval($matches[2]) * 60 + floatval($matches[3]);
        }

        if ($currentTime > 0 && $totalDuration > 0) {
          $progress = min(($currentTime / $totalDuration) * 100, 99.9);

          if ($progress > $lastProgress + 0.5) {
            $this->updateProgress($progress, 'encoding', $currentTime, $totalDuration);
            $lastProgress = $progress;

            if (time() - $lastLogTime >= 15) {
              $this->log("Progress: " . round($progress, 1) . "% (" . round($currentTime, 1) . "s / " . round($totalDuration, 1) . "s)");
              $lastLogTime = time();
            }
          }
        }
      }

      if ($output === false && $error === false) {
        if (time() - $lastOutputTime > STUCK_THRESHOLD) {
          $this->log("⚠️ No output from FFmpeg for " . round(STUCK_THRESHOLD / 60) . " minutes");

          $currentSize = file_exists($outputVideo) ? filesize($outputVideo) : 0;
          if ($currentSize > 0) {
            $this->log("✅ Output exists ({$this->formatBytes($currentSize)}). Continuing...");
            $lastOutputTime = time();
          } else {
            $this->log("❌ FFmpeg stuck - no output");
            throw new Exception("FFmpeg stuck: No output for " . round(STUCK_THRESHOLD / 60) . " minutes");
          }
        }

        usleep(50000);
      }

      if (microtime(true) - $progressStartTime > VIDEO_TIMEOUT) {
        $this->log("⚠️ TIMEOUT: Process exceeded " . round(VIDEO_TIMEOUT / 3600) . " hours", true);
        $this->stopCurrentProcess();
        throw new Exception("Process timeout after " . round(VIDEO_TIMEOUT / 3600) . " hours");
      }
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    $mergeTime = round(microtime(true) - $mergeStart, 2);

    $this->log("⏱️ Merge time: {$mergeTime}s");
    $this->log("📢 FFmpeg return code: $returnCode");

    @unlink($this->processIdFile);

    if ($returnCode !== 0) {
      $errorLines = explode("\n", $errorOutput);
      $relevantErrors = array_filter($errorLines, function ($line) {
        return stripos($line, 'error') !== false || stripos($line, 'failed') !== false;
      });

      $errorMsg = "FFmpeg failed with code $returnCode";
      if (!empty($relevantErrors)) {
        $lastError = end($relevantErrors);
        $errorMsg .= "\nLast error: $lastError";
      }
      $this->log("❌ ERROR: $errorMsg", true);
      throw new Exception($errorMsg);
    }

    if (!file_exists($outputVideo)) {
      throw new Exception("Output file not created. Check merge_log.txt");
    }

    $fileSize = filesize($outputVideo);
    if ($fileSize < 1024 * 1024) {
      throw new Exception("Output file too small ($fileSize bytes), likely corrupted");
    }

    $this->log("✅ Output: $outputVideo (" . $this->formatBytes($fileSize) . ")");
    $this->updateProgress(100, 'completed', $totalDuration, $totalDuration);
    $this->log("=== VIDEO MERGE COMPLETE ===\n", true);

    // NOW cleanup temp folder after successful merge
    $this->shouldCleanup = true;
    $this->cleanupTempFolder();

    return [
      'path' => $outputVideo,
      'size' => $this->formatBytes($fileSize)
    ];
  }

  private function formatBytes($bytes)
  {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
      $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
  }

  private function formatTime($seconds)
  {
    $seconds = round($seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return $h > 0 ? "{$h}h {$m}m {$s}s" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
  }
}

// ===== REQUEST HANDLER =====
try {
  $input = json_decode(file_get_contents('php://input'), true);

  if (!$input || !isset($input['action'])) {
    throw new Exception('Invalid request format');
  }

  $action = $input['action'];
  $inputPath = $input['inputPath'] ?? '';
  $outputPath = $input['outputPath'] ?? '';
  $outputName = $input['outputName'] ?? 'merged_output';

  if (in_array($action, ['scan', 'merge_all_srt', 'merge_video']) && (empty($inputPath) || empty($outputPath))) {
    throw new Exception('Input and output paths required');
  }

  $merger = new VideoMergerUltraV2($inputPath, $outputPath);

  switch ($action) {
    case 'scan':
      $result = $merger->scanFiles();
      echo json_encode([
        'success' => true,
        'files' => $result,
        'srt_info' => $result['srt_info'],
        'skipped' => $result['skipped'] ?? [],
        'total_duration' => $result['total_duration'],
        'estimated_size' => $result['estimated_size'],
        'stats' => $result['stats'],
        'processId' => uniqid('ultra_v2_', true)
      ]);
      // NOTE: Object will be destroyed here but temp folder persists
      break;

    case 'merge_all_srt':
      $srtData = $input['srt_data'] ?? [];
      $videoData = $input['video_data'] ?? [];

      if (empty($srtData)) {
        echo json_encode(['success' => true, 'merged_count' => 0, 'message' => 'No SRT files']);
        break;
      }

      $mergedCount = $merger->mergeAllSRT($srtData, $videoData, $outputName);
      echo json_encode([
        'success' => true,
        'merged_count' => $mergedCount
      ]);
      break;

    case 'merge_video':
      $videoData = $input['video_data'] ?? [];
      $totalDuration = $input['total_duration'] ?? 0;

      if (empty($videoData)) {
        throw new Exception('No video data provided');
      }

      $result = $merger->mergeVideos($videoData, $outputName, $totalDuration);
      echo json_encode([
        'success' => true,
        'output' => $result['path'],
        'output_size' => $result['size']
      ]);
      // Temp folder cleaned up inside mergeVideos()
      break;

    case 'get_progress':
      $outputPath = $input['outputPath'] ?? '';
      $outputName = $input['outputName'] ?? '';
      $progress = $merger->getProgress($outputPath, $outputName);
      echo json_encode(array_merge(['success' => true], $progress));
      break;

    case 'stop_process':
      $merger->stopCurrentProcess();
      echo json_encode([
        'success' => true,
        'message' => 'Process terminated'
      ]);
      break;

    default:
      throw new Exception('Unknown action: ' . $action);
  }
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine()
  ]);
}
