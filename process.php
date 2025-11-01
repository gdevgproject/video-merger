<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(0);

// Đường dẫn FFmpeg
define('FFMPEG_PATH', 'C:\ooxmind\bin\ffmpeg\bin\ffmpeg.exe');

class VideoMerger
{
  private $inputPath;
  private $outputPath;

  public function __construct($inputPath, $outputPath)
  {
    $this->inputPath = rtrim($inputPath, '\\/');
    $this->outputPath = rtrim($outputPath, '\\/');
  }

  /**
   * Quét và phân loại files
   */
  public function scanFiles()
  {
    if (!is_dir($this->inputPath)) {
      throw new Exception("Thư mục input không tồn tại: {$this->inputPath}");
    }

    if (!is_dir($this->outputPath)) {
      if (!mkdir($this->outputPath, 0777, true)) {
        throw new Exception("Không thể tạo thư mục output: {$this->outputPath}");
      }
    }

    $files = scandir($this->inputPath);
    $videos = [];
    $srt_en = [];
    $srt_vi = [];

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      $filePath = $this->inputPath . DIRECTORY_SEPARATOR . $file;
      if (!is_file($filePath)) continue;

      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
      $nameWithoutExt = pathinfo($file, PATHINFO_FILENAME);

      // Kiểm tra video MP4
      if ($ext === 'mp4') {
        // Lấy số thứ tự từ đầu tên file
        if (preg_match('/^(\d+)/', $file, $matches)) {
          $order = intval($matches[1]);
          $videos[$order] = $file;
        }
      }
      // Kiểm tra SRT
      elseif ($ext === 'srt') {
        if (preg_match('/_en$/i', $nameWithoutExt)) {
          // SRT tiếng Anh
          $baseNameWithoutLang = preg_replace('/_en$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_en[$order] = $file;
          }
        } elseif (preg_match('/_vi$/i', $nameWithoutExt)) {
          // SRT tiếng Việt
          $baseNameWithoutLang = preg_replace('/_vi$/i', '', $nameWithoutExt);
          if (preg_match('/^(\d+)/', $baseNameWithoutLang, $matches)) {
            $order = intval($matches[1]);
            $srt_vi[$order] = $file;
          }
        }
      }
    }

    // Sắp xếp theo số thứ tự
    ksort($videos);
    ksort($srt_en);
    ksort($srt_vi);

