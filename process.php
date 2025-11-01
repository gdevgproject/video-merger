<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ===== FIX #1: Tăng giới hạn thời gian và memory =====
set_time_limit(0); // Không giới hạn thời gian
ini_set('max_execution_time', '0');
ini_set('memory_limit', '4G'); // Tăng lên 4GB
ignore_user_abort(true); // Tiếp tục chạy khi user đóng browser

// Đường dẫn FFmpeg
define('FFMPEG_PATH', 'C:\\ooxmind\\bin\\ffmpeg\\bin\\ffmpeg.exe');
define('SPEED_FACTOR', 1.0);
define('MAX_RETRIES', 3); // Số lần thử lại khi lỗi

class VideoMerger
{
  private $inputPath;
  private $outputPath;
  private $logFile;
  private $progressFile;
  private $processIdFile;
  private $currentProcessPid = null;
  private $lastProgressUpdate = 0;

  public function __construct($inputPath, $outputPath)
  {
    $this->inputPath = rtrim($inputPath, '\\/');
    $this->outputPath = rtrim($outputPath, '\\/');
    $this->logFile = $this->outputPath . DIRECTORY_SEPARATOR . 'merge_log.txt';
    $this->progressFile = $this->outputPath . DIRECTORY_SEPARATOR . 'progress.json';
    $this->processIdFile = $this->outputPath . DIRECTORY_SEPARATOR . 'process_id.txt';
  }

  private function log($message)
  {
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
  }

  // ===== FIX #2: Throttle progress updates để giảm I/O =====
  private function updateProgress($progress, $status = '')
  {
    $now = microtime(true);
    // Chỉ update mỗi 1 giây
    if ($now - $this->lastProgressUpdate < 1 && $progress < 100) {
      return;
    }

    $this->lastProgressUpdate = $now;
    $data = [
      'progress' => $progress,
      'status' => $status,
      'timestamp' => time()
    ];
    @file_put_contents($this->progressFile, json_encode($data), LOCK_EX);
  }

  private function saveProcessPid($pid)
  {
    @file_put_contents($this->processIdFile, $pid, LOCK_EX);
    $this->currentProcessPid = $pid;
  }

  // ===== FIX #3: Cải thiện stop process - kill cả process tree =====
  public function stopCurrentProcess()
  {
    if (file_exists($this->processIdFile)) {
      $pid = @file_get_contents($this->processIdFile);
      if ($pid && is_numeric($pid)) {
        $this->log("🛑 Đang dừng process PID: $pid");

        // Kill process tree (bao gồm cả child processes)
        exec("taskkill /F /PID $pid /T 2>&1", $output, $returnCode);
        $this->log("Kill result (code $returnCode): " . implode("\n", $output));

        // Đợi một chút để đảm bảo process đã chết
        sleep(1);

        // Kiểm tra xem còn process nào đang chạy không
        exec("tasklist /FI \"PID eq $pid\" 2>&1", $checkOutput);
        if (!preg_match("/\b$pid\b/", implode("\n", $checkOutput))) {
          $this->log("✓ Process đã dừng hoàn toàn");
        }
      }
      @unlink($this->processIdFile);
    }

    // Xóa file progress để tránh hiển thị sai
    @unlink($this->progressFile);
  }

  public function getProgress()
  {
    if (file_exists($this->progressFile)) {
      $content = @file_get_contents($this->progressFile);
      if ($content) {
        $data = json_decode($content, true);
        // Check timeout - nếu không update trong 30s thì coi như lỗi
        if (isset($data['timestamp']) && (time() - $data['timestamp']) > 30) {
          return ['progress' => 0, 'status' => 'timeout'];
        }
        return $data;
      }
    }
    return null;
  }

  // ===== FIX #4: Validate video kỹ hơn với retry =====
  private function isValidVideo($videoPath, $retries = 2)
  {
    if (!file_exists($videoPath)) {
      return false;
    }

    $fileSize = filesize($videoPath);
    if ($fileSize < 1024) {
      $this->log("  ⚠️ File quá nhỏ: " . $this->formatBytes($fileSize));
      return false;
    }

    // Thử validate với retry
    for ($i = 0; $i <= $retries; $i++) {
      $duration = $this->getVideoDuration($videoPath);

      if ($duration > 0) {
        return true;
      }

      if ($i < $retries) {
        $this->log("  ⏳ Retry validation... ($i/$retries)");
        sleep(1);
      }
    }

    $this->log("  ✗ Không thể validate video sau $retries lần thử");
    return false;
  }

