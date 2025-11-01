<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ===== ULTRA PERFORMANCE CONFIGURATION =====
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '8G'); // 8GB for massive files
ignore_user_abort(true);

// Increase buffer for better I/O performance
ini_set('output_buffering', '4096');

define('FFMPEG_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffmpeg.exe');
define('FFPROBE_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffprobe.exe');
define('MAX_RETRIES', 3);
define('PROGRESS_UPDATE_INTERVAL', 0.5); // Update every 0.5 seconds

class VideoMergerUltra
{
  private $inputPath;
  private $outputPath;
  private $logFile;
  private $progressFile;
  private $processIdFile;
  private $checkpointFile;
  private $currentProcessPid = null;
  private $lastProgressUpdate = 0;
  private $logBuffer = [];
  private $logBufferSize = 50; // Batch log writes

  public function __construct($inputPath, $outputPath)
  {
    $this->inputPath = rtrim($inputPath, '\\/');
    $this->outputPath = rtrim($outputPath, '\\/');
    $this->logFile = $this->outputPath . DIRECTORY_SEPARATOR . 'merge_log.txt';
    $this->progressFile = $this->outputPath . DIRECTORY_SEPARATOR . 'progress.json';
    $this->processIdFile = $this->outputPath . DIRECTORY_SEPARATOR . 'process_id.txt';
    $this->checkpointFile = $this->outputPath . DIRECTORY_SEPARATOR . 'checkpoint.json';
  }

  private function log($message, $forceWrite = false)
  {
    $timestamp = date('Y-m-d H:i:s');
    $this->logBuffer[] = "[$timestamp] $message";

    // Batch write logs to reduce I/O
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
  }

  // ===== OPTIMIZED PROGRESS UPDATE =====
  private function updateProgress($progress, $status = '', $currentTime = 0, $totalDuration = 0)
  {
    $now = microtime(true);

    // Throttle updates to reduce I/O load
    if ($now - $this->lastProgressUpdate < PROGRESS_UPDATE_INTERVAL && $progress < 100) {
      return;
    }

    $this->lastProgressUpdate = $now;

    $data = [
      'progress' => round($progress, 2),
      'status' => $status,
      'timestamp' => time(),
      'current_time' => $currentTime,
      'total_duration' => $totalDuration
    ];

    @file_put_contents($this->progressFile, json_encode($data), LOCK_EX);
  }

  private function saveProcessPid($pid)
  {
    @file_put_contents($this->processIdFile, $pid, LOCK_EX);
    $this->currentProcessPid = $pid;
  }

  // ===== CHECKPOINT SYSTEM FOR RECOVERY =====
  private function saveCheckpoint($step, $data)
  {
    $checkpoint = [
      'step' => $step,
      'data' => $data,
      'timestamp' => time()
    ];
    @file_put_contents($this->checkpointFile, json_encode($checkpoint), LOCK_EX);
  }

  private function loadCheckpoint()
  {
    if (file_exists($this->checkpointFile)) {
      $content = @file_get_contents($this->checkpointFile);
      if ($content) {
        return json_decode($content, true);
      }
    }
    return null;
  }

  // ===== ENHANCED STOP PROCESS WITH TREE KILL =====
  public function stopCurrentProcess()
  {
    $this->log("🛑 Initiating process termination...", true);

    if (file_exists($this->processIdFile)) {
      $pid = @file_get_contents($this->processIdFile);
      if ($pid && is_numeric($pid)) {
        $this->log("Killing PID: $pid and all children");

        // Kill entire process tree
        exec("taskkill /F /T /PID $pid 2>&1", $output, $returnCode);
        $this->log("Taskkill result (code $returnCode): " . implode("; ", $output));

        // Wait for graceful termination
        sleep(2);

        // Verify process is dead
        exec("tasklist /FI \"PID eq $pid\" 2>&1", $checkOutput);
        if (!preg_match("/\b$pid\b/", implode("\n", $checkOutput))) {
          $this->log("✓ Process terminated successfully");
        } else {
          $this->log("⚠️ Process may still be running");
        }
      }
      @unlink($this->processIdFile);
    }

    @unlink($this->progressFile);
    $this->flushLogs();
  }