    return [
      'videos' => array_values($videos),
      'srt_en' => array_values($srt_en),
      'srt_vi' => array_values($srt_vi)
    ];
  }

  /**
   * Gộp video sử dụng FFmpeg
   */
  public function mergeVideos($videoFiles)
  {
    if (empty($videoFiles)) {
      throw new Exception("Không có video để gộp");
    }

    // Tạo file list cho FFmpeg
    $listFile = $this->outputPath . DIRECTORY_SEPARATOR . 'filelist.txt';
    $listContent = '';

    foreach ($videoFiles as $video) {
      $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $video;
      // Escape đường dẫn cho FFmpeg
      $escapedPath = str_replace('\\', '/', $videoPath);
      $listContent .= "file '" . addslashes($escapedPath) . "'\n";
    }

    file_put_contents($listFile, $listContent);

    // Đường dẫn output
    $outputVideo = $this->outputPath . DIRECTORY_SEPARATOR . 'merged_output.mp4';

    // Xóa file cũ nếu tồn tại
    if (file_exists($outputVideo)) {
      unlink($outputVideo);
    }

    // Lệnh FFmpeg để gộp video
    $command = sprintf(
      '"%s" -f concat -safe 0 -i "%s" -c copy "%s" 2>&1',
      FFMPEG_PATH,
      $listFile,
      $outputVideo
    );

    exec($command, $output, $returnCode);

    // Xóa file list tạm
    @unlink($listFile);

    if ($returnCode !== 0) {
      $errorMsg = implode("\n", $output);
      throw new Exception("Lỗi khi gộp video: " . $errorMsg);
    }

    if (!file_exists($outputVideo)) {
      throw new Exception("File video output không được tạo");
    }

    return $outputVideo;
  }

  /**
   * Lấy thời lượng video bằng FFmpeg
   */
  private function getVideoDuration($videoPath)
  {
    $command = sprintf(
      '"%s" -i "%s" 2>&1 | findstr "Duration"',
      FFMPEG_PATH,
      $videoPath
    );

    exec($command, $output);

    if (!empty($output)) {
      $line = $output[0];
      if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
        $hours = intval($matches[1]);
        $minutes = intval($matches[2]);
        $seconds = floatval($matches[3]);
        return $hours * 3600 + $minutes * 60 + $seconds;
      }
    }

    return 0;
  }

  /**
   * Gộp file SRT
   */
  public function mergeSRT($srtFiles, $videoFiles, $lang = 'en')
  {
    if (empty($srtFiles)) {
      throw new Exception("Không có file SRT để gộp");
    }

    $mergedContent = '';
    $subtitleCounter = 1;
    $timeOffset = 0;

    foreach ($srtFiles as $index => $srtFile) {
      $srtPath = $this->inputPath . DIRECTORY_SEPARATOR . $srtFile;

      if (!file_exists($srtPath)) {
        continue;
      }

      $content = file_get_contents($srtPath);

      // Parse SRT
      $subtitles = $this->parseSRT($content);

      foreach ($subtitles as $subtitle) {
        // Cộng offset vào thời gian
        $startTime = $this->addTimeOffset($subtitle['start'], $timeOffset);
        $endTime = $this->addTimeOffset($subtitle['end'], $timeOffset);

        $mergedContent .= $subtitleCounter . "\n";
        $mergedContent .= $startTime . ' --> ' . $endTime . "\n";
        $mergedContent .= $subtitle['text'] . "\n\n";

        $subtitleCounter++;
      }

      // Cập nhật offset cho file tiếp theo
      if (isset($videoFiles[$index])) {
        $videoPath = $this->inputPath . DIRECTORY_SEPARATOR . $videoFiles[$index];
        $duration = $this->getVideoDuration($videoPath);
        $timeOffset += $duration;
      }
    }

    // Lưu file SRT đã gộp
    $outputSRT = $this->outputPath . DIRECTORY_SEPARATOR . 'merged_output_' . $lang . '.srt';
    file_put_contents($outputSRT, trim($mergedContent));

    return $outputSRT;
  }

  /**
   * Parse nội dung SRT
   */
  private function parseSRT($content)
  {
    $subtitles = [];

    // Chuẩn hóa line ending
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    // Split thành các block
    $blocks = preg_split('/\n\s*\n/', trim($content));

    foreach ($blocks as $block) {
      $lines = explode("\n", trim($block));

      if (count($lines) < 3) continue;

      // Dòng đầu là số thứ tự (bỏ qua)
      // Dòng thứ 2 là timeline
      if (preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $lines[1], $matches)) {
        $start = $matches[1];
        $end = $matches[2];

        // Các dòng còn lại là text
        $text = implode("\n", array_slice($lines, 2));

        $subtitles[] = [
          'start' => $start,
          'end' => $end,
          'text' => $text
        ];
      }
    }

    return $subtitles;
  }

  /**
   * Cộng offset vào timestamp SRT
   */
  private function addTimeOffset($timestamp, $offsetSeconds)
  {
    // Parse timestamp: 00:00:00,000
    if (preg_match('/(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $timestamp, $matches)) {
      $hours = intval($matches[1]);
      $minutes = intval($matches[2]);
      $seconds = intval($matches[3]);
      $milliseconds = intval($matches[4]);

      // Chuyển thành tổng milliseconds
      $totalMs = ($hours * 3600 + $minutes * 60 + $seconds) * 1000 + $milliseconds;

      // Cộng offset
      $totalMs += $offsetSeconds * 1000;

      // Chuyển lại thành format SRT
      $ms = $totalMs % 1000;
      $totalSeconds = floor($totalMs / 1000);
      $s = $totalSeconds % 60;
      $m = floor($totalSeconds / 60) % 60;
      $h = floor($totalSeconds / 3600);

      return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    return $timestamp;
  }
}

// Xử lý request
try {
  $input = json_decode(file_get_contents('php://input'), true);

  if (!$input || !isset($input['action'])) {
    throw new Exception('Invalid request');
  }

  $action = $input['action'];
  $inputPath = $input['inputPath'] ?? '';
  $outputPath = $input['outputPath'] ?? '';

  $merger = new VideoMerger($inputPath, $outputPath);

  switch ($action) {
    case 'scan':
      $files = $merger->scanFiles();
      echo json_encode([
        'success' => true,
        'files' => $files
      ]);
      break;

    case 'merge_video':
      $videos = $input['videos'] ?? [];
      $outputFile = $merger->mergeVideos($videos);
      echo json_encode([
        'success' => true,
        'output' => $outputFile
      ]);
      break;

    case 'merge_srt':
      $srtFiles = $input['srtFiles'] ?? [];
      $videos = $input['videos'] ?? [];
      $lang = $input['lang'] ?? 'en';
      $outputFile = $merger->mergeSRT($srtFiles, $videos, $lang);
      echo json_encode([
        'success' => true,
        'output' => $outputFile
      ]);
      break;

    default:
      throw new Exception('Unknown action');
  }
} catch (Exception $e) {
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}