  public function scanFiles()
  {
    $this->log("=== BẮT ĐẦU QUÉT FILES THÔNG MINH ===");

    if (!is_dir($this->inputPath)) {
      throw new Exception("Thư mục input không tồn tại: {$this->inputPath}");
    }

    if (!is_dir($this->outputPath)) {
      if (!@mkdir($this->outputPath, 0777, true)) {
        throw new Exception("Không thể tạo thư mục output: {$this->outputPath}");
      }
    }

    $files = scandir($this->inputPath);
    $videos = [];
    $srt_en = [];
    $srt_vi = [];
    $srt_unknown = [];
    $skippedVideos = [];

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      $filePath = $this->inputPath . DIRECTORY_SEPARATOR . $file;
      if (!is_file($filePath)) continue;

      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
      $nameWithoutExt = pathinfo($file, PATHINFO_FILENAME);

      if ($ext === 'mp4') {
        if (preg_match('/^(\d+)/', $file, $matches)) {
          $order = intval($matches[1]);

          $this->log("🔍 Kiểm tra video: $file");

          // Validate với retry
          if ($this->isValidVideo($filePath, 2)) {
            $videos[$order] = [
              'file' => $file,
              'order' => $order,
              'path' => $filePath
            ];
            $this->log("  ✓ Video hợp lệ: [$order] $file");
          } else {
            $skippedVideos[] = $file;
            $this->log("  ✗ SKIP video lỗi: $file");
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
              'file' => $file,
              'order' => $order,
              'path' => $filePath
            ];
            $this->log("📝 SRT EN: [$order] $file");
          }
        } elseif ($isVietnamese) {
          $baseNameWithoutLang = preg_replace('/_vi$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_vi[$order] = [
              'file' => $file,
              'order' => $order,
              'path' => $filePath
            ];
            $this->log("📝 SRT VI: [$order] $file");
          }
        } else {
          if (preg_match('/^(\d+)/', $nameWithoutExt, $matches)) {
            $order = intval($matches[1]);
            $srt_unknown[$order] = [
              'file' => $file,
              'order' => $order,
              'path' => $filePath
            ];
            $this->log("📝 SRT (no lang): [$order] $file");
          }
        }
      }
    }

    ksort($videos);
    ksort($srt_en);
    ksort($srt_vi);
    ksort($srt_unknown);

    if (!empty($skippedVideos)) {
      $this->log("⚠️ CẢNH BÁO: Đã bỏ qua " . count($skippedVideos) . " video lỗi:");
      foreach ($skippedVideos as $skipped) {
        $this->log("  - $skipped");
      }
    }

    $this->log("✅ Tổng: " . count($videos) . " videos hợp lệ, " . count($srt_en) . " SRT EN, " .
      count($srt_vi) . " SRT VI, " . count($srt_unknown) . " SRT unknown");
    $this->log("=== KẾT THÚC QUÉT FILES ===\n");

    if (empty($videos)) {
      throw new Exception("Không tìm thấy video hợp lệ nào để gộp!");
    }

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
      'skipped' => $skippedVideos
    ];
  }

  public function mergeAllSRT($srtFiles, $videoFiles, $outputName = 'merged_output')
  {
    $this->log("=== BẮT ĐẦU GỘP TẤT CẢ SRT ===");

    $srt_by_type = [
      'en' => [],
      'vi' => [],
      'unknown' => []
    ];

    foreach ($srtFiles as $srtData) {
      $type = $srtData['type'];
      $srt_by_type[$type][] = $srtData['file'];
    }

    $merged_count = 0;

    if (!empty($srt_by_type['en'])) {
      $this->log("📝 Gộp " . count($srt_by_type['en']) . " file SRT EN");
      $this->mergeSRT($srt_by_type['en'], $videoFiles, $outputName, 'en');
      $merged_count++;
    }

    if (!empty($srt_by_type['vi'])) {
      $this->log("📝 Gộp " . count($srt_by_type['vi']) . " file SRT VI");
      $this->mergeSRT($srt_by_type['vi'], $videoFiles, $outputName, 'vi');
      $merged_count++;
    }

    if (!empty($srt_by_type['unknown'])) {
      $this->log("📝 Gộp " . count($srt_by_type['unknown']) . " file SRT (no lang)");
      $this->mergeSRT($srt_by_type['unknown'], $videoFiles, $outputName, '');
      $merged_count++;
    }

    $this->log("✅ HOÀN THÀNH GỘP SRT: $merged_count loại\n");

    return $merged_count;
  }

  // ===== FIX #5: Cải thiện merge video với error handling tốt hơn =====
  public function mergeVideos($videoFiles, $outputName = 'merged_output')
  {
    $this->log("=== BẮT ĐẦU GỘP VIDEO (TỐC ĐỘ GỐC 1.0x) ===");

    if (empty($videoFiles)) {
      throw new Exception("Không có video để gộp");
    }

    $totalDuration = 0;
    $validVideos = [];

    foreach ($videoFiles as $video) {
      $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $video;

      if (!file_exists($videoPath)) {
        $this->log("⚠️ SKIP: File không tồn tại: $videoPath");
        continue;
      }

      $duration = $this->getVideoDuration($videoPath);

      if ($duration <= 0) {
        $this->log("⚠️ SKIP: Video lỗi hoặc duration = 0: $video");
        continue;
      }

      $validVideos[] = $video;
      $totalDuration += $duration;
      $this->log("✓ Video: $video - Duration: " . round($duration, 2) . "s");
    }

    if (empty($validVideos)) {
      throw new Exception("Không có video hợp lệ để gộp sau khi kiểm tra!");
    }

    $this->log("📊 Tổng thời lượng: " . round($totalDuration, 2) . "s (" . count($validVideos) . " videos)");

    // ===== FIX #6: Escape path đúng cách cho Windows =====
    $listFile = $this->outputPath . DIRECTORY_SEPARATOR . 'filelist.txt';
    $listContent = '';

    foreach ($validVideos as $video) {
      $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $video;
      // Normalize path cho Windows
      $normalizedPath = str_replace('/', '\\', $videoPath);
      // Escape single quotes trong path
      $escapedPath = str_replace("'", "'\\''", $normalizedPath);
      $listContent .= "file '$escapedPath'\n";
      $this->log("📋 Add to list: $video");
    }

    if (!@file_put_contents($listFile, $listContent)) {
      throw new Exception("Không thể tạo file list: $listFile");
    }

    $outputVideo = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . '.mp4';

    if (file_exists($outputVideo)) {
      @unlink($outputVideo);
      $this->log("🗑️ Đã xóa file output cũ");
    }

    $metadata = [
      'title' => $outputName,
      'author' => 'Video Merger Pro',
      'artist' => 'Original Content Creator',
      'copyright' => '© ' . date('Y') . ' - All Rights Reserved. Protected Content.',
      'comment' => 'Merged with Video Merger Pro v1.0 - Original Speed Preserved',
      'description' => 'This is a merged video compilation. Original content rights belong to respective owners.',
      'album' => 'Video Collection ' . date('Y'),
      'date' => date('Y-m-d'),
      'encoder' => 'Video Merger Pro with FFmpeg'
    ];

    // ===== FIX #7: Thêm error recovery và logging tốt hơn =====
    $command = sprintf(
      '"%s" -f concat -safe 0 -i "%s" ' .
        '-c copy ' .
        '-metadata title="%s" -metadata author="%s" -metadata artist="%s" ' .
        '-metadata copyright="%s" -metadata comment="%s" ' .
        '-metadata description="%s" -metadata album="%s" ' .
        '-metadata date="%s" -metadata encoder="%s" ' .
        '-movflags +faststart -progress pipe:1 -y "%s" 2>&1',
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

    $this->log("🚀 FFmpeg command: $command");
    $this->log("⏳ Đang xử lý video với -c copy (NHANH, không re-encode)...");

    $startTime = microtime(true);

    $descriptorspec = [
      0 => ["pipe", "r"],
      1 => ["pipe", "w"],
      2 => ["pipe", "w"]
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (!is_resource($process)) {
      throw new Exception("Không thể khởi động FFmpeg process");
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
    $outputBuffer = '';
    $lastAliveCheck = time();

    // ===== FIX #8: Better process monitoring =====
    while (true) {
      $status = proc_get_status($process);

      // Check if process is still running
      if (!$status['running']) {
        $this->log("⚠️ Process stopped running");
        break;
      }

      // Heartbeat - log mỗi 10s để biết process vẫn chạy
      if (time() - $lastAliveCheck >= 10) {
        $this->log("💓 Process đang chạy... (progress: " . round($lastProgress, 1) . "%)");
        $lastAliveCheck = time();
      }

      $output = fgets($pipes[1]);
      $error = fgets($pipes[2]);

      if ($error !== false && $error !== '') {
        $errorOutput .= $error;
      }

      if ($output !== false && $output !== '') {
        $outputBuffer .= $output;

        // Parse progress
        if (preg_match('/out_time_ms=(\d+)/', $output, $matches)) {
          $currentTime = intval($matches[1]) / 1000000;
          if ($totalDuration > 0) {
            $progress = min(($currentTime / $totalDuration) * 100, 99);

            // Update progress mỗi 0.5% để giảm I/O
            if ($progress > $lastProgress + 0.5) {
              $this->updateProgress($progress, 'encoding');
              $lastProgress = $progress;
            }
          }
        }
      }

      // Nếu không có data, sleep ngắn
      if ($output === false && $error === false) {
        usleep(100000); // 0.1s
      }

      // Timeout protection - nếu quá 2 giờ thì dừng
      if (microtime(true) - $startTime > 7200) {
        $this->log("⚠️ TIMEOUT: Quá 2 giờ, dừng process");
        $this->stopCurrentProcess();
        throw new Exception("Process timeout sau 2 giờ");
      }
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    $endTime = microtime(true);
    $processingTime = round($endTime - $startTime, 2);

    // Log full error nếu có
    if (!empty($errorOutput)) {
      $this->log("📋 FFmpeg stderr:\n" . substr($errorOutput, -2000)); // Log 2000 ký tự cuối
    }

    $this->log("⏱️ Thời gian xử lý: {$processingTime}s");
    $this->log("🔢 FFmpeg return code: $returnCode");

    @unlink($listFile);
    @unlink($this->processIdFile);

    // ===== FIX #9: Better error handling =====
    if ($returnCode !== 0) {
      $errorMsg = "FFmpeg failed with return code: $returnCode";
      if (!empty($errorOutput)) {
        // Extract last meaningful error line
        $errorLines = array_filter(explode("\n", $errorOutput));
        $lastError = end($errorLines);
        $errorMsg .= "\nLast error: $lastError";
      }
      $this->log("❌ ERROR: $errorMsg");
      throw new Exception($errorMsg);
    }

    if (!file_exists($outputVideo)) {
      throw new Exception("File video output không được tạo. Check merge_log.txt");
    }

    $fileSize = filesize($outputVideo);
    if ($fileSize < 1024 * 1024) { // Nhỏ hơn 1MB = lỗi
      throw new Exception("File output quá nhỏ ($fileSize bytes), có thể bị lỗi");
    }

    $this->log("✅ Video output: $outputVideo (" . $this->formatBytes($fileSize) . ")");
    $this->updateProgress(100, 'completed');
    $this->log("=== KẾT THÚC GỘP VIDEO ===\n");

    return $outputVideo;
  }

  // ===== FIX #10: Improve duration detection =====
  private function getVideoDuration($videoPath)
  {
    $ffprobePath = str_replace('ffmpeg.exe', 'ffprobe.exe', FFMPEG_PATH);

    if (file_exists($ffprobePath)) {
      $command = sprintf(
        '"%s" -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
        $ffprobePath,
        $videoPath
      );

      exec($command, $output, $returnCode);

      if ($returnCode === 0 && !empty($output[0])) {
        $duration = trim($output[0]);
        if (is_numeric($duration) && $duration > 0) {
          return floatval($duration);
        }
      }
    }

    // Fallback
    $command = sprintf(
      '"%s" -i "%s" 2>&1',
      FFMPEG_PATH,
      $videoPath
    );

    exec($command, $output);

    foreach ($output as $line) {
      if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        $seconds = floatval($matches[3]);
        return $hours * 3600 + $minutes * 60 + $seconds;
      }
    }

    return 0;
  }

  public function mergeSRT($srtFiles, $videoFiles, $outputName = 'merged_output', $lang = 'en')
  {
    $this->log("=== BẮT ĐẦU GỘP SRT ($lang) ===");

    if (empty($srtFiles)) {
      throw new Exception("Không có file SRT để gộp");
    }

    $mergedContent = '';
    $subtitleCounter = 1;
    $timeOffset = 0;

    foreach ($srtFiles as $index => $srtFile) {
      $srtPath = $this->inputPath . DIRECTORY_SEPARATOR . $srtFile;

      if (!file_exists($srtPath)) {
        $this->log("⚠️ SKIP: SRT không tồn tại: $srtPath");
        continue;
      }

      $this->log("📝 Process SRT [$index]: $srtFile (offset: " . round($timeOffset, 3) . "s)");

      $content = @file_get_contents($srtPath);
      if ($content === false) {
        $this->log("⚠️ SKIP: Không đọc được SRT: $srtFile");
        continue;
      }

      $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

      $subtitles = $this->parseSRT($content);
      $this->log("  ✓ Found " . count($subtitles) . " subtitles");

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
            $this->log("  ⏱️ Offset: +" . round($duration, 3) . "s = " . round($timeOffset, 3) . "s");
          }
        }
      }
    }

    $suffix = '';
    if ($lang === 'en') {
      $suffix = '_en';
    } elseif ($lang === 'vi') {
      $suffix = '_vi';
    }

    $outputSRT = $this->outputPath . DIRECTORY_SEPARATOR . $outputName . $suffix . '.srt';

    $bom = "\xEF\xBB\xBF";
    if (!@file_put_contents($outputSRT, $bom . trim($mergedContent))) {
      throw new Exception("Không thể ghi file SRT: $outputSRT");
    }

    $this->log("✅ SRT output: $outputSRT (" . ($subtitleCounter - 1) . " subtitles)");
    $this->log("=== KẾT THÚC GỘP SRT ($lang) ===\n");

    return $outputSRT;
  }

  private function parseSRT($content)
  {
    $subtitles = [];
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $content = trim($content);
    $blocks = preg_split('/\n\s*\n/', $content);

    foreach ($blocks as $block) {
      $block = trim($block);
      if (empty($block)) continue;

      $lines = explode("\n", $block);
      if (count($lines) < 3) continue;

      $timelineLine = isset($lines[1]) ? $lines[1] : '';

      if (preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $timelineLine, $matches)) {
        $start = $matches[1];
        $end = $matches[2];
        $text = implode("\n", array_slice($lines, 2));
        $text = trim($text);

        if (!empty($text)) {
          $subtitles[] = [
            'start' => $start,
            'end' => $end,
            'text' => $text
          ];
        }
      }
    }

    return $subtitles;
  }

  private function addTimeOffset($timestamp, $offsetSeconds)
  {
    if (preg_match('/(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $timestamp, $matches)) {
      $hours = intval($matches[1]);
      $minutes = intval($matches[2]);
      $seconds = intval($matches[3]);
      $milliseconds = intval($matches[4]);

      $totalMs = ($hours * 3600 + $minutes * 60 + $seconds) * 1000 + $milliseconds;
      $totalMs += round($offsetSeconds * 1000);

      if ($totalMs < 0) $totalMs = 0;

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
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
      $bytes /= 1024;
      $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
  }
}

// ===== FIX #11: Xử lý request với error handling tốt hơn =====
try {
  $input = json_decode(file_get_contents('php://input'), true);

  if (!$input || !isset($input['action'])) {
    throw new Exception('Invalid request');
  }

  $action = $input['action'];
  $inputPath = $input['inputPath'] ?? '';
  $outputPath = $input['outputPath'] ?? '';
  $outputName = $input['outputName'] ?? 'merged_output';

  if (in_array($action, ['scan', 'merge_all_srt', 'merge_video']) && (empty($inputPath) || empty($outputPath))) {
    throw new Exception('Input path và output path không được để trống');
  }

  $merger = new VideoMerger($inputPath, $outputPath);

  switch ($action) {
    case 'scan':
      $files = $merger->scanFiles();
      echo json_encode([
        'success' => true,
        'files' => $files,
        'srt_info' => $files['srt_info'],
        'skipped' => $files['skipped'] ?? [],
        'processId' => uniqid('proc_', true)
      ]);
      break;

    case 'merge_all_srt':
      $srtFiles = $input['srt_files'] ?? [];
      $videos = $input['videos'] ?? [];

      if (empty($srtFiles)) {
        throw new Exception('Không có file SRT để gộp');
      }

      $mergedCount = $merger->mergeAllSRT($srtFiles, $videos, $outputName);
      echo json_encode([
        'success' => true,
        'merged_count' => $mergedCount
      ]);
      break;

    case 'merge_video':
      $videos = $input['videos'] ?? [];
      if (empty($videos)) {
        throw new Exception('Không có video để gộp');
      }
      $outputFile = $merger->mergeVideos($videos, $outputName);
      echo json_encode([
        'success' => true,
        'output' => $outputFile
      ]);
      break;

    case 'get_progress':
      $progress = $merger->getProgress();
      echo json_encode([
        'success' => true,
        'progress' => $progress ? $progress['progress'] : 0,
        'status' => $progress ? ($progress['status'] ?? 'unknown') : 'not_started'
      ]);
      break;

    case 'stop_process':
      $merger->stopCurrentProcess();
      echo json_encode([
        'success' => true,
        'message' => 'Process stopped'
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
    'trace' => $e->getTraceAsString()
  ]);
}