  public function getProgress()
  {
    if (file_exists($this->progressFile)) {
      $content = @file_get_contents($this->progressFile);
      if ($content) {
        $data = json_decode($content, true);

        // Timeout detection
        if (isset($data['timestamp']) && (time() - $data['timestamp']) > 60) {
          return ['progress' => 0, 'status' => 'timeout', 'message' => 'Progress stalled for 60s'];
        }

        return $data;
      }
    }
    return ['progress' => 0, 'status' => 'not_started'];
  }

  // ===== DISK SPACE VALIDATION =====
  private function checkDiskSpace($estimatedSize)
  {
    $drive = substr($this->outputPath, 0, 2); // e.g., "D:"
    $freeSpace = @disk_free_space($drive);

    if ($freeSpace === false) {
      $this->log("⚠️ Cannot check disk space");
      return true; // Continue anyway
    }

    $required = $estimatedSize * 1.2; // Add 20% buffer
    $freeGB = $freeSpace / (1024 * 1024 * 1024);
    $requiredGB = $required / (1024 * 1024 * 1024);

    $this->log("💾 Disk space: {$freeGB}GB free, need ~{$requiredGB}GB");

    if ($freeSpace < $required) {
      throw new Exception("Không đủ dung lượng đĩa! Cần {$requiredGB}GB, chỉ còn {$freeGB}GB");
    }

    return true;
  }

  // ===== ADVANCED VIDEO VALIDATION WITH RETRY =====
  private function isValidVideo($videoPath, $retries = MAX_RETRIES)
  {
    if (!file_exists($videoPath)) {
      return false;
    }

    $fileSize = filesize($videoPath);
    if ($fileSize < 1024) {
      $this->log("  ⚠️ File too small: " . $this->formatBytes($fileSize));
      return false;
    }

    // Retry validation
    for ($i = 0; $i <= $retries; $i++) {
      $duration = $this->getVideoDuration($videoPath);

      if ($duration > 0) {
        // Additional validation: check codec
        $info = $this->getVideoInfo($videoPath);
        if ($info && isset($info['codec_name'])) {
          return true;
        }
      }

      if ($i < $retries) {
        $this->log("  ⏳ Retry validation... (" . ($i + 1) . "/$retries)");
        usleep(500000); // 0.5s
      }
    }

    $this->log("  ✗ Invalid video after $retries attempts");
    return false;
  }

  // ===== GET DETAILED VIDEO INFO =====
  private function getVideoInfo($videoPath)
  {
    $command = sprintf(
      '"%s" -v quiet -print_format json -show_format -show_streams "%s" 2>&1',
      FFPROBE_PATH,
      $videoPath
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && !empty($output)) {
      $json = implode('', $output);
      $data = json_decode($json, true);

      if ($data && isset($data['streams'])) {
        foreach ($data['streams'] as $stream) {
          if ($stream['codec_type'] === 'video') {
            return $stream;
          }
        }
      }
    }

    return null;
  }

