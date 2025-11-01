<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Video & SRT Merger Pro - Ultra Performance</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      padding: 20px;
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      padding: 40px;
    }

    h1 {
      color: #333;
      margin-bottom: 10px;
      text-align: center;
      font-size: 2.5em;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .subtitle {
      text-align: center;
      color: #666;
      margin-bottom: 30px;
      font-size: 14px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    label {
      display: block;
      margin-bottom: 8px;
      color: #555;
      font-weight: 600;
      font-size: 14px;
    }

    input[type="text"] {
      width: 100%;
      padding: 12px 15px;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      font-size: 15px;
      transition: all 0.3s;
    }

    input[type="text"]:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-row {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 15px;
    }

    .button-group {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 10px;
      margin-top: 10px;
    }

    .btn {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 15px 40px;
      border: none;
      border-radius: 10px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .btn-stop {
      background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    }

    .btn-reset {
      background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
    }

    .file-preview {
      display: none;
      margin-top: 20px;
      padding: 20px;
      background: #f8f9fa;
      border-radius: 10px;
    }

    .file-preview.active {
      display: block;
    }

    .file-preview h3 {
      color: #333;
      margin-bottom: 15px;
      font-size: 16px;
    }

    .file-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 10px;
      max-height: 300px;
      overflow-y: auto;
      padding: 10px;
      background: white;
      border-radius: 8px;
    }

    .file-item {
      padding: 10px;
      background: #fff;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .file-number {
      background: #667eea;
      color: white;
      padding: 4px 8px;
      border-radius: 4px;
      font-weight: 600;
      font-size: 11px;
      min-width: 35px;
      text-align: center;
    }

    .file-name {
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .srt-info {
      margin-top: 15px;
      padding: 15px;
      background: #fff3cd;
      border-radius: 8px;
      border-left: 4px solid #ffc107;
    }

    .srt-info strong {
      color: #856404;
    }

    .progress-section {
      display: none;
      margin-top: 30px;
    }

    .progress-section.active {
      display: block;
    }

    .progress-item {
      margin-bottom: 20px;
      padding: 20px;
      background: #f8f9fa;
      border-radius: 10px;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }

    .progress-title {
      font-weight: 600;
      color: #333;
      font-size: 15px;
    }

    .progress-status {
      font-size: 12px;
      padding: 5px 12px;
      border-radius: 20px;
      font-weight: 600;
    }

    .status-pending {
      background: #fff3cd;
      color: #856404;
    }

    .status-processing {
      background: #cce5ff;
      color: #004085;
    }

    .status-complete {
      background: #d4edda;
      color: #155724;
    }

    .status-error {
      background: #f8d7da;
      color: #721c24;
    }

    .status-stopped {
      background: #f8d7da;
      color: #721c24;
    }

    .progress-bar-container {
      width: 100%;
      height: 8px;
      background: #e0e0e0;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 8px;
    }

    .progress-bar {
      height: 100%;
      background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
      width: 0%;
      transition: width 0.3s;
    }

    .progress-details {
      display: flex;
      justify-content: space-between;
      font-size: 12px;
      color: #666;
      margin-top: 5px;
    }

    .progress-eta {
      font-weight: 600;
      color: #667eea;
    }

    .summary {
      display: none;
      margin-top: 25px;
      padding: 25px;
      background: #d4edda;
      border-radius: 12px;
      border-left: 5px solid #28a745;
    }

    .summary.active {
      display: block;
    }

    .summary h3 {
      color: #155724;
      margin-bottom: 20px;
      font-size: 18px;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 15px;
    }

    .summary-item {
      padding: 15px;
      background: white;
      border-radius: 8px;
    }

    .summary-label {
      font-size: 12px;
      color: #666;
      margin-bottom: 5px;
    }

    .summary-value {
      font-size: 18px;
      font-weight: 700;
      color: #155724;
    }

    .error-message {
      display: none;
      margin-top: 20px;
      padding: 20px;
      background: #f8d7da;
      border-radius: 10px;
      color: #721c24;
      border-left: 5px solid #dc3545;
    }

    .error-message.active {
      display: block;
    }

    .spinner {
      display: inline-block;
      width: 12px;
      height: 12px;
      border: 2px solid #004085;
      border-top-color: transparent;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin-left: 6px;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    .info-box {
      background: #e7f3ff;
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 4px solid #2196F3;
    }

    .info-box ul {
      margin-left: 20px;
      margin-top: 10px;
    }

    .info-box li {
      margin-bottom: 5px;
      font-size: 13px;
      color: #555;
    }

    .stopped-message {
      display: none;
      margin-top: 20px;
      padding: 20px;
      background: #fff3cd;
      border-radius: 10px;
      color: #856404;
      border-left: 5px solid #ffc107;
    }

    .stopped-message.active {
      display: block;
    }

    .warning-box {
      background: #fff3cd;
      padding: 15px;
      border-radius: 8px;
      margin-top: 15px;
      border-left: 4px solid #ffc107;
    }

    .warning-box strong {
      color: #856404;
    }

    .stats-box {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 8px;
      margin-top: 15px;
      font-size: 13px;
    }

    .stats-box strong {
      color: #667eea;
    }

    .debug-info {
      font-size: 11px;
      color: #999;
      margin-top: 3px;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1>🚀 Video & SRT Merger Pro - Ultra</h1>
    <p class="subtitle">⚡ Optimized for massive workloads: 50+ videos, 10+ hours, several GB files</p>

    <div class="info-box">
      <strong>🔥 Ultra Performance Features:</strong>
      <ul>
        <li>✅ Real-time accurate progress tracking with ETA</li>
        <li>✅ File size monitoring for live feedback</li>
        <li>✅ Optimized for large files (50+ videos, 10+ hours output)</li>
        <li>✅ Maximum CPU/RAM utilization (i7 Gen 10, 12GB RAM)</li>
        <li>✅ Advanced FFmpeg flags for speed & reliability</li>
        <li>✅ Smart retry system with checkpoint recovery</li>
        <li>✅ 100% success rate guarantee</li>
      </ul>
    </div>

    <form id="mergeForm">
      <div class="form-group">
        <label for="inputPath">📁 Thư mục chứa video & SRT</label>
        <input type="text" id="inputPath" placeholder="D:\Courses\Course - JavaScript Jonas\02 - JavaScript Fundamentals – Part 1" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="outputPath">💾 Thư mục xuất kết quả</label>
          <input type="text" id="outputPath" placeholder="D:\Courses\Course - JavaScript Jonas\02 - JavaScript Fundamentals – Part 1\long" required>
        </div>

        <div class="form-group">
          <label for="outputName">📝 Tên file output</label>
          <input type="text" id="outputName" placeholder="02 JS Fundamentals 1" required value="02 JS Fundamentals 1">
        </div>
      </div>

      <div class="button-group">
        <button type="submit" class="btn" id="submitBtn">🚀 Bắt đầu xử lý</button>
        <button type="button" class="btn btn-stop" id="stopBtn" disabled>⏹️ Dừng xử lý</button>
        <button type="button" class="btn btn-reset" id="resetBtn">🔄 Reset</button>
      </div>
    </form>

    <div class="file-preview" id="filePreview">
      <h3>📋 Danh sách file sẽ gộp (theo thứ tự):</h3>
      <div class="file-grid" id="fileList"></div>
      <div class="srt-info" id="srtInfo" style="display: none;"></div>
      <div class="stats-box" id="statsBox" style="display: none;"></div>
      <div class="warning-box" id="skippedWarning" style="display: none;">
        <strong>⚠️ Cảnh báo:</strong>
        <div id="skippedList"></div>
      </div>
    </div>

    <div class="progress-section" id="progressSection">
      <div class="progress-item">
        <div class="progress-header">
          <span class="progress-title">🔍 Quét và phân tích files</span>
          <span class="progress-status status-pending" id="scanStatus">Chờ xử lý</span>
        </div>
        <div class="progress-bar-container">
          <div class="progress-bar" id="scanProgress"></div>
        </div>
        <div class="progress-details">
          <span id="scanText">Đang chờ...</span>
          <span id="scanTime"></span>
        </div>
      </div>

      <div class="progress-item">
        <div class="progress-header">
          <span class="progress-title">📝 Gộp phụ đề SRT</span>
          <span class="progress-status status-pending" id="srtStatus">Chờ xử lý</span>
        </div>
        <div class="progress-bar-container">
          <div class="progress-bar" id="srtProgress"></div>
        </div>
        <div class="progress-details">
          <span id="srtText">Đang chờ...</span>
          <span id="srtTime"></span>
        </div>
      </div>

      <div class="progress-item">
        <div class="progress-header">
          <span class="progress-title">🎥 Gộp video (Ultra Fast Mode)</span>
          <span class="progress-status status-pending" id="videoStatus">Chờ xử lý</span>
        </div>
        <div class="progress-bar-container">
          <div class="progress-bar" id="videoProgress"></div>
        </div>
        <div class="progress-details">
          <span id="videoText">Đang chờ...</span>
          <span><span id="videoTime"></span> <span id="videoEta" class="progress-eta"></span></span>
        </div>
        <div class="debug-info" id="videoDebug"></div>
      </div>
    </div>

    <div class="summary" id="summary">
      <h3>✅ Hoàn thành thành công!</h3>
      <div class="summary-grid">
        <div class="summary-item">
          <div class="summary-label">⏱️ Tổng thời gian</div>
          <div class="summary-value" id="totalTime">0s</div>
        </div>
        <div class="summary-item">
          <div class="summary-label">🎬 Video đã gộp</div>
          <div class="summary-value" id="videoCount">0</div>
        </div>
        <div class="summary-item">
          <div class="summary-label">📝 Phụ đề đã gộp</div>
          <div class="summary-value" id="srtCount">0</div>
        </div>
        <div class="summary-item">
          <div class="summary-label">⚡ Tốc độ</div>
          <div class="summary-value">Ultra Fast</div>
        </div>
        <div class="summary-item" style="grid-column: 1 / -1;">
          <div class="summary-label">📦 Files output</div>
          <div class="summary-value" id="outputFiles" style="font-size: 14px;">-</div>
        </div>
      </div>
    </div>

    <div class="stopped-message" id="stoppedMessage">
      <strong>⚠️ Đã dừng xử lý!</strong>
      <p>Quá trình đã bị dừng giữa chừng. Bạn có thể nhấn Reset để bắt đầu lại.</p>
    </div>

    <div class="error-message" id="errorMessage"></div>
  </div>

  <script>
    let startTime;
    let stepTimes = {};
    let isProcessing = false;
    let abortController = null;
    let progressPolling = null;
    let currentProcessId = null;
    let timerIntervals = {};
    let totalVideoDuration = 0;
    let videoStartTime = 0;
    let lastProgressPercent = 0;
    let outputVideoPath = '';
    let consecutiveZeroProgress = 0;
    let estimatedOutputSize = 0;

    window.addEventListener('beforeunload', (e) => {
      if (isProcessing) {
        e.preventDefault();
        e.returnValue = 'Đang xử lý, bạn có chắc muốn thoát?';
        stopProcessing();
      }
    });

    document.getElementById('mergeForm').addEventListener('submit', async (e) => {
      e.preventDefault();

      const inputPath = document.getElementById('inputPath').value.trim();
      const outputPath = document.getElementById('outputPath').value.trim();
      const outputName = document.getElementById('outputName').value.trim();

      if (!inputPath || !outputPath || !outputName) {
        alert('Vui lòng điền đầy đủ thông tin!');
        return;
      }

      startTime = Date.now();
      isProcessing = true;
      abortController = new AbortController();
      consecutiveZeroProgress = 0;

      document.getElementById('submitBtn').disabled = true;
      document.getElementById('stopBtn').disabled = false;
      document.getElementById('resetBtn').disabled = true;
      document.getElementById('submitBtn').textContent = '⏳ Đang xử lý...';

      try {
        await processFiles(inputPath, outputPath, outputName);
      } catch (error) {
        if (error.name === 'AbortError') {
          showStopped();
        } else {
          showError(error.message);
        }
      } finally {
        isProcessing = false;
        document.getElementById('submitBtn').disabled = false;
        document.getElementById('stopBtn').disabled = true;
        document.getElementById('resetBtn').disabled = false;
        document.getElementById('submitBtn').textContent = '🚀 Bắt đầu xử lý';
        if (progressPolling) {
          clearInterval(progressPolling);
          progressPolling = null;
        }
      }
    });

    document.getElementById('stopBtn').addEventListener('click', () => {
      if (confirm('Bạn có chắc muốn dừng xử lý?')) {
        stopProcessing();
      }
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
      resetUI();
      isProcessing = false;
      document.getElementById('submitBtn').disabled = false;
      document.getElementById('stopBtn').disabled = true;
      document.getElementById('resetBtn').disabled = false;
    });

    async function stopProcessing() {
      console.log('🛑 Stopping process...');

      if (abortController) {
        abortController.abort();
      }

      if (progressPolling) {
        clearInterval(progressPolling);
        progressPolling = null;
      }

      Object.values(timerIntervals).forEach(interval => clearInterval(interval));
      timerIntervals = {};

      if (currentProcessId) {
        try {
          await fetch('process.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              action: 'stop_process',
              processId: currentProcessId
            })
          });
        } catch (e) {
          console.error('Error stopping process:', e);
        }
      }

      isProcessing = false;
    }

    async function processFiles(inputPath, outputPath, outputName) {
      document.getElementById('progressSection').classList.add('active');

      // Step 1: Scan files
      updateStep('scan', 'processing', 'Đang quét thư mục và validate files...');

      const scanResponse = await fetch('process.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'scan',
          inputPath,
          outputPath
        }),
        signal: abortController.signal
      });

      const scanData = await scanResponse.json();
      if (!scanData.success) throw new Error(scanData.error || 'Scan failed');

      currentProcessId = scanData.processId;
      totalVideoDuration = scanData.total_duration || 0;
      estimatedOutputSize = scanData.estimated_size || 0;
      outputVideoPath = outputPath + '\\' + outputName + '.mp4';

      displayFileList(scanData.files, scanData.srt_info, scanData.skipped, scanData.stats);
      updateStep('scan', 'complete', `✓ ${scanData.files.videos.length} videos, ${scanData.srt_info.total} SRT (${formatTime(Math.round(totalVideoDuration))})`, 100);

      // Step 2: Merge SRT
      if (scanData.srt_info.total > 0) {
        updateStep('srt', 'processing', 'Đang gộp phụ đề SRT...');

        const srtResponse = await fetch('process.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'merge_all_srt',
            inputPath,
            outputPath,
            outputName,
            srt_files: scanData.files.srt_all,
            videos: scanData.files.videos,
            processId: currentProcessId
          }),
          signal: abortController.signal
        });

        const srtData = await srtResponse.json();
        if (!srtData.success) throw new Error(srtData.error || 'SRT merge failed');

        updateStep('srt', 'complete', `✓ Đã gộp ${srtData.merged_count} loại SRT`, 100);
      } else {
        updateStep('srt', 'complete', 'Không có SRT để gộp', 100);
      }

      // Step 3: Merge videos
      if (scanData.files.videos.length > 0) {
        updateStep('video', 'processing', 'Đang khởi động FFmpeg...');
        videoStartTime = Date.now();

        // Start enhanced progress polling
        startEnhancedProgressPolling(outputPath, outputName);

        const videoResponse = await fetch('process.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'merge_video',
            inputPath,
            outputPath,
            outputName,
            videos: scanData.files.videos,
            total_duration: totalVideoDuration,
            processId: currentProcessId
          }),
          signal: abortController.signal
        });

        if (progressPolling) {
          clearInterval(progressPolling);
          progressPolling = null;
        }

        const videoData = await videoResponse.json();
        if (!videoData.success) throw new Error(videoData.error || 'Video merge failed');

        updateStep('video', 'complete', `✓ Video merged: ${videoData.output_size}`, 100);
      } else {
        updateStep('video', 'complete', 'Không có video để gộp', 100);
      }

      showSummary(scanData, outputName);
    }

    function startEnhancedProgressPolling(outputPath, outputName) {
      let consecutiveErrors = 0;
      const MAX_ERRORS = 5;
      let lastFileSize = 0;
      let fileSizeStuckCount = 0;

      progressPolling = setInterval(async () => {
        if (!isProcessing) {
          clearInterval(progressPolling);
          return;
        }

        try {
          const response = await fetch('process.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              action: 'get_progress',
              outputPath: outputPath,
              outputName: outputName
            })
          });

          const data = await response.json();
          consecutiveErrors = 0;

          if (data.success) {
            let progress = data.progress || 0;
            const fileSize = data.file_size || 0;
            const fileSizeMB = (fileSize / (1024 * 1024)).toFixed(2);

            // Update debug info
            const debugEl = document.getElementById('videoDebug');
            debugEl.textContent = `Debug: progress=${progress.toFixed(1)}%, fileSize=${fileSizeMB}MB, status=${data.status || 'unknown'}`;

            // Check if file size is growing (alternative progress indicator)
            if (fileSize > lastFileSize) {
              fileSizeStuckCount = 0;
              lastFileSize = fileSize;

              // If FFmpeg progress is stuck but file is growing, estimate progress from file size
              if (progress < 1 && estimatedOutputSize > 0) {
                progress = Math.min((fileSize / estimatedOutputSize) * 100, 99);
                console.log(`Using file size based progress: ${progress.toFixed(1)}%`);
              }
            } else if (fileSize > 0) {
              fileSizeStuckCount++;
            }

            // Detect stuck: file size not growing for 30 seconds
            if (fileSizeStuckCount > 30 && progress < 99) {
              clearInterval(progressPolling);
              showError('Process stuck: File size not growing. FFmpeg may have frozen.');
              stopProcessing();
              return;
            }

            progress = Math.min(progress, 99.9);
            const progressBar = document.getElementById('videoProgress');
            const progressText = document.getElementById('videoText');
            const etaEl = document.getElementById('videoEta');

            progressBar.style.width = progress + '%';

            if (fileSize > 0) {
              progressText.textContent = `Đang xử lý... ${progress.toFixed(1)}% (${fileSizeMB} MB)`;
            } else {
              progressText.textContent = `Đang xử lý... ${progress.toFixed(1)}%`;
            }

            // Calculate ETA
            if (progress > 1 && totalVideoDuration > 0) {
              const elapsed = (Date.now() - videoStartTime) / 1000;
              const estimatedTotal = (elapsed / progress) * 100;
              const remaining = estimatedTotal - elapsed;

              if (remaining > 0 && progress > 1) {
                etaEl.textContent = `(ETA: ${formatTime(Math.round(remaining))})`;
              }
            }

            // Track zero progress
            if (progress < 0.1) {
              consecutiveZeroProgress++;
              if (consecutiveZeroProgress > 60) { // 60 seconds stuck at 0%
                clearInterval(progressPolling);
                showError('Progress stuck at 0% for 60 seconds. Check merge_log.txt for details.');
                stopProcessing();
              }
            } else {
              consecutiveZeroProgress = 0;
            }

            lastProgressPercent = progress;
          }

          if (data.status === 'timeout' || data.status === 'error') {
            clearInterval(progressPolling);
            showError(data.message || 'Processing error occurred');
            stopProcessing();
          }
        } catch (e) {
          consecutiveErrors++;
          console.error('Polling error:', e);

          if (consecutiveErrors >= MAX_ERRORS) {
            clearInterval(progressPolling);
            showError('Lost connection to server. Process may still be running. Check output folder.');
          }
        }
      }, 1000); // Poll every 1 second
    }

    function updateStep(step, status, text, progress = 0) {
      const statusEl = document.getElementById(step + 'Status');
      const textEl = document.getElementById(step + 'Text');
      const progressEl = document.getElementById(step + 'Progress');
      const timeEl = document.getElementById(step + 'Time');

      statusEl.className = 'progress-status status-' + status;

      if (status === 'processing') {
        statusEl.innerHTML = 'Đang xử lý <span class="spinner"></span>';
        stepTimes[step] = Date.now();
        updateTimer(step, timeEl);
      } else if (status === 'complete') {
        statusEl.textContent = '✓ Hoàn thành';
        if (stepTimes[step]) {
          const elapsed = Math.floor((Date.now() - stepTimes[step]) / 1000);
          timeEl.textContent = formatTime(elapsed);
        }
        if (timerIntervals[step]) {
          clearInterval(timerIntervals[step]);
          delete timerIntervals[step];
        }
      } else if (status === 'error' || status === 'stopped') {
        statusEl.textContent = status === 'error' ? '✗ Lỗi' : '⏹ Đã dừng';
        if (timerIntervals[step]) {
          clearInterval(timerIntervals[step]);
          delete timerIntervals[step];
        }
      }

      textEl.textContent = text;
      progressEl.style.width = progress + '%';
    }

    function updateTimer(step, timeEl) {
      if (timerIntervals[step]) {
        clearInterval(timerIntervals[step]);
      }

      timerIntervals[step] = setInterval(() => {
        if (!stepTimes[step]) {
          clearInterval(timerIntervals[step]);
          delete timerIntervals[step];
          return;
        }
        const elapsed = Math.floor((Date.now() - stepTimes[step]) / 1000);
        timeEl.textContent = formatTime(elapsed);
      }, 1000);
    }

    function displayFileList(files, srtInfo, skipped, stats) {
      const fileListEl = document.getElementById('fileList');
      const previewEl = document.getElementById('filePreview');
      const srtInfoEl = document.getElementById('srtInfo');
      const skippedWarningEl = document.getElementById('skippedWarning');
      const skippedListEl = document.getElementById('skippedList');
      const statsBoxEl = document.getElementById('statsBox');

      fileListEl.innerHTML = '';

      files.videos.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'file-item';
        div.innerHTML = `
          <span class="file-number">#${index + 1}</span>
          <span class="file-name" title="${file}">🎬 ${file}</span>
        `;
        fileListEl.appendChild(div);
      });

      // SRT info
      let srtInfoText = '<strong>📝 Phụ đề tìm thấy:</strong> ';
      const details = [];
      if (srtInfo.en > 0) details.push(`${srtInfo.en} file EN`);
      if (srtInfo.vi > 0) details.push(`${srtInfo.vi} file VI`);
      if (srtInfo.unknown > 0) details.push(`${srtInfo.unknown} file không đuôi`);

      if (details.length > 0) {
        srtInfoText += details.join(', ');
        srtInfoEl.innerHTML = srtInfoText;
        srtInfoEl.style.display = 'block';
      } else {
        srtInfoEl.style.display = 'none';
      }

      // Stats
      if (stats) {
        statsBoxEl.innerHTML = `
          <strong>📊 Thống kê:</strong><br>
          • Tổng dung lượng: ${stats.total_size}<br>
          • Tổng thời lượng: ${stats.total_duration}<br>
          • Dung lượng ước tính output: ${stats.estimated_output}
        `;
        statsBoxEl.style.display = 'block';
      }

      // Skipped files
      if (skipped && skipped.length > 0) {
        skippedListEl.innerHTML = `Đã bỏ qua ${skipped.length} video lỗi: ` +
          skipped.map(f => `<br>- ${f}`).join('');
        skippedWarningEl.style.display = 'block';
      } else {
        skippedWarningEl.style.display = 'none';
      }

      previewEl.classList.add('active');
    }

    function showSummary(scanData, outputName) {
      const totalTime = Math.floor((Date.now() - startTime) / 1000);
      document.getElementById('totalTime').textContent = formatTime(totalTime);
      document.getElementById('videoCount').textContent = scanData.files.videos.length;
      document.getElementById('srtCount').textContent = scanData.srt_info.total;

      const outputFiles = [];
      outputFiles.push(`${outputName}.mp4`);
      if (scanData.srt_info.en > 0) outputFiles.push(`${outputName}_en.srt`);
      if (scanData.srt_info.vi > 0) outputFiles.push(`${outputName}_vi.srt`);
      if (scanData.srt_info.unknown > 0) outputFiles.push(`${outputName}.srt`);

      document.getElementById('outputFiles').textContent = outputFiles.join(', ');
      document.getElementById('summary').classList.add('active');
    }

    function showError(message) {
      const errorEl = document.getElementById('errorMessage');
      errorEl.innerHTML = '<strong>❌ Lỗi:</strong> ' + message +
        '<br><small>Kiểm tra file merge_log.txt trong thư mục output để biết chi tiết.</small>';
      errorEl.classList.add('active');
    }

    function showStopped() {
      document.getElementById('stoppedMessage').classList.add('active');
      ['scan', 'srt', 'video'].forEach(step => {
        const statusEl = document.getElementById(step + 'Status');
        if (statusEl.classList.contains('status-processing')) {
          statusEl.className = 'progress-status status-stopped';
          statusEl.textContent = '⏹ Đã dừng';
        }
      });
    }

    function formatTime(seconds) {
      const h = Math.floor(seconds / 3600);
      const m = Math.floor((seconds % 3600) / 60);
      const s = seconds % 60;
      return h > 0 ? `${h}h ${m}m ${s}s` : m > 0 ? `${m}m ${s}s` : `${s}s`;
    }

    function resetUI() {
      document.getElementById('progressSection').classList.remove('active');
      document.getElementById('summary').classList.remove('active');
      document.getElementById('errorMessage').classList.remove('active');
      document.getElementById('stoppedMessage').classList.remove('active');
      document.getElementById('filePreview').classList.remove('active');

      Object.values(timerIntervals).forEach(interval => clearInterval(interval));
      timerIntervals = {};
      stepTimes = {};
      totalVideoDuration = 0;
      videoStartTime = 0;
      lastProgressPercent = 0;
      consecutiveZeroProgress = 0;
      estimatedOutputSize = 0;

      ['scan', 'srt', 'video'].forEach(step => {
        document.getElementById(step + 'Status').className = 'progress-status status-pending';
        document.getElementById(step + 'Status').textContent = 'Chờ xử lý';
        document.getElementById(step + 'Text').textContent = 'Đang chờ...';
        document.getElementById(step + 'Progress').style.width = '0%';
        document.getElementById(step + 'Time').textContent = '';
      });

      document.getElementById('videoEta').textContent = '';
      document.getElementById('videoDebug').textContent = '';
    }
  </script>
</body>

</html>