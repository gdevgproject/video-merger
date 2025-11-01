<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '8G');
ignore_user_abort(true);
ini_set('output_buffering', '4096');

define('FFMPEG_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffmpeg.exe');
define('FFPROBE_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffprobe.exe');
define('MAX_RETRIES', 3);
define('PROGRESS_UPDATE_INTERVAL', 0.5);
define('VIDEO_TIMEOUT', 28800);
define('STUCK_THRESHOLD', 120); // Reduce to 2 minutes for faster detection

class VideoMergerUltraV2
{
  private $inputPath;
  private $outputPath;
  private $tempWorkPath;
  private $logFile;
  private $progressFile;
  private $processIdFile;
  private $tempPathFile;
  private $errorLogFile;
  private $ffmpegLogFile; // NEW: Separate FFmpeg output file
  private $currentProcessPid = null;
  private $lastProgressUpdate = 0;
  private $logBuffer = [];
  private $logBufferSize = 50;
  private $outputVideoPath = '';
  private $sanitizationMap = [];
  private $shouldCleanup = false;
  private $silentMode = false;

  public function __construct($inputPath, $outputPath, $loadExistingTemp = true, $silentMode = false)
  {
    $this->silentMode = $silentMode;
    $this->inputPath = rtrim($inputPath, '\\/');
    $this->outputPath = rtrim($outputPath, '\\/');
    $this->logFile = $this->outputPath . DIRECTORY_SEPARATOR . 'merge_log.txt';
    $this->errorLogFile = $this->outputPath . DIRECTORY_SEPARATOR . 'error_log.txt';
    $this->ffmpegLogFile = $this->outputPath . DIRECTORY_SEPARATOR . 'ffmpeg_output.txt';
    $this->progressFile = $this->outputPath . DIRECTORY_SEPARATOR . 'progress.json';
    $this->processIdFile = $this->outputPath . DIRECTORY_SEPARATOR . 'process_id.txt';
    $this->tempPathFile = $this->outputPath . DIRECTORY_SEPARATOR . 'temp_path.txt';

    if ($loadExistingTemp && file_exists($this->tempPathFile)) {
      $savedPath = @file_get_contents($this->tempPathFile);
      if ($savedPath && is_dir($savedPath)) {
        $this->tempWorkPath = trim($savedPath);
        if (!$silentMode) {
          $this->log("📂 Loaded existing temp path: {$this->tempWorkPath}");
        }
      } else {
        $this->tempWorkPath = $this->outputPath . DIRECTORY_SEPARATOR . '_temp_work_' . uniqid();
      }
    } else {
      $this->tempWorkPath = $this->outputPath . DIRECTORY_SEPARATOR . '_temp_work_' . uniqid();
    }
  }

  private function saveTempPath()
  {
    if (!@file_put_contents($this->tempPathFile, $this->tempWorkPath, LOCK_EX)) {
      $this->log("⚠️ Warning: Cannot save temp path to file");
    } else {
      $this->log("💾 Saved temp path: {$this->tempWorkPath}");
    }
  }

  private function log($message, $forceWrite = false)
  {
    if ($this->silentMode && !$forceWrite) {
      return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $this->logBuffer[] = "[$timestamp] $message";

    if (count($this->logBuffer) >= $this->logBufferSize || $forceWrite) {
      $this->flushLogs();
    }
  }

  private function logError($message, $forceWrite = true)
  {
    $timestamp = date('Y-m-d H:i:s');
    $errorMsg = "[$timestamp] ERROR: $message\n";
    @file_put_contents($this->errorLogFile, $errorMsg, FILE_APPEND | LOCK_EX);
    $this->log("❌ ERROR: $message", $forceWrite);
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
    if ($this->shouldCleanup) {
      $this->cleanupTempFolder();
    }
  }

  private function aggressiveSanitize($filename)
  {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
    $nameWithoutExt = preg_replace('/^\xEF\xBB\xBF/', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $nameWithoutExt);
    $nameWithoutExt = preg_replace('/[_-]+/', '_', $nameWithoutExt);
    $nameWithoutExt = trim($nameWithoutExt, '_-. ');

    if (empty($nameWithoutExt) || $nameWithoutExt === '') {
      $nameWithoutExt = 'file_' . uniqid() . '_' . time();
    }

    if (strlen($nameWithoutExt) > 150) {
      $nameWithoutExt = substr($nameWithoutExt, 0, 150);
    }

    $sanitized = $nameWithoutExt . '.' . $extension;

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
      $this->saveTempPath();
    } else {
      $this->log("📂 Temp folder already exists: {$this->tempWorkPath}");
    }
    return true;
  }