  // ===== ULTRA-OPTIMIZED SCAN WITH STATS =====
  public function scanFiles()
  {
    $this->log("=== 🚀 ULTRA SCAN STARTED ===", true);
    $scanStart = microtime(true);

    if (!is_dir($this->inputPath)) {
      throw new Exception("Input directory not found: {$this->inputPath}");
    }

    if (!is_dir($this->outputPath)) {
      if (!@mkdir($this->outputPath, 0777, true)) {
        throw new Exception("Cannot create output directory: {$this->outputPath}");
      }
    }

    $files = scandir($this->inputPath);
    $videos = [];
    $srt_en = [];
    $srt_vi = [];
    $srt_unknown = [];
    $skippedVideos = [];
    $totalSize = 0;
    $totalDuration = 0;

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      $filePath = $this->inputPath . DIRECTORY_SEPARATOR . $file;
      if (!is_file($filePath)) continue;

      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
      $nameWithoutExt = pathinfo($file, PATHINFO_FILENAME);

      if ($ext === 'mp4') {
        if (preg_match('/^(\d+)/', $file, $matches)) {
          $order = intval($matches[1]);
          $fileSize = filesize($filePath);

          $this->log("🔍 Validating: $file (" . $this->formatBytes($fileSize) . ")");

          if ($this->isValidVideo($filePath, 2)) {
            $duration = $this->getVideoDuration($filePath);

            $videos[$order] = [
              'file' => $file,
              'order' => $order,
              'path' => $filePath,
              'size' => $fileSize,
              'duration' => $duration
            ];

            $totalSize += $fileSize;
            $totalDuration += $duration;

            $this->log("  ✓ Valid: [$order] {$file} - {$duration}s");
          } else {
            $skippedVideos[] = $file;
            $this->log("  ✗ SKIPPED: $file");
          }
        }
      } elseif ($ext === 'srt') {
        $isEnglish = preg_match('/_en$/i', $nameWithoutExt);
        $isVietnamese = preg_match('/_vi$/i', $nameWithoutExt);

        if ($isEnglish) {
          $baseNameWithoutLang = preg_replace('/_en$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_en[$order] = ['file' => $file, 'order' => $order, 'path' => $filePath];
          }
        } elseif ($isVietnamese) {
          $baseNameWithoutLang = preg_replace('/_vi$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_vi[$order] = ['file' => $file, 'order' => $order, 'path' => $filePath];
          }
        } else {
          if (preg_match('/^(\d+)/', $nameWithoutExt, $matches)) {
            $order = intval($matches[1]);
            $srt_unknown[$order] = ['file' => $file, 'order' => $order, 'path' => $filePath];
          }
        }
      }
    }

    ksort($videos);
    ksort($srt_en);
    ksort($srt_vi);
    ksort($srt_unknown);

    if (empty($videos)) {
      throw new Exception("No valid videos found!");
    }

    // Check disk space
    $this->checkDiskSpace($totalSize);

    $scanTime = round(microtime(true) - $scanStart, 2);
    $this->log("✅ Scan complete in {$scanTime}s: " . count($videos) . " videos, " .
      $this->formatBytes($totalSize) . ", {$totalDuration}s");
    $this->log("=== SCAN FINISHED ===\n", true);

    $srt_all = [];
    foreach ($srt_en as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'type' => 'en', 'order' => $order];
    }
    foreach ($srt_vi as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'type' => 'vi', 'order' => $order];
    }
    foreach ($srt_unknown as $order => $data) {
      $srt_all[] = ['file' => $data['file'], 'type' => 'unknown', 'order' => $order];
    }

    return [
      'videos' => array_values(array_map(function ($v) {
        return $v['file'];
      }, $videos)),
      'srt_all' => $srt_all,
      'srt_info' => [
        'en' => count($srt_en),
        'vi' => count($srt_vi),
        'unknown' => count($srt_unknown),
        'total' => count($srt_en) + count($srt_vi) + count($srt_unknown)
      ],
      'skipped' => $skippedVideos,
      'total_duration' => $totalDuration,
      'stats' => [
        'total_size' => $this->formatBytes($totalSize),
        'total_duration' => $this->formatTime($totalDuration),
        'estimated_output' => $this->formatBytes($totalSize * 1.05) // ~5% overhead
      ]
    ];
  }

  public function mergeAllSRT($srtFiles, $videoFiles, $outputName = 'merged_output')
  {
    $this->log("=== 📝 MERGING ALL SRT ===");

    $srt_by_type = ['en' => [], 'vi' => [], 'unknown' => []];

    foreach ($srtFiles as $srtData) {
      $type = $srtData['type'];
      $srt_by_type[$type][] = $srtData['file'];
    }

    $merged_count = 0;

    foreach (['en', 'vi', 'unknown'] as $type) {
      if (!empty($srt_by_type[$type])) {
        $this->log("📝 Merging " . count($srt_by_type[$type]) . " $type SRT files");
        $this->mergeSRT($srt_by_type[$type], $videoFiles, $outputName, $type === 'unknown' ? '' : $type);
        $merged_count++;
      }
    }

    $this->log("✅ SRT MERGE COMPLETE: $merged_count types\n", true);
    return $merged_count;
  }

  // ===== ULTRA-OPTIMIZED VIDEO MERGE =====
  public function mergeVideos($videoFiles, $outputName = 'merged_output', $totalDuration = 0)
  {
    $this->log("=== 🎥 ULTRA FAST VIDEO MERGE STARTED ===", true);
    $mergeStart = microtime(true);

    if (empty($videoFiles)) {
      throw new Exception("No videos to merge");
    }

    $validVideos = [];
    $calculatedDuration = 0;

    foreach ($videoFiles as $video) {
      $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $video;

      if (!file_exists($videoPath)) {
        $this->log("⚠️ SKIP: File not found: $video");
        continue;
      }

      $duration = $this->getVideoDuration($videoPath);

      if ($duration <= 0) {
        $this->log("⚠️ SKIP: Invalid duration: $video");
        continue;
      }

      $validVideos[] = $video;
      $calculatedDuration += $duration;
      $this->log("✓ $video - " . round($duration, 2) . "s");
    }

    if (empty($validVideos)) {
      throw new Exception("No valid videos after validation!");
    }

    $totalDuration = $totalDuration > 0 ? $totalDuration : $calculatedDuration;
    $this->log("📊 Total duration: " . round($totalDuration, 2) . "s (" . count($validVideos) . " videos)");

    // Create filelist with proper escaping
    $listFile = $this->outputPath . DIRECTORY_SEPARATOR . 'filelist.txt';
    $listContent = '';

    foreach ($validVideos as $video) {
      $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $video;
      $normalizedPath = str_replace('/', '\\', $videoPath);
      $escapedPath = str_replace("'", "'\\''", $normalizedPath);
      $listContent .= "file '$escapedPath'\n";
    }

    if (!@file_put_contents($listFile, $listContent, LOCK_EX)) {
      throw new Exception("Cannot create filelist: $listFile");
    }

    $outputVideo = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . '.mp4';

    if (file_exists($outputVideo)) {
      @unlink($outputVideo);
      $this->log("🗑️ Removed old output file");
    }

    // Enhanced metadata
    $metadata = [
      'title' => $outputName,
      'author' => 'Video Merger Pro Ultra',
      'artist' => 'Original Content Creator',
      'copyright' => '© ' . date('Y') . ' - All Rights Reserved',
      'comment' => 'Merged with Ultra Performance Engine - ' . count($validVideos) . ' videos',
      'description' => 'Ultra-fast concatenation with zero quality loss',
      'album' => 'Video Collection ' . date('Y'),
      'date' => date('Y-m-d'),
      'encoder' => 'Video Merger Pro Ultra with FFmpeg'
    ];

    // ===== ULTRA-OPTIMIZED FFMPEG COMMAND =====
    $command = sprintf(
      '"%s" -f concat -safe 0 -i "%s" ' .
        '-c copy ' .
        '-fflags +genpts ' .                          // Generate PTS for better sync
        '-avoid_negative_ts make_zero ' .            // Fix timestamp issues
        '-max_muxing_queue_size 9999 ' .             // Large queue for smooth muxing
        '-analyzeduration 100M -probesize 100M ' .   // Large buffers for better analysis
        '-metadata title="%s" -metadata author="%s" -metadata artist="%s" ' .
        '-metadata copyright="%s" -metadata comment="%s" ' .
        '-metadata description="%s" -metadata album="%s" ' .
        '-metadata date="%s" -metadata encoder="%s" ' .
        '-movflags +faststart ' .                    // Enable fast start for web streaming
        '-progress pipe:1 -y "%s" 2>&1',
      FFMPEG_PATH,
      $listFile,
      addslashes($metadata['title']),
      addslashes($metadata['author']),
      addslashes($metadata['artist']),
      addslashes($metadata['copyright']),
      addslashes($metadata['comment']),
      addslashes($metadata['description']),
      addslashes($metadata['album']),
      $metadata['date'],
      addslashes($metadata['encoder']),
      $outputVideo
    );

    $this->log("🚀 FFmpeg command executing...");
    $this->log("⏳ Processing with Ultra Fast Mode (copy codec, no re-encode)");

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
      $this->log("🔢 FFmpeg PID: " . $status['pid']);
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $lastProgress = 0;
    $errorOutput = '';
    $lastHeartbeat = time();
    $progressStartTime = microtime(true);

    // ===== REAL-TIME PROGRESS TRACKING =====
    while (true) {
      $status = proc_get_status($process);

      if (!$status['running']) {
        break;
      }

      // Heartbeat logging every 30s
      if (time() - $lastHeartbeat >= 30) {
        $elapsed = round(microtime(true) - $progressStartTime);
        $this->log("💓 Processing... " . round($lastProgress, 1) . "% ({$elapsed}s)");
        $lastHeartbeat = time();
      }

      $output = fgets($pipes[1]);
      $error = fgets($pipes[2]);

      if ($error !== false && $error !== '') {
        $errorOutput .= $error;
      }

      if ($output !== false && $output !== '') {
        // Parse FFmpeg progress - use out_time_us for microsecond precision
        if (preg_match('/out_time_us=(\d+)/', $output, $matches)) {
          $currentTimeUs = intval($matches[1]);
          $currentTime = $currentTimeUs / 1000000; // Convert to seconds

          if ($totalDuration > 0) {
            $progress = min(($currentTime / $totalDuration) * 100, 99.9);

            if ($progress > $lastProgress + 0.3) { // Update every 0.3%
              $this->updateProgress($progress, 'encoding', $currentTime, $totalDuration);
              $lastProgress = $progress;
            }
          }
        }
      }

      if ($output === false && $error === false) {
        usleep(50000); // 0.05s sleep to reduce CPU usage
      }

      // Timeout protection - 4 hours max
      if (microtime(true) - $progressStartTime > 14400) {
        $this->log("⚠️ TIMEOUT: Process exceeded 4 hours", true);
        $this->stopCurrentProcess();
        throw new Exception("Process timeout after 4 hours");
      }
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    $mergeTime = round(microtime(true) - $mergeStart, 2);

    // Log errors if any
    if (!empty($errorOutput)) {
      $errorLines = explode("\n", $errorOutput);
      $relevantErrors = array_filter($errorLines, function ($line) {
        return stripos($line, 'error') !== false || stripos($line, 'failed') !== false;
      });

      if (!empty($relevantErrors)) {
        $this->log("⚠️ FFmpeg warnings/errors:\n" . implode("\n", array_slice($relevantErrors, -10)));
      }
    }

    $this->log("⏱️ Merge time: {$mergeTime}s");
    $this->log("🔢 FFmpeg return code: $returnCode");

    @unlink($listFile);
    @unlink($this->processIdFile);
    @unlink($this->checkpointFile);

    if ($returnCode !== 0) {
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

    return [
      'path' => $outputVideo,
      'size' => $this->formatBytes($fileSize)
    ];
  }

  // ===== OPTIMIZED DURATION DETECTION =====
  private function getVideoDuration($videoPath)
  {
    static $durationCache = [];

    if (isset($durationCache[$videoPath])) {
      return $durationCache[$videoPath];
    }

    // Try ffprobe first (faster and more accurate)
    $command = sprintf(
      '"%s" -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
      FFPROBE_PATH,
      $videoPath
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0 && !empty($output[0])) {
      $duration = trim($output[0]);
      if (is_numeric($duration) && $duration > 0) {
        $durationCache[$videoPath] = floatval($duration);
        return $durationCache[$videoPath];
      }
    }

    // Fallback to ffmpeg
    $command = sprintf('"%s" -i "%s" 2>&1', FFMPEG_PATH, $videoPath);
    exec($command, $output);

    foreach ($output as $line) {
      if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        $seconds = floatval($matches[3]);
        $duration = $hours * 3600 + $minutes * 60 + $seconds;
        $durationCache[$videoPath] = $duration;
        return $duration;
      }
    }

    return 0;
  }

  public function mergeSRT($srtFiles, $videoFiles, $outputName = 'merged_output', $lang = 'en')
  {
    $this->log("=== 📝 MERGING SRT ($lang) ===");

    if (empty($srtFiles)) {
      throw new Exception("No SRT files to merge");
    }

    $mergedContent = '';
    $subtitleCounter = 1;
    $timeOffset = 0;

    foreach ($srtFiles as $index => $srtFile) {
      $srtPath = $this->inputPath . DIRECTORY_SEPARATOR . $srtFile;

      if (!file_exists($srtPath)) {
        $this->log("⚠️ SKIP: SRT not found: $srtFile");
        continue;
      }

      $content = @file_get_contents($srtPath);
      if ($content === false) {
        $this->log("⚠️ SKIP: Cannot read SRT: $srtFile");
        continue;
      }

      // Remove BOM
      $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

      $subtitles = $this->parseSRT($content);
      $this->log("  ✓ $srtFile: " . count($subtitles) . " subs (offset: " . round($timeOffset, 2) . "s)");

      foreach ($subtitles as $subtitle) {
        $startTime = $this->addTimeOffset($subtitle['start'], $timeOffset);
        $endTime = $this->addTimeOffset($subtitle['end'], $timeOffset);

        $mergedContent .= $subtitleCounter . "\n";
        $mergedContent .= $startTime . ' --> ' . $endTime . "\n";
        $mergedContent .= $subtitle['text'] . "\n\n";

        $subtitleCounter++;
      }

      if (isset($videoFiles[$index])) {
        $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $videoFiles[$index];
        if (file_exists($videoPath)) {
          $duration = $this->getVideoDuration($videoPath);
          if ($duration > 0) {
            $timeOffset += $duration;
          }
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
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return $h > 0 ? "{$h}h {$m}m {$s}s" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
  }
}

// ===== REQUEST HANDLER WITH ROBUST ERROR HANDLING =====
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

  $merger = new VideoMergerUltra($inputPath, $outputPath);

  switch ($action) {
    case 'scan':
      $result = $merger->scanFiles();
      echo json_encode([
        'success' => true,
        'files' => $result,
        'srt_info' => $result['srt_info'],
        'skipped' => $result['skipped'] ?? [],
        'total_duration' => $result['total_duration'],
        'stats' => $result['stats'],
        'processId' => uniqid('ultra_', true)
      ]);
      break;

    case 'merge_all_srt':
      $srtFiles = $input['srt_files'] ?? [];
      $videos = $input['videos'] ?? [];

      if (empty($srtFiles)) {
        throw new Exception('No SRT files provided');
      }

      $mergedCount = $merger->mergeAllSRT($srtFiles, $videos, $outputName);
      echo json_encode([
        'success' => true,
        'merged_count' => $mergedCount
      ]);
      break;

    case 'merge_video':
      $videos = $input['videos'] ?? [];
      $totalDuration = $input['total_duration'] ?? 0;

      if (empty($videos)) {
        throw new Exception('No videos provided');
      }

      $result = $merger->mergeVideos($videos, $outputName, $totalDuration);
      echo json_encode([
        'success' => true,
        'output' => $result['path'],
        'output_size' => $result['size']
      ]);
      break;

    case 'get_progress':
      $progress = $merger->getProgress();
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