  private function cleanupTempFolder()
  {
    if (!is_dir($this->tempWorkPath)) {
      return;
    }

    $this->log("🧹 Cleaning up temp folder...", true);

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

    if (file_exists($this->tempPathFile)) {
      @unlink($this->tempPathFile);
    }

    $this->log("✅ Deleted $deleted temp files, removed temp folder", true);
  }

  public function forceCleanup()
  {
    $this->shouldCleanup = true;
    $this->cleanupTempFolder();
  }

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

  private function killAllFFmpegProcesses()
  {
    $this->log("🔪 Killing all FFmpeg processes...");
    exec('taskkill /F /IM ffmpeg.exe /T 2>&1', $output1);
    $this->log("Taskkill result: " . implode("; ", $output1));
    exec('wmic process where name="ffmpeg.exe" delete 2>&1', $output2);
    sleep(2);
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

    if (file_exists($this->processIdFile)) {
      $pid = @file_get_contents($this->processIdFile);
      if ($pid && is_numeric($pid)) {
        $this->log("Killing PID: $pid and all children");
        exec("taskkill /F /T /PID $pid 2>&1", $output);
        $this->log("Taskkill PID result: " . implode("; ", $output));
      }
      @unlink($this->processIdFile);
    }

    $this->killAllFFmpegProcesses();
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

        if (isset($data['timestamp']) && (time() - $data['timestamp']) > STUCK_THRESHOLD) {
          return [
            'progress' => $data['progress'] ?? 0,
            'status' => 'timeout',
            'message' => 'Progress stalled for ' . round(STUCK_THRESHOLD / 60) . ' minutes',
            'file_size' => $data['file_size'] ?? 0
          ];
        }

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

    $required = $estimatedSize * 2;
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
      $sanitizedName = $this->aggressiveSanitize($file);
      $needsSanitization = ($sanitizedName !== $file);

      if ($needsSanitization) {
        $sanitizedCount++;
        $this->log("🧹 Sanitize: '$file' → '$sanitizedName'");
      }

      $this->sanitizationMap[$file] = $sanitizedName;
      $tempPath = $this->tempWorkPath . DIRECTORY_SEPARATOR . $sanitizedName;

      if (file_exists($tempPath)) {
        $this->log("⚠️ SKIP duplicate: $sanitizedName (already exists in temp)");
        continue;
      }

      if (!@copy($originalPath, $tempPath)) {
        $this->log("❌ FAIL copy: $file → temp folder");
        continue;
      }

      $nameWithoutExt = pathinfo($sanitizedName, PATHINFO_FILENAME);

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
      } elseif ($ext === 'srt') {
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

    $tempFileCount = count(glob($this->tempWorkPath . DIRECTORY_SEPARATOR . '*.mp4'));
    $this->log("📊 Verification: $tempFileCount MP4 files in temp folder");

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
      'temp_path' => $this->tempWorkPath,
      'stats' => [
        'total_size' => $this->formatBytes($totalSize),
        'total_duration' => $this->formatTime(round($totalDuration)),
        'estimated_output' => $this->formatBytes($totalSize * 1.1),
        'sanitized_count' => $sanitizedCount,
        'temp_folder' => $this->tempWorkPath
      ]
    ];
  }

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

    // --- OPTIMIZATION: Pre-calculate cumulative duration offsets ---
    $videoOffsets = [];
    $cumulativeDuration = 0;
    foreach ($videoData as $video) {
      $videoOffsets[$video['order']] = $cumulativeDuration;
      $cumulativeDuration += $video['duration'];
    }
    // --- END OPTIMIZATION ---

    $mergedContent = '';
    $subtitleCounter = 1;

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

      // Use the pre-calculated offset.
      $timeOffset = $videoOffsets[$srtItem['order']] ?? 0;

      $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
      $subtitles = $this->parseSRT($content);
      $this->log("  ✅ " . $srtItem['file'] . ": " . count($subtitles) . " subs (offset: " . round($timeOffset, 2) . "s)");

      foreach ($subtitles as $subtitle) {
        $startTime = $this->addTimeOffset($subtitle['start'], $timeOffset);
        $endTime = $this->addTimeOffset($subtitle['end'], $timeOffset);

        $mergedContent .= $subtitleCounter++ . "\n";
        $mergedContent .= $startTime . ' --> ' . $endTime . "\n";
        $mergedContent .= $subtitle['text'] . "\n\n";
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
      if (count($lines) < 2) continue; // Changed to 2 to be more lenient

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

  public function mergeVideos($videoData, $outputName = 'merged_output', $totalDuration = 0)
  {
    $this->log("=== 🎥 ULTRA FAST VIDEO MERGE STARTED ===", true);
    $mergeStart = microtime(true);

    $outputName = $this->aggressiveSanitize($outputName);

    if (empty($videoData)) {
      throw new Exception("No videos to merge");
    }

    if (!is_dir($this->tempWorkPath)) {
      throw new Exception("Temp work folder not found! Path: {$this->tempWorkPath}");
    }

    $tempFileCount = count(glob($this->tempWorkPath . DIRECTORY_SEPARATOR . '*.mp4'));
    $this->log("📊 Pre-merge verification: $tempFileCount MP4 files in temp folder");

    if ($tempFileCount === 0) {
      throw new Exception("Temp folder is empty before merge!");
    }

    $validVideos = [];
    $calculatedDuration = 0;

    foreach ($videoData as $video) {
      $videoPath = $video['path'];

      if (!file_exists($videoPath)) {
        $this->log("⚠️ SKIP: File not found: " . $video['file']);
        continue;
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
      throw new Exception("No valid videos found after validation!");
    }

    $totalDuration = $totalDuration > 0 ? $totalDuration : $calculatedDuration;
    $this->log("📊 Total duration: " . round($totalDuration, 2) . "s (" . count($validVideos) . " videos)");

    $listFile = $this->tempWorkPath . DIRECTORY_SEPARATOR . 'filelist.txt';
    $listContent = '';

    foreach ($validVideos as $video) {
      $videoPath = $video['path'];
      $unixPath = str_replace('\\', '/', $videoPath);
      $listContent .= "file '$unixPath'\n";
    }

    if (!@file_put_contents($listFile, $listContent, LOCK_EX)) {
      throw new Exception("Cannot create filelist: $listFile");
    }

    $this->log("📄 Filelist created with Unix paths");
    $this->log("📄 Sample: " . substr($listContent, 0, 200));

    $outputVideo = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . '.mp4';
    $this->outputVideoPath = $outputVideo;

    if (file_exists($outputVideo)) {
      @unlink($outputVideo);
      $this->log("🗑️ Removed old output file");
    }

    $this->updateProgress(0.1, 'starting', 0, $totalDuration);

    $ffmpegOutputFile = $this->ffmpegLogFile;

    // --- IMPROVED & ROBUST COMMAND ---
    $command = sprintf(
      '"%s" -f concat -safe 0 -i "%s" -c copy -avoid_negative_ts make_zero -max_muxing_queue_size 9999 -movflags +faststart -y "%s" > "%s" 2>&1',
      FFMPEG_PATH,
      str_replace('\\', '/', $listFile),
      str_replace('\\', '/', $outputVideo),
      str_replace('\\', '/', $ffmpegOutputFile)
    );

    $this->log("🚀 FFmpeg command:");
    $this->log($command, true);
    $this->log("📝 FFmpeg output will be saved to: $ffmpegOutputFile");

    $startTime = time();

    // --- CRITICAL FIX for Windows background execution ---
    // Add an empty quoted string "" as a dummy title for the 'start' command.
    exec('start /B "" ' . $command, $execOutput, $returnCode);

    $this->log("📢 FFmpeg started in background, return code: $returnCode");

    $lastFileSize = 0;
    $noGrowthCount = 0;
    $lastLogTime = time();

    while (true) {
      sleep(1);

      if (file_exists($outputVideo)) {
        clearstatcache(true, $outputVideo); // Clear file stat cache
        $currentSize = filesize($outputVideo);

        if ($currentSize > $lastFileSize) {
          $lastFileSize = $currentSize;
          $noGrowthCount = 0;

          $progress = 0;
          if ($totalDuration > 0 && $currentSize > 0) {
            $estimatedSeconds = $currentSize / 102400; // Rough estimate
            $progress = min(($estimatedSeconds / $totalDuration) * 100, 99.5);
          }

          if (time() - $lastLogTime >= 15) {
            $sizeMB = round($currentSize / (1024 * 1024), 2);
            $this->log("💗 Progress: {$progress}% - Size: {$sizeMB}MB");
            $lastLogTime = time();
          }

          $this->updateProgress($progress, 'encoding', 0, $totalDuration);
        } else {
          $noGrowthCount++;
        }
      }

      $elapsed = time() - $startTime;

      if ($noGrowthCount > 120) {
        $this->log("⚠️ File size not growing for 2 minutes");
        break;
      }

      if ($elapsed > VIDEO_TIMEOUT) {
        $this->log("⚠️ TIMEOUT after {$elapsed} seconds");
        break;
      }

      exec('tasklist /FI "IMAGENAME eq ffmpeg.exe" 2>&1', $checkOutput);
      $ffmpegRunning = stripos(implode("\n", $checkOutput), 'ffmpeg.exe') !== false;

      if (!$ffmpegRunning) {
        $this->log("✅ FFmpeg process finished");
        break;
      }
    }

    sleep(2);

    $mergeTime = round(microtime(true) - $mergeStart, 2);
    $this->log("⏱️ Merge time: {$mergeTime}s");

    if (file_exists($ffmpegOutputFile)) {
      $ffmpegOutput = @file_get_contents($ffmpegOutputFile);
      $this->log("📝 FFmpeg output length: " . strlen($ffmpegOutput) . " bytes");

      if (stripos($ffmpegOutput, 'error') !== false || stripos($ffmpegOutput, 'invalid') !== false) {
        $this->logError("FFmpeg reported errors. Check ffmpeg_output.txt");
        $this->log("❌ FFmpeg errors detected in output");
      }
    }

    if (!file_exists($outputVideo)) {
      throw new Exception("Output file not created. Check ffmpeg_output.txt");
    }

    $fileSize = filesize($outputVideo);
    if ($fileSize < 1024 * 1024) { // 1MB minimum
      throw new Exception("Output file too small ($fileSize bytes). Check ffmpeg_output.txt for errors.");
    }

    $this->log("✅ Output: $outputVideo (" . $this->formatBytes($fileSize) . ")");
    $this->updateProgress(100, 'completed', $totalDuration, $totalDuration);
    $this->log("=== VIDEO MERGE COMPLETE ===\n", true);

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

  $silentMode = ($action === 'get_progress');
  $loadExistingTemp = ($action !== 'scan');
  $merger = new VideoMergerUltraV2($inputPath, $outputPath, $loadExistingTemp, $silentMode);

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
        'temp_path' => $result['temp_path'],
        'stats' => $result['stats'],
        'processId' => uniqid('ultra_v2_', true)
      ]);
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
